<?php

namespace Drupal\config_helper\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
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
   * Rename config.
   */
  #[CLI\Command(name: 'config_helper:rename')]
  #[CLI\Argument(name: 'search', description: 'The value to search for.')]
  #[CLI\Argument(name: 'replace', description: 'The replacement value.')]
  #[CLI\Option(name: 'regex', description: 'Use regex search and replace.')]
  #[CLI\Option(name: 'dry-run', description: 'Dry run.')]
  #[CLI\Usage(name: 'drush config:rename porject project', description: 'Fix typo in config.')]
  #[CLI\Usage(name: "drush config:rename 'field_(.+)' '\1' --regex", description: 'Remove superfluous prefix from field machine names.')]
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
   * Enforce module dependencies in config.
   *
   * @see https://www.drupal.org/node/2087879#s-example:~:text=The%20dependencies%20and%20enforced%20keys%20ensure,removed%20when%20the%20module%20is%20uninstalled
   */
  #[CLI\Command(name: 'config_helper:enforce-module-dependency')]
  #[CLI\Argument(name: 'module', description: 'The module name.')]
  #[CLI\Argument(name: 'configNames', description: 'The config names.')]
  #[CLI\Option(name: 'remove-uuid', description: 'Remove uuid and _core from config.')]
  #[CLI\Option(name: 'dry-run', description: 'Dry run.')]
  #[CLI\Usage(name: 'drush config:enforce-module-dependency my_module another_module', description: '')]
  public function enforceModuleDependencies(
    string $module,
    array $configNames,
    array $options = [
      'remove-uuid' => FALSE,
      'dry-run' => FALSE,
    ]
  ): void {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new RuntimeException(sprintf('Invalid module: %s', $module));
    }

    if (empty($configNames)) {
      $configNames = $this->getModuleConfigNames($module);
    }

    foreach ($configNames as $name) {
      $this->io()->writeln(sprintf('Config name: %s', $name));
      if (!$this->configExists($name)) {
        throw new RuntimeException(sprintf('Invalid config name: %s', $name));
      }

      if (!$options['dry-run']) {
        $config = $this->configFactory->getEditable($name);

        // Config::merge does merge correctly so we do it ourselves.
        $dependencies = $config->get('dependencies') ?? [];
        $dependencies['enforced']['module'][] = $module;
        $dependencies['enforced']['module'] = array_unique($dependencies['enforced']['module']);
        sort($dependencies['enforced']['module']);

        $config->set('dependencies', $dependencies);

        if ($options['remove-uuid']) {
          $config->clear('uuid');
          $config->clear('_core');
        }

        $config->save();
      }
    }
  }

  /**
   * Move config info config/install folder in a module.
   */
  #[CLI\Command(name: 'config_helper:move-module-config')]
  #[CLI\Argument(name: 'module', description: 'The module name.')]
  #[CLI\Argument(name: 'configNames', description: 'The config names.')]
  #[CLI\Option(name: 'source', description: 'Config source directory.')]
  #[CLI\Option(name: 'enforced', description: 'Move only config with enforced dependency on the module.')]
  #[CLI\Option(name: 'dry-run', description: 'Dry run.')]
  #[CLI\Usage(name: 'drush config:move-module-config my_module', description: '')]
  #[CLI\Usage(name: 'drush config:move-module-config --source=sites/all/config my_module', description: '')]
  public function moveModuleConfig(
    string $module,
    array $configNames,
    array $options = [
      'source' => NULL,
      'enforced' => FALSE,
      'dry-run' => FALSE,
    ]
  ): void {
    if (!$this->moduleHandler->moduleExists($module)) {
      throw new RuntimeException(sprintf('Invalid module: %s', $module));
    }

    if ($options['enforced']) {
      $configNames = $this->getEnforcedModuleConfigNames($module);
    } else {
      if (empty($configNames)) {
        $configNames = $this->getModuleConfigNames($module);
      }
    }

    $source = $options['source'] ?? Settings::get('config_sync_directory');
    if (NULL === $source) {
      throw new RuntimeException('Config source not defined');
    }
    $configPath = DRUPAL_ROOT . '/' . $source;
    if (!is_dir($configPath)) {
      throw new RuntimeException(sprintf('Config directory %s does not exist',
        $configPath));
    }
    $modulePath = $this->moduleHandler->getModule($module)->getPath();
    $moduleConfigPath = $modulePath . '/config/install';
    if (!$options['dry-run']) {
      if (!is_dir($moduleConfigPath)) {
        $this->fileSystem->mkdir($moduleConfigPath, 0755, TRUE);
      }
    }

    foreach ($configNames as $name) {
      $this->io()->writeln($name);

      $filename = $name . '.yml';
      $source = $configPath . '/' . $filename;
      $destination = $moduleConfigPath . '/' . $filename;

      if (!file_exists($source)) {
        $this->output()->writeln(sprintf('Source file %s does not exist',
          $source));
        continue;
      }

      if (!$options['dry-run']) {
        $this->fileSystem->move($source, $destination,
          FileSystemInterface::EXISTS_REPLACE);
      }
      $this->io()->writeln(sprintf('%s -> %s', $source, $destination));
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

  private function configExists(string $name): bool {
    return in_array($name, $this->configFactory->listAll(), TRUE);
  }

  private function getModuleConfigNames(string $module): array {
  // Find config names containing the module name.
  return array_values(array_filter($this->configFactory->listAll(),
    static function($name) use ($module) {
      return preg_match('/[.]' . preg_quote($module, '/') . '(?:$|[.])/',
        $name);
    }));
  }

  private function getEnforcedModuleConfigNames(string $module): array {
    $configNames = [];
    $names = $this->configFactory->listAll();
    foreach ($names as $name) {
      $config = $this->configFactory->get($name)->getRawData();
      if (isset($config['dependencies']['enforced']['module'])
        && in_array($module, $config['dependencies']['enforced']['module'], TRUE)) {
        $configNames[] = $name;
      }
    }

    return $configNames;
  }

}
