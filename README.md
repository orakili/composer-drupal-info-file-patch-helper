# Drupal Info File Patch Helper - Composer plugin

This is a simple plugin to help applying patches that modify the `module.info.yml` of a drupal module, for example the Drupal 10 compatibility patches created by Drupal rector.

## Requirements

This plugin listens to the patch events from the https://github.com/cweagans/composer-patches plugin so it is only useful when this plugin is added as well.

**Note:** This version requires `cweagans/composer-patches` version 2. For version 1 compatibility, use the latest 1.x release.

## Why is this needed?

The Drupal packaging script adds information at the end of the `module.info.yml` file when generating the archive for distribution.

Example:

```
# Information added by Drupal.org packaging script on 2022-11-07
version: '8.x-1.3'
project: 'my_module'
datestamp: 1667786708
```

That prevents applying cleanly the patches that modify the `module.info.yml` for example to change the `core_version_requirement` (Drupal 9 -> Drupal 10 upgrade).

## What does it do?

This plugin simply listens to the patch events from the `cweagans/composer-patches` plugin and before the patch is applied, remove and store the info added by the Drupal packaging script and add it back after the patch is applied.
