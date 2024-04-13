<?php

namespace Drupal\config_helper\Drush\Commands;

use Drupal\config_helper\ConfigHelper;
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
    private readonly ConfigHelper $helper,
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
    $this->initialize();

    $names = $this->helper->getConfigNames($patterns);

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
    $this->initialize();

    if (!$this->helper->moduleExists($module)) {
      throw new RuntimeException(sprintf('Invalid module: %s', $module));
    }

    $configNames = empty($configNames)
      ? $this->helper->getModuleConfigNames($module)
      : $this->helper->getConfigNames($configNames);

    $question = sprintf("Enforce dependency on module %s in\n * %s\n ?", $module, implode("\n * ", $configNames));
    if ($this->io()->confirm($question)) {
      $this->helper->addEnforcedDependency($module, $configNames);
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
    $this->initialize();

    if (!$this->helper->moduleExists($module)) {
      throw new RuntimeException(sprintf('Invalid module: %s', $module));
    }

    if ($options['enforced']) {
      $configNames = $this->helper->getEnforcedModuleConfigNames($module);
    }
    else {
      $configNames = empty($configNames)
        ? $this->helper->getModuleConfigNames($module)
        : $this->helper->getConfigNames($configNames);
    }

    $configPath = $this->helper->getModuleConfigPath($module, $options['optional']);
    $question = sprintf("Write config\n * %s\n into %s?", implode("\n * ", $configNames), $configPath);
    if ($this->io()->confirm($question)) {
      $this->helper->writeModuleConfig($module, $options['optional'], $configNames);
    }
  }

  /**
   * Rename config.
   */
  #[CLI\Command(name: 'config_helper:rename')]
  #[CLI\Argument(name: 'from', description: 'The value to search for.')]
  #[CLI\Argument(name: 'to', description: 'The replacement value.')]
  #[CLI\Argument(name: 'configNames', description: 'The config names.')]
  #[CLI\Option(name: 'regex', description: 'Use regex search and replace.')]
  #[CLI\Usage(name: 'drush config_helper:rename porject project', description: 'Fix typo in config.')]
  #[CLI\Usage(name: "drush config_helper:rename '/field_(.+)/' '\1' --regex", description: 'Remove superfluous prefix from field machine names.')]
  public function rename(
    string $from,
    string $to,
    array $configNames,
    array $options = [
      'regex' => FALSE,
    ]
  ): void {
    $this->initialize();

    $configNames = $this->helper->getConfigNames($configNames);
    $question = sprintf("Rename %s to %s %sin\n * %s\n?", $from, $to, $options['regex'] ? '(regex)' : '', implode("\n * ", $configNames));
    if ($this->io()->confirm($question)) {
      $this->helper->renameConfig($from, $to, $configNames, (bool) $options['regex']);
    }
  }

  /**
   * Initialize.
   *
   * @todo Can we automatically call this before each command?
   */
  private function initialize(): void {
    $this->helper->setLogger($this->logger());
  }

}
