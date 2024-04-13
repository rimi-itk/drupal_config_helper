<?php

namespace Drupal\config_helper\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Exception\RuntimeException;

/**
 * A Drush commandfile.
 */
final class ConfigHelperCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a ConfigHelperCommands object.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * List config names.
   */
  #[CLI\Command(name: 'config_helper:list')]
  #[CLI\Argument(name: 'patterns', description: 'Config names or patterns.')]
  #[CLI\Usage(name: 'drush config_helper:list', description: 'List all config names.')]
  #[CLI\Usage(name: 'drush config_helper:list "views.view.*"', description: 'List all views.')]
  public function list(
    array $patterns,
  ): void {
    $names = $this->getConfigNames($patterns);

    foreach ($names as $name) {
      $this->io()->writeln($name);
    }
  }

  /**
   * Enforce module dependency in config.
   *
   * @see https://www.drupal.org/node/2087879#s-example:~:text=The%20dependencies%20and%20enforced%20keys%20ensure,removed%20when%20the%20module%20is%20uninstalled
   */
  #[CLI\Command(name: 'config_helper:enforce-module-dependency')]
  #[CLI\Argument(name: 'module', description: 'The module name.')]
  #[CLI\Argument(name: 'configNames', description: 'The config names.')]
  #[CLI\Usage(name: 'drush config_helper:enforce-module-dependency my_module "*.my_module.*"', description: 'Enforce dependency on my_module')]
  #[CLI\Usage(name: 'drush config_helper:enforce-module-dependency my_module', description: 'Shorthand for `drush config_helper:enforce-module-dependency my_module "*.my_module.*"`')]
  public function enforceModuleDependencies(
    string $module,
    array $configNames,
  ): void {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new RuntimeException(sprintf('Invalid module: %s', $module));
    }

    $configNames = empty($configNames)
      ? $this->getModuleConfigNames($module)
      : $this->getConfigNames($configNames);

    $question = sprintf("Enforce dependency on module %s in\n * %s\n ?", $module, implode("\n * ", $configNames));
    if ($this->io()->confirm($question)) {
      foreach ($configNames as $name) {
        $this->io()->writeln(sprintf('Config name: %s', $name));

        $config = $this->configFactory->getEditable($name);

        // Config::merge does merge correctly so we do it ourselves.
        $dependencies = $config->get('dependencies') ?? [];
        $dependencies['enforced']['module'][] = $module;
        $dependencies['enforced']['module'] = array_unique($dependencies['enforced']['module']);
        sort($dependencies['enforced']['module']);

        $config->set('dependencies', $dependencies);

        $config->save();
      }
    }
  }

  /**
   * Write config info config folder in module.
   */
  #[CLI\Command(name: 'config_helper:write-module-config')]
  #[CLI\Argument(name: 'module', description: 'The module name.')]
  #[CLI\Argument(name: 'configNames', description: 'The config names.')]
  #[CLI\Option(name: 'optional', description: 'Create as optional config.')]
  #[CLI\Option(name: 'enforced', description: 'Move only config with enforced dependency on the module.')]
  #[CLI\Option(name: 'dry-run', description: 'Dry run.')]
  #[CLI\Usage(name: 'drush config_helper:write-module-config my_module', description: '')]
  #[CLI\Usage(name: 'drush config_helper:write-module-config --source=sites/all/config my_module', description: '')]
  public function writeModuleConfig(
    string $module,
    array $configNames,
    array $options = [
      'optional' => FALSE,
      'enforced' => FALSE,
    ]
  ): void {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new RuntimeException(sprintf('Invalid module: %s', $module));
    }

    if ($options['enforced']) {
      $configNames = $this->getEnforcedModuleConfigNames($module);
    }
    else {
      $configNames = empty($configNames)
        ? $this->getModuleConfigNames($module)
        : $this->getConfigNames($configNames);
    }

    $modulePath = $this->moduleHandler->getModule($module)->getPath();
    $moduleConfigPath = $modulePath . '/config/' . ($options['optional'] ? 'optional' : 'install');

    $question = sprintf("Write config\n * %s\n into %s?", implode("\n * ", $configNames), $moduleConfigPath);
    if ($this->io()->confirm($question)) {
      if (!is_dir($moduleConfigPath)) {
        $this->fileSystem->mkdir($moduleConfigPath, 0755, TRUE);
      }

      foreach ($configNames as $name) {
        $this->io()->writeln($name);

        $filename = $name . '.yml';
        $destination = $moduleConfigPath . '/' . $filename;
        $config = $this->configFactory->get($name)->getRawData();
        // @see https://www.drupal.org/node/2087879#s-exporting-configuration
        unset($config['uuid'], $config['_core']);

        file_put_contents($destination, Yaml::encode($config));
      }
    }
  }

  /**
   * Rename config.
   */
  #[CLI\Command(name: 'config_helper:rename')]
  #[CLI\Argument(name: 'search', description: 'The value to search for.')]
  #[CLI\Argument(name: 'replace', description: 'The replacement value.')]
  #[CLI\Option(name: 'regex', description: 'Use regex search and replace.')]
  #[CLI\Option(name: 'dry-run', description: 'Dry run.')]
  #[CLI\Usage(name: 'drush config_helper:rename porject project', description: 'Fix typo in config.')]
  #[CLI\Usage(name: "drush config_helper:rename 'field_(.+)' '\1' --regex", description: 'Remove superfluous prefix from field machine names.')]
  public function rename(
    string $search,
    string $replace,
    array $options = [
      'regex' => FALSE,
      'dry-run' => FALSE,
    ]
  ): void {
    if (!$options['regex']) {
      $search = '/' . preg_quote($search, '/') . '/';
    }

    $this->io()->writeln(sprintf('Replacing %s with %s in config', $search, $replace));

    $names = $this->configFactory->listAll();
    foreach ($names as $name) {
      $config = $this->configFactory->getEditable($name);
      $data = $this->replaceKeysAndValues($search, $replace, $config->get());
      $config->setData($data);
      $config->save();

      if (preg_match($search, $name)) {
        $newName = preg_replace($search, $replace, $name);
        $this->io()->writeln(sprintf('%s -> %s', $name, $newName));
        if (!$options['dry-run']) {
          $this->configFactory->rename($name, $newName);
        }
      }
    }
  }

  /**
   * Replace in keys and values.
   *
   * @see https://stackoverflow.com/a/29619470
   */
  private function replaceKeysAndValues(
    string $search,
    string $replace,
    array $input
  ): array {
    $return = [];
    foreach ($input as $key => $value) {
      if (preg_match($search, $key)) {
        $key = preg_replace($search, $replace, $key);
      }

      if (is_array($value)) {
        $value = $this->replaceKeysAndValues($search, $replace, $value);
      }
      elseif (is_string($value)) {
        $value = preg_replace($search, $replace, $value);
      }

      $return[$key] = $value;
    }

    return $return;
  }

  /**
   * Get config names matching patterns.
   */
  private function getConfigNames(array $patterns): array {
    $names = $this->configFactory->listAll();
    if (empty($patterns)) {
      return $names;
    }

    $configNames = [];
    foreach ($patterns as $pattern) {
      $chunk = array_filter(
        $names,
        // phpcs:disable Drupal.Functions.DiscouragedFunctions.Discouraged -- https://www.php.net/manual/en/function.fnmatch.php#refsect1-function.fnmatch-notes
        static fn ($name) => fnmatch($pattern, $name),
      );
      if (empty($chunk)) {
        throw new RuntimeException(sprintf('No config matches %s', var_export($pattern, TRUE)));
      }
      $configNames[] = $chunk;
    }

    $configNames = array_unique(array_merge(...$configNames));
    sort($configNames);

    return $configNames;
  }

  /**
   * Get config names for a module.
   */
  private function getModuleConfigNames(string $module): array {
    return $this->getConfigNames(['*.' . $module . '.*']);
  }

  /**
   * Get names of config that has an enforced dependency on a module.
   */
  private function getEnforcedModuleConfigNames(string $module): array {
    $configNames = array_values(
      array_filter(
        $this->configFactory->listAll(),
        function ($name) use ($module) {
          $config = $this->configFactory->get($name)->getRawData();
          $list = $config['dependencies']['enforced']['module'] ?? NULL;

          return is_array($list) && in_array($module, $list, TRUE);
        }
      )
    );

    if (empty($configNames)) {
      throw new RuntimeException(sprintf('No config has an enforced dependency on "%s".', $module));
    }

    return $configNames;
  }

}
