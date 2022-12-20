<?php

declare(strict_types=1);

namespace ComposerDrupalInfoFilePatchHelper;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;

/**
 * Drupal Info File Patch Helper Composer Plugin.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Comment starting the extra info added to a module `.info.yml`.
   *
   * @var string
   */
  const DRUPAL_INFO_DELIMITER = '# Information added by Drupal.org packaging script';

  /**
   * The composer instance.
   *
   * @var \Composer\Composer
   */
  private Composer $composer;

  /**
   * Stored drupal module extra info (keyed by package unique name).
   *
   * @var array
   */
  protected array $drupalModuleExtraInfo = [];

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io): void {
    $this->composer = $composer;
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
    $package = $event->getPackage();

    if (!$this->isNonCoreDrupalPackage($package)) {
      return;
    }

    $install_path = $this->getPackageInstallPath($package);

    $pattern = '@(\n+)(' . preg_quote(static::DRUPAL_INFO_DELIMITER) . '.+)@sm';

    $drupal_module_extra_info = [];
    foreach (glob($install_path . '/*.info.yml') as $file) {
      $content = file_get_contents($file);
      if ($content !== FALSE) {
        // Extract, store and remove the extra info added by Drupal.
        $changed_content = preg_replace_callback($pattern, function ($matches) use ($file, &$drupal_module_extra_info) {
          $drupal_module_extra_info[$file] = $matches[2];
          return "\n";
        }, $content);

        if ($changed_content !== $content) {
          file_put_contents($file, $changed_content);
        }
      }
    }

    if (!empty($drupal_module_extra_info)) {
      $this->drupalModuleExtraInfo[$package->getUniqueName()] = $drupal_module_extra_info;
    }
  }

  /**
   * Restore the extra info added by the Drupal packaging script.
   *
   * @param \cweagans\Composer\PatchEvent $event
   *   Patch event.
   */
  public function restoreDrupalModuleInfo(PatchEvent $event): void {
    $package = $event->getPackage();

    if (!$this->isNonCoreDrupalPackage($package)) {
      return;
    }

    $unique_name = $package->getUniqueName();
    if (isset($this->drupalModuleExtraInfo[$unique_name])) {
      foreach ($this->drupalModuleExtraInfo[$unique_name] as $file => $info) {
        if (file_exists($file)) {
          $content = file_get_contents($file);
          if ($content !== FALSE && !str_contains($content, $info)) {
            $content = rtrim($content) . "\n\n" . $info;
            file_put_contents($file, $content);
          }
        }
      }
      unset($this->drupalModuleExtraInfo[$unique_name]);
    }
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

}
