<?php

namespace Reach\StatamicResrv\Tests\Caching;

/**
 * An object the addon never allow-lists. CacheSerializationTest caches it to prove the
 * store is serializing with allowed_classes active (it must return incomplete).
 */
class UnregisteredCacheFixture
{
    public function __construct(public string $marker = 'resrv') {}

    public function marker(): string
    {
        return $this->marker;
    }
}
