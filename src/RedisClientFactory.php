<?php

namespace Drupal\wmcontroller_redis;

use Drupal\Core\Site\Settings;
use Drupal\Core\Utility\Error;
use Drupal\redis\Client\PhpRedis;
use Drupal\redis\ClientFactory;

/**
 * Connects to the Redis server in $settings['wmcontroller.redis.connection'].
 *
 * This does not extend \Drupal\redis\ClientFactory: drupal/redis 2.x made its
 * getClient() an instance method that returns a wrapper, and this module needs
 * the \Redis connection itself.
 */
class RedisClientFactory
{
    protected static $client;

    public static function getClient()
    {
        if (isset(static::$client)) {
            return static::$client;
        }

        $settings = Settings::get('wmcontroller.redis.connection', []);
        $settings += [
            'host' => ClientFactory::REDIS_DEFAULT_HOST,
            'port' => ClientFactory::REDIS_DEFAULT_PORT,
            'base' => ClientFactory::REDIS_DEFAULT_BASE,
            'password' => ClientFactory::REDIS_DEFAULT_PASSWORD,
        ];

        try {
            return static::$client = static::connect($settings);
        } catch (\Exception $e) {
            $logger = \Drupal::logger('wmcontroller.redis');
            Error::logException($logger, $e);
            return null;
        }
    }

    protected static function connect(array $settings): \Redis
    {
        // drupal/redis 1.x
        if (method_exists(PhpRedis::class, 'getClient')) {
            return (new PhpRedis())->getClient(
                $settings['host'],
                $settings['port'],
                $settings['base'],
                $settings['password']
            );
        }

        // drupal/redis 2.x only hands out wrapped connections. This is what
        // drupal/redis 1.x does for a single host.
        $redis = new \Redis();
        $redis->connect($settings['host'], $settings['port'], 0.5);

        if (isset($settings['password'])) {
            $redis->auth($settings['password']);
        }

        if (isset($settings['base'])) {
            $redis->select($settings['base']);
        }

        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

        return $redis;
    }
}
