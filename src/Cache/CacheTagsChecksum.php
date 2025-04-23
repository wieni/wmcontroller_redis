<?php

namespace Drupal\wmcontroller_redis\Cache;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Utility\Error;
use Drupal\redis\Cache\RedisCacheTagsChecksum;
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
                $decorated = new RedisCacheTagsChecksum($this->redisClientFactory);
            }
        } catch (\Exception $e) {
            $logger = \Drupal::logger('wmcontroller.redis');
            Error::logException($logger, $e);
        }

        return $this->redisCacheTagsChecksum = $decorated;
    }
}
