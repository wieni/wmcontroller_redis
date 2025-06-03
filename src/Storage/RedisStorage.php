<?php

namespace Drupal\wmcontroller_redis\Storage;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Utility\Error;
use Drupal\wmcontroller\Entity\Cache;
use Drupal\wmcontroller\Exception\NoSuchCacheEntryException;
use Drupal\wmcontroller\Service\Cache\CacheSerializerInterface;
use Drupal\wmcontroller\Service\Cache\Storage\StorageInterface;
use Drupal\wmcontroller_redis\RedisClientFactory;
use Redis;

class RedisStorage implements StorageInterface, MarksExpiredInterface
{
    /** @var \Redis */
    protected $redis;
    /** @var \Drupal\wmcontroller\Service\Cache\CacheSerializerInterface */
    protected $serializer;
    /** @var \Drupal\Core\Cache\CacheTagsChecksumInterface */
    protected $cacheTagsChecksum;
    protected $prefix;

    public function __construct(
        RedisClientFactory $clientFactory,
        CacheSerializerInterface $serializer,
        CacheTagsChecksumInterface $cacheTagsChecksum,
        $prefix = ''
    ) {
        try {
            $this->redis = $clientFactory::getClient();
            if (!$this->redis instanceof Redis) {
                throw new \RuntimeException(
                    'RedisClientFactory did not return a Redis instance. Silently failing...'
                );
            }
        } catch (\Exception $e) {
            $this->redis = null;
            $logger = \Drupal::logger('wmcontroller.redis');
            Error::logException($logger, $e);
        }
        $this->serializer = $serializer;
        $this->cacheTagsChecksum = $cacheTagsChecksum;
        $this->prefix = $prefix;
    }

    public function load($id, $includeBody = true)
    {
        $item = $this->loadMultiple([$id], $includeBody)->current();
        if (!$item) {
            throw new NoSuchCacheEntryException($id);
        }

        return $item;
    }

    public function loadMultiple(array $ids, $includeBody = true): \Iterator
    {
        if (!$this->redis) {
            return [];
        }

        $time = time();

        foreach (array_chunk($ids, 50) as $chunk) {
            $rows = $this->redis->mget(
                $this->prefix($chunk, $includeBody ? 'body' : '')
            );
            $checksums = $this->redis->mget(
                $this->prefix($chunk, 'checksum')
            );

            foreach ($rows as $i => $row) {
                if (!$row) {
                    continue;
                }
                $item = $this->serializer->denormalize($row);
                $tags = $this->redis->sMembers(
                    $this->prefix($item->getId(), 'tags')
                );
                if (
                    $item->getExpiry() > $time
                    && (
                        !isset($checksums[$i])
                        || $this->cacheTagsChecksum->isValid(
                            $checksums[$i],
                            $tags
                        )
                    )
                ) {
                    yield $item;
                }
            }
        }
    }

    public function set(Cache $item, array $tags)
    {
        if (!$this->redis) {
            return;
        }
        $id = $item->getId();
        $time = time();

        $tx = $this->redis->multi();

        $tx->set(
            $this->prefix($id),
            $this->serializer->normalize($item, false),
            ($item->getExpiry() - $time)
        );
        $tx->set(
            $this->prefix($id, 'body'),
            $this->serializer->normalize($item, true),
            ($item->getExpiry() - $time)
        );
        $tx->set(
            $this->prefix($id, 'checksum'),
            $this->cacheTagsChecksum->getCurrentChecksum($tags),
            ($item->getExpiry() - $time)
        );
        $tx->zAdd(
            $this->prefix('expiries'),
            $item->getExpiry(),
            $id
        );
        $tx->sRem($this->prefix('stale'), $id);

        $tx->del($this->prefix($id, 'tags'));
        if (!empty($tags)) {
            $tx->sAdd(
                $this->prefix($id, 'tags'),
                ...$tags
            );
            foreach ($tags as $tag) {
                $tx->sAdd(
                    $this->prefix($tag),
                    $id
                );
            }
        }

        $path = parse_url($item->getUri(), PHP_URL_PATH);
        if ($path) {
            $tx->set(
                $this->prefix($id, 'path'),
                $path
            );
        }

        $tx->exec();
    }

