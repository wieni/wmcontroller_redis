<?php

namespace Drupal\wmcontroller_redis\Storage;

interface MarksExpiredInterface
{

    /**
     * Search for stale entries and mark them as expired. This should be run
     * periodically (nightly).
     *
     * @return int The number of stale entries found
     */
    public function markExpired();

}
