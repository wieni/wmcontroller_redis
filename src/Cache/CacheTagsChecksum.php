<?php

namespace Drupal\wmcontroller_redis\Cache;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Utility\Error;
use Drupal\redis\Cache\RedisCacheTagsChecksum;
use Drupal\redis\Client\PhpRedis;
use Drupal\redis\ClientFactory;
use Drupal\redis\ClientInterface;
use Drupal\wmcontroller\Service\Cache\InvalidatorInterface;
use Drupal\wmcontroller_redis\RedisClientFactory;

class CacheTagsChecksum implements CacheTagsChecksumInterface, CacheTagsInvalidatorInterface, InvalidatorInterface
{
    /** @var \Drupal\wmcontroller_redis\RedisClientFactory */
    protected $redisClientFactory;

    /** @var CacheTagsChecksumInterface|CacheTagsInvalidatorInterface|false */
    private $redisCacheTagsChecksum;

    public function __construct(RedisClientFactory $redisClientFactory)
    {
        $this->redisClientFactory = $redisClientFactory;
    }

    public function getCurrentChecksum(array $tags)
    {
        if ($decorated = $this->decorated()) {
            return $decorated->getCurrentChecksum($tags);
        }
        return 'noop';
    }

    public function isValid($checksum, array $tags)
    {
        if ($decorated = $this->decorated()) {
            return $decorated->isValid($checksum, $tags);
        }
        return false;
    }

    public function reset()
    {
        if ($decorated = $this->decorated()) {
            $decorated->reset();
        }
    }

    public function invalidateCacheTags(array $tags)
    {
        // noop
        // We use Drupal's cache tag invalidation system to invalidate the cache
    }

    public function invalidateTags(array $tags)
    {
        if ($decorated = $this->decorated()) {
            $decorated->invalidateTags($tags);
        }
    }

    /** @return CacheTagsChecksumInterface|CacheTagsInvalidatorInterface|null */
    protected function decorated()
    {
        if (isset($this->redisCacheTagsChecksum)) {
            return $this->redisCacheTagsChecksum ?: null;
        }

        $decorated = false;
        try {
            $client = $this->redisClientFactory::getClient();
            if ($client instanceof \Redis) {
                $decorated = new RedisCacheTagsChecksum($this->clientFactory($client));
            }
        } catch (\Exception $e) {
            $logger = \Drupal::logger('wmcontroller.redis');
            Error::logException($logger, $e);
        }

        return $this->redisCacheTagsChecksum = $decorated;
    }

    /**
     * Hands our own connection to drupal/redis' RedisCacheTagsChecksum, which
     * only accepts a \Drupal\redis\ClientFactory.
     *
     * Anonymous classes, because the two drupal/redis majors have incompatible
     * ClientFactory::getClient() signatures. PHP only checks the one that runs.
     */
    private function clientFactory(\Redis $redis): ClientFactory
    {
        // drupal/redis 1.x: static methods, getClient() returns \Redis.
        if (method_exists(PhpRedis::class, 'getClient')) {
            $factory = new class extends ClientFactory {
                public static $redis;

                public static function getClient()
                {
                    return static::$redis;
                }

                public static function getClientName()
                {
                    return 'PhpRedis';
                }
            };
            $factory::$redis = $redis;

            return $factory;
        }

        // drupal/redis 2.x: instance methods, getClient() returns a wrapper.
        return new class($redis) extends ClientFactory {
            private $redis;

            public function __construct(\Redis $redis)
            {
                $this->redis = $redis;
            }

            public function getClient(): ClientInterface
            {
                return new PhpRedis($this->redis);
            }

            public function getClientName(): ?string
            {
                return 'PhpRedis';
            }
        };
    }
}