    public function getByTags(array $tags)
    {
        if (!$this->redis || !$tags) {
            return [];
        }

        $tags = array_map([$this, 'prefix'], $tags);

        $this->redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        $ids = [];
        foreach ($tags as $tag) {
            $iterator = null;
            while ($results = $this->redis->sScan($tag, $iterator)) {
                foreach ($results as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    public function remove(array $ids)
    {
        if (!$this->redis) {
            return;
        }

        $tagSet = $this->getTags($ids) ?: [];

        $pipeline = $this->redis->pipeline();

        foreach ($ids as $i => $id) {
            $tags = $tagSet[$i] ?: [];

            $tx = $pipeline->multi();

            $tx->del($this->prefix($id));
            $tx->del($this->prefix($id, 'body'));
            $tx->del($this->prefix($id, 'tags'));
            $tx->del($this->prefix($id, 'checksum'));
            $tx->del($this->prefix($id, 'path'));
            $tx->zRem($this->prefix('expiries'), $id);
            $tx->sRem($this->prefix('stale'), $id);

            foreach ($tags as $tag) {
                $tx->sRem(
                    $this->prefix($tag),
                    $id
                );
            }

            $tx->exec();
        }

        $pipeline->exec();
    }

    public function getExpired($amount)
    {
        if (!$this->redis) {
            return [];
        }

        // Return items from the stale set. See ::markExpired()
        return $this->redis->sPop($this->prefix('stale'), $amount) ?: [];
    }

    public function flush()
    {
        if (!$this->redis) {
            return;
        }

        $this->redis->flushDB();
    }

    /**
     * {@inheritdoc}
     *
     * This is a very expensive operation that does a checksum calculation
     * for every cache entry. Due to how the cacheTagsChecksum implementation
     * of Drupal core works (with a static cache), this becomes slower and
     * slower after each performed calculation. This is why we periodically
     * reset the static cache of the cacheTagsChecksum service.
     * (Risky, because the reset method appears to be only used by tests)
     *
     * However, that has a side effect; that static cache is also used to track
     * which tags have been invalidated during the request, to prevent the
     * invalidation of the same cache tag multiple times.
     * It is therefore best to run this method in a separate (drush) process
     * where you don't perform any invalidations.
     *
     * This module is used in high-traffic sites. This method has been tested
     * against >300K cache entries. Without the periodic resets, performance
     * is, unfortunately, disastrous.
     */
    public function markExpired()
    {
        if (!$this->redis) {
            return 0;
        }

        $now = time();
        $staleCount = 0;
        foreach ($this->getAllExpiries() as $expiries) {
            $entryIds = array_keys($expiries);
            $tagsSet = $this->getTags($entryIds) ?: [];
            $checksumsSet = $this->getChecksums($entryIds) ?: [];

            // Reset the static cache. Drastically improves performance.
            $this->cacheTagsChecksum->reset();

            // Instead of fetching the cachetag invalidation counts one
            // by one, we can fetch them all at once. This improves performance
            $allCacheTags = [];
            foreach ($tagsSet as $tags) {
                $allCacheTags += array_flip($tags ?: []);
            }
            $this->cacheTagsChecksum->getCurrentChecksum(array_keys($allCacheTags));
            unset($allCacheTags);

            // Loop over all entries and check if they are stale.
            $staleIds = [];
            foreach ($entryIds as $i => $entryId) {
                $expiry = (int) $expiries[$entryId];
                $checksum = $checksumsSet[$i];
                $cacheTags = (array) ($tagsSet[$i] ?: []);

                if (
                    $expiry <= $now
                    || (
                        $checksum === false
                        || !$this->cacheTagsChecksum->isValid((int) $checksum, $cacheTags)
                    )
                ) {
                    $staleIds[] = $entryId;
                }
            }

            $staleCount += count($staleIds);
            if (!empty($staleIds)) {
                $this->redis->sAdd($this->prefix('stale'), ...$staleIds);
            }
        }

        return $staleCount;
    }

    private function prefix($string, $prefix = '')
    {
        if (is_array($string)) {
            $result = [];
            foreach ($string as $str) {
                $result[] = $this->prefix($str, $prefix);
            }
            return $result;
        }
        return $this->prefix . ($prefix ? "$prefix:" : '') . $string;
    }

    /** @return \Generator<array<array<int, float>>> */
    private function getAllExpiries(): \Generator
    {
        if (!$this->redis) {
            return [];
        }

        $this->redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        $iterator = null;
        while ($results = $this->redis->zScan($this->prefix('expiries'), $iterator, null, 1000)) {
            yield $results;
        }
    }

    /** @return iterable<string[]|false>|false */
    private function getTags(array $ids)
    {
        $tx = $this->redis->pipeline();
        foreach ($ids as $id) {
            $tx->sMembers($this->prefix($id, 'tags'));
        }

        return $tx->exec();
    }

    /** @return iterable<string[]|false>|false */
    private function getChecksums(array $ids)
    {
        return $this->redis->mGet($this->prefix($ids, 'checksum'));
    }
}
