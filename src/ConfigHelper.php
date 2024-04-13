<?php

namespace Drupal\config_helper;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Exception\RuntimeException;

/**
 * Config helper.
 */
class ConfigHelper {
  use LoggerAwareTrait;

  /**
   * Contructor.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
  ) {
    $this->setLogger(new NullLogger());
  }

  /**
   * Add enforced module dependency.
   */
  public function addEnforcedDependency(
    string $module,
    array $configNames
  ): void {
    foreach ($configNames as $name) {
      $this->logger->info(sprintf('Config name: %s', $name));

      $config = $this->configFactory->getEditable($name);

      $data = $config->getRawData();
      $data['dependencies']['enforced']['module'][] = $module;
      $data['dependencies']['enforced']['module'] = array_unique($data['dependencies']['enforced']['module']);
      sort($data['dependencies']['enforced']['module']);
      $config->setData($data);

      $config->save();
    }
  }

  /**
   * Remove enforced module dependency.
   */
  public function removeEnforcedDependency(
    string $module,
    array $configNames
  ): void {
    foreach ($configNames as $name) {
      $this->logger->info(sprintf('Config name: %s', $name));

      $config = $this->configFactory->getEditable($name);

      $data = $config->getRawData();
      if (isset($data['dependencies']['enforced']['module'])) {
        $data['dependencies']['enforced']['module'] = array_diff($data['dependencies']['enforced']['module'], [$module]);
        sort($data['dependencies']['enforced']['module']);
        if (empty($data['dependencies']['enforced']['module'])) {
          unset($data['dependencies']['enforced']['module']);
        }
        if (empty($data['dependencies']['enforced'])) {
          unset($data['dependencies']['enforced']);
        }
        if (empty($data['dependencies'])) {
          unset($data['dependencies']);
        }
        $config->setData($data);
        $config->save();
      }
    }
  }

  /**
   * Write module config.
   */
  public function writeModuleConfig(
    string $module,
    mixed $optional,
    array $configNames
  ): void {
    $moduleConfigPath = $this->getModuleConfigPath($module, $optional);
    if (!is_dir($moduleConfigPath)) {
      $this->fileSystem->mkdir($moduleConfigPath, 0755, TRUE);
    }

    foreach ($configNames as $name) {
      $this->logger->info($name);

      $filename = $name . '.yml';
      $destination = $moduleConfigPath . '/' . $filename;
      $config = $this->configFactory->get($name)->getRawData();
      // @see https://www.drupal.org/node/2087879#s-exporting-configuration
      unset($config['uuid'], $config['_core']);

      file_put_contents($destination, Yaml::encode($config));
    }
  }

  /**
   * Rename config.
   */
  public function renameConfig(
    string $from,
    string $to,
    array $configNames,
    bool $regex = FALSE
  ): void {
    $replacer = $regex
      ? static fn (string $subject) => preg_replace($from, $to, $subject)
      : static fn (string $subject) => str_replace($from, $to, $subject);

      foreach ($configNames as $name) {
        $config = $this->configFactory->getEditable($name);
        $data = $config->getRawData();
        $newData = $this->replaceKeysAndValues($replacer, $data);
        if ($newData !== $data) {
          $config->setData($newData);
          $this->logger->info(sprintf('Saving updated config %s', $name));
          $config->save();
        }

        $newName = $replacer($name);
        if ($newName !== $name) {
          $this->logger->info(sprintf('Renaming config %s to %s', $name, $newName));
          $this->configFactory->rename($name, $newName);
        }
      }
  }

  /**
   * Get config names matching patterns.
   */
  public function getConfigNames(array $patterns): array {
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
  public function getModuleConfigNames(string $module): array {
    return $this->getConfigNames(['*.' . $module . '.*']);
  }

  /**
   * Get names of config that has an enforced dependency on a module.
   */
  public function getEnforcedModuleConfigNames(string $module): array {
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

  /**
   * Check if a module exists.
   */
  public function moduleExists(string $module): bool {
    return $this->moduleHandler->moduleExists($module);
  }

  /**
   * Get module config path.
   */
  public function getModuleConfigPath(string $module, bool $optional): string {
    $modulePath = $this->moduleHandler->getModule($module)->getPath();

    return $modulePath . '/config/' . ($optional ? 'optional' : 'install');
  }

  /**
   * Replace in keys and values.
   *
   * @see https://stackoverflow.com/a/29619470
   */
  private function replaceKeysAndValues(
    callable $replacer,
    array $input
  ): array {
    $return = [];
    foreach ($input as $key => $value) {
      $key = $replacer($key);

      if (is_array($value)) {
        $value = $this->replaceKeysAndValues($replacer, $value);
      }
      elseif (is_string($value)) {
        $value = $replacer($value);
      }

      $return[$key] = $value;
    }

    return $return;
  }

}
