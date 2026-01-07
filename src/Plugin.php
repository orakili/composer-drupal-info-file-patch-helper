<?php

declare(strict_types=1);

namespace ComposerDrupalInfoFilePatchHelper;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use cweagans\Composer\Event\PatchEvent;
use cweagans\Composer\Event\PatchEvents;

/**
 * Drupal Info File Patch Helper Composer Plugin.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Comment starting the extra info added to a module `.info.yml`.
   *
   * @var string
   */
  private const DRUPAL_INFO_DELIMITER = '# Information added by Drupal.org packaging script';

  /**
   * The composer instance.
   *
   * @var \Composer\Composer
   */
  private Composer $composer;

  /**
   * The IO interface for logging.
   *
   * @var \Composer\IO\IOInterface
   */
  private IOInterface $io;

  /**
   * Stored drupal module extra info (keyed by package unique name).
   *
   * @var array
   */
  private array $drupalModuleExtraInfo = [];

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io): void {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PatchEvents::PRE_PATCH_APPLY => 'extractDrupalModuleInfo',
      PatchEvents::POST_PATCH_APPLY => 'restoreDrupalModuleInfo',
    ];
  }

  /**
   * Extract the extra info added by the Drupal packaging script.
   *
   * @param \cweagans\Composer\PatchEvent $event
   *   Patch event.
   */
  public function extractDrupalModuleInfo(PatchEvent $event): void {
    $package = $this->findPackageForEvent($event);
    if ($package === NULL) {
      return;
    }

    if (!$this->isNonCoreDrupalPackage($package)) {
      return;
    }

    $install_path = $this->getPackageInstallPath($package);
    if (!is_dir($install_path)) {
      $this->logError(sprintf('Install path does not exist: %s', $install_path), $package);
      return;
    }

    $pattern = '@(\n+)(' . preg_quote(static::DRUPAL_INFO_DELIMITER) . '.+)@sm';
    $drupal_module_extra_info = [];

    // Find all .info.yml files recursively in the install path.
    $info_files = $this->findInfoFiles($install_path, $package);
    if (empty($info_files)) {
      return;
    }

    foreach ($info_files as $file) {
      if (!is_readable($file)) {
        $this->logError(sprintf('Cannot read file: %s', $file), $package);
        continue;
      }

      $content = file_get_contents($file);
      if ($content === FALSE) {
        $this->logError(sprintf('Failed to read file: %s', $file), $package);
        continue;
      }

      // Check if the delimiter exists before attempting extraction.
      if (!str_contains($content, static::DRUPAL_INFO_DELIMITER)) {
        continue;
      }

      // Extract, store and remove the extra info added by Drupal.
      $changed_content = preg_replace_callback($pattern, function ($matches) use ($file, &$drupal_module_extra_info) {
        $drupal_module_extra_info[$file] = $matches[2];
        return "\n";
      }, $content);

      if ($changed_content !== $content) {
        if (file_put_contents($file, $changed_content) === FALSE) {
          $this->logError(sprintf('Failed to write file: %s', $file), $package);
          // Remove from storage if write failed.
          unset($drupal_module_extra_info[$file]);
        }
      }
    }

    if (!empty($drupal_module_extra_info)) {
      $this->drupalModuleExtraInfo[$package->getUniqueName()] = $drupal_module_extra_info;
      $this->logInfo(sprintf('Extracted Drupal packaging info from %d file(s)', count($drupal_module_extra_info)), $package);
    }
  }

  /**
   * Restore the extra info added by the Drupal packaging script.
   *
   * @param \cweagans\Composer\PatchEvent $event
   *   Patch event.
   */
  public function restoreDrupalModuleInfo(PatchEvent $event): void {
    $package = $this->findPackageForEvent($event);
    if ($package === NULL) {
      return;
    }

    if (!$this->isNonCoreDrupalPackage($package)) {
      return;
    }

    $unique_name = $package->getUniqueName();
    if (!isset($this->drupalModuleExtraInfo[$unique_name])) {
      return;
    }

    $files = $this->drupalModuleExtraInfo[$unique_name];

    if (empty($files)) {
      unset($this->drupalModuleExtraInfo[$unique_name]);
      return;
    }

    $restored_count = 0;
    foreach ($files as $file => $info) {
      if (!file_exists($file)) {
        $this->logError(sprintf('File no longer exists, cannot restore info: %s', $file), $package);
        continue;
      }

      if (!is_writable($file)) {
        $this->logError(sprintf('File is not writable: %s', $file), $package);
        continue;
      }

      $content = file_get_contents($file);
      if ($content === FALSE) {
        $this->logError(sprintf('Failed to read file: %s', $file), $package);
        continue;
      }

      // Only restore if the info is not already present.
      if (!str_contains($content, $info)) {
        $content = rtrim($content) . "\n\n" . $info;
        if (file_put_contents($file, $content) === FALSE) {
          $this->logError(sprintf('Failed to write file: %s', $file), $package);
        }
        else {
          $restored_count++;
        }
      }
    }

    if ($restored_count > 0) {
      $this->logInfo(sprintf('Restored Drupal packaging info to %d file(s)', $restored_count), $package);
    }

    unset($this->drupalModuleExtraInfo[$unique_name]);
  }

  /**
   * Find all .info.yml files recursively in a directory.
   *
   * @param string $directory
   *   Directory path to search.
   * @param \Composer\Package\PackageInterface|null $package
   *   Optional package for error logging.
   *
   * @return array
   *   Array of absolute file paths to .info.yml files.
   */
  private function findInfoFiles(string $directory, ?PackageInterface $package = NULL): array {
    $info_files = [];

    try {
      $directory_iterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
      $recursive_iterator = new \RecursiveIteratorIterator($directory_iterator);
      $info_file_iterator = new \RegexIterator($recursive_iterator, '/\.info\.yml$/i', \RegexIterator::MATCH);

      foreach ($info_file_iterator as $file_info) {
        if ($file_info->isFile()) {
          $info_files[] = $file_info->getPathname();
        }
      }
    }
    catch (\Exception $exception) {
      $this->logError(sprintf('Failed to scan for .info.yml files in: %s - %s', $directory, $exception->getMessage()), $package);
      return [];
    }

    return $info_files;
  }

  /**
   * Get the install path for the given package.
   *
   * @param \Composer\Package\PackageInterface $package
   *   Package.
   *
   * @return string
   *   Install path.
   */
  private function getPackageInstallPath(PackageInterface $package): string {
    return $this->composer
      ->getInstallationManager()
      ->getInstaller($package->getType())
      ->getInstallPath($package);
  }

  /**
   * Check if the package is a non core Drupal package.
   *
   * @param \Composer\Package\PackageInterface $package
   *   Package.
   *
   * @return bool
   *   TRUE if the package is a non core Drupal package.
   */
  private function isNonCoreDrupalPackage(PackageInterface $package): bool {
    $type = $package->getType();
    return $type !== 'drupal-core' && str_starts_with($type, 'drupal-');
  }

  /**
   * Find the package from a patch event.
   *
   * @param \cweagans\Composer\PatchEvent $event
   *   Patch event.
   *
   * @return \Composer\Package\PackageInterface|null
   *   Package, or NULL if the patch or package name is missing.
   *
   * @throws \RuntimeException
   *   When the package cannot be found.
   */
  private function findPackageForEvent(PatchEvent $event): ?PackageInterface {
    $patch = $event->getPatch();
    if ($patch === NULL) {
      $this->logError('Patch event does not contain a patch object.');
      return NULL;
    }

    // The package property is a required string, but may be uninitialized
    // or empty.
    if (!isset($patch->package) || $patch->package === '') {
      $this->logError('Patch does not contain a package name.');
      return NULL;
    }

    return $this->findPackage($patch->package);
  }

  /**
   * Find the package by unique name.
   *
   * @param string $name
   *   Package unique name.
   *
   * @return \Composer\Package\PackageInterface
   *   Package.
   *
   * @throws \RuntimeException
   *   When the package cannot be found.
   */
  private function findPackage(string $name): PackageInterface {
    $package = $this->composer->getRepositoryManager()->findPackage($name, '*');
    if (!$package) {
      throw new \RuntimeException(sprintf('Package "%s" not found. This may indicate a problem with the patch configuration.', $name));
    }
    return $package;
  }

  /**
   * Log an error message.
   *
   * @param string $message
   *   Error message.
   * @param \Composer\Package\PackageInterface|null $package
   *   Optional package to include in the message.
   */
  private function logError(string $message, ?PackageInterface $package = NULL): void {
    if ($package !== NULL) {
      $message = sprintf('[%s] %s', $package->getPrettyName(), $message);
    }
    $this->io->writeError($message, TRUE, IOInterface::VERBOSE);
  }

  /**
   * Log an informational message.
   *
   * @param string $message
   *   Informational message.
   * @param \Composer\Package\PackageInterface|null $package
   *   Optional package to include in the message.
   */
  private function logInfo(string $message, ?PackageInterface $package = NULL): void {
    if ($package !== NULL) {
      $message = sprintf('[%s] %s', $package->getPrettyName(), $message);
    }
    $this->io->write($message, TRUE, IOInterface::VERBOSE);
  }

}
