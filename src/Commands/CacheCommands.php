<?php

namespace Drupal\wmcontroller_redis\Commands;

use Drupal\wmcontroller\Service\Cache\Storage\StorageInterface;
use Drupal\wmcontroller_redis\Storage\MarksExpiredInterface;
use Drush\Commands\DrushCommands;

class CacheCommands extends DrushCommands
{

    /** @var \Drupal\wmcontroller\Service\Cache\Storage\StorageInterface */
    protected $storage;

    public function __construct(StorageInterface $storage)
    {
        parent::__construct();
        $this->storage = $storage;
    }

    /**
     * Look for stale cache entries and mark them.
     *
     * @command wmcontroller_redis:mark-expired
     */
    public function markExpired()
    {
        if (!$this->storage instanceof MarksExpiredInterface) {
            throw new \RuntimeException('Storage does not support marking stale entries');
        }

        $count = $this->storage->markExpired();
        if ($this->logger()) {
            $this->logger()->notice("Marked $count stale entries for deletion.");
        }
    }

}
