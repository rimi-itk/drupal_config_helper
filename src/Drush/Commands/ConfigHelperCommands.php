<?php

namespace Drupal\config_helper\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drush commandfile.
 */
final class ConfigHelperCommands extends DrushCommands {

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
      $container->get('config.factory'),
      $container->get('file_system'),
    );
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
  public function rename(string $search, string $replace, array $options = [
    'regex' => FALSE,
    'dry-run' => FALSE,
  ]): void {
    if (!$options['regex']) {
      $search = '/' . preg_quote($search, '/') . '/';
    }

    $names = $this->configFactory->listAll();

    foreach ($names as $name) {
      $config = $this->configFactory->getEditable($name);
      $data = $this->replaceKeysAndValues($search, $replace, $config->get());
      $config->setData($data);
      $config->save();

      if (preg_match($search, $name)) {
        $newName = preg_replace($search, $replace, $name);
        $this->output()->writeln(sprintf('%s -> %s', $name, $newName));
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
  #[CLI\Argument(name: 'modules', description: 'The module names.')]
  #[CLI\Option(name: 'remove-uuid', description: 'Remove uuid and _core from config.')]
  #[CLI\Usage(name: 'drush config:enforce-module-dependency my_module another_module', description: '')]
  public function enforceModuleDependencies(array $modules, array $options = [
    'remove-uuid' => FALSE,
  ]): void {
    foreach ($modules as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        throw new RuntimeException(sprintf('Invalid module: %s', $module));
      }

      $this->output()->writeln($module);

      $names = array_values(array_filter($this->configFactory->listAll(), static function ($name) use ($module) {
        return preg_match('/[._]' . preg_quote($module, '/') . '/', $name);
      }));

      foreach ($names as $name) {
        $this->output()->writeln($name);
        $config = $this->configFactory->getEditable($name);

        // Config::merge does merge correctly so we do it ourselves.
        $dependencies = $config->get('dependencies') ?? [];

        if (!isset($dependencies['module'])) {
          $dependencies['module'] = [];
        }
        if (!isset($dependencies['enforced'])) {
          $dependencies['enforced'] = [];
        }
        if (!isset($dependencies['enforced']['module'])) {
          $dependencies['enforced']['module'] = [];
        }

        $dependencies['module'][] = $module;
        $dependencies['enforced']['module'][] = $module;

        $dependencies['module'] = array_unique($dependencies['module']);
        sort($dependencies['module']);

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
  #[CLI\Argument(name: 'modules', description: 'The module names.')]
  #[CLI\Option(name: 'source', description: 'Config source directory.')]
  #[CLI\Usage(name: 'drush config:move-module-config my_module another_module', description: '')]
  #[CLI\Usage(name: 'drush config:move-module-config --source=sites/all/config my_module another_module', description: '')]
  public function moveModuleConfig(array $modules, array $options = [
    'source' => NULL,
  ]): void {
    foreach ($modules as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        throw new RuntimeException(sprintf('Invalid module: %s', $module));
      }

      $this->output()->writeln($module);

      $names = array_values(array_filter($this->configFactory->listAll(), static function ($name) use ($module) {
        return preg_match('/[._]' . preg_quote($module, '/') . '/', $name);
      }));

      $source = $options['source'] ?? Settings::get('config_sync_directory');
      if (NULL === $source) {
        throw new RuntimeException('Config source not defined');
      }
      $configPath = DRUPAL_ROOT . '/' . $source;
      if (!is_dir($configPath)) {
        throw new RuntimeException(sprintf('Config directory %s does not exist', $configPath));
      }
      $modulePath = $this->moduleHandler->getModule($module)->getPath();
      $moduleConfigPath = $modulePath . '/config/install';
      if (!is_dir($moduleConfigPath)) {
        $this->fileSystem->mkdir($moduleConfigPath, 0755, TRUE);
      }

      foreach ($names as $name) {
        $this->output()->writeln($name);

        $filename = $name . '.yml';
        $source = $configPath . '/' . $filename;
        $destination = $moduleConfigPath . '/' . $filename;

        if (!file_exists($source)) {
          $this->output()->writeln(sprintf('Source file %s does not exist', $source));
          continue;
        }

        $this->fileSystem->move($source, $destination, FileSystemInterface::EXISTS_REPLACE);
        $this->output()->writeln(sprintf('%s -> %s', $source, $destination));
      }
    }
  }

  /**
   * Replace in keys and values.
   *
   * @see https://stackoverflow.com/a/29619470
   */
  private function replaceKeysAndValues(string $search, string $replace, array $input): array {
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

}
