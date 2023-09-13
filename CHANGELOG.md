# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2023-09-13
### Changed
- **BC** Use CacheTagChecksum to invalidate cache tags. See https://github.com/wieni/wmcontroller_redis/pull/4
  - Upgrade steps:
    - Add `wmcontroller.cache.invalidator: wmcontroller.redis.checksum` to your `services.yml` file.
    - Run `drush wmcontroller_redis:mark-expired` nightly to search for stale entries and mark them for deletion

## [1.1.0] - 2023-09-13
### Added
- Add Drupal 10 compatibility

## [1.0.1] - 2022-06-28
### Changed
- Add stub of successor module for during the upgrade

## [1.0.0] - 2020-08-24
- Mark as compatible with wmcontroller `1.0.0`

## [0.9.0] - 2020-02-07
### Added
- Add .gitignore
- Add Github issue & pull request templates
- Add code style fixers
- Add changelog
- Add PHP & drupal/core requirements
- Add MIT license

### Changed
- Update readme
- Update module description
- Update wmcontroller version constraint

## [0.8.1] - 2019-02-22
### Fixed
- Fix PHP going out of memory

### Changed
- Silently fail if no Redis is provided

## [0.8.0] - 2019-02-22
Initial release
