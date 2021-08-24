wmcontroller_redis
======================

[![Latest Stable Version](https://poser.pugx.org/wieni/wmcontroller_redis/v/stable)](https://packagist.org/packages/wieni/wmcontroller_redis)
[![Total Downloads](https://poser.pugx.org/wieni/wmcontroller_redis/downloads)](https://packagist.org/packages/wieni/wmcontroller_redis)
[![License](https://poser.pugx.org/wieni/wmcontroller_redis/license)](https://packagist.org/packages/wieni/wmcontroller_redis)

> A Redis cache storage for [wieni/wmcontroller](https://github.com/wieni/wmcontroller)

## Installation

This package requires PHP 8.0, PhpRedis and Drupal 9.1 or higher. It can be
installed using Composer:

```bash
 composer require wieni/wmcontroller_redis
```

To enable this cache storage, change the `wmcontroller.cache.storage` container parameter:
```yaml
parameters:
    wmcontroller.cache.storage: wmcontroller.cache.storage.redis

    wmcontroller.cache.redis.prefix: 'wmcontroller:'
```

Make sure to also set the host & base in `settings.php`.
```php
$settings['wmcontroller.redis.connection']['host'] = '127.0.0.1';
$settings['wmcontroller.redis.connection']['base'] = '0';
```

## Changelog
All notable changes to this project will be documented in the
[CHANGELOG](CHANGELOG.md) file.

## Security
If you discover any security-related issues, please email
[security@wieni.be](mailto:security@wieni.be) instead of using the issue
tracker.

## License
Distributed under the MIT License. See the [LICENSE](LICENSE) file
for more information.
