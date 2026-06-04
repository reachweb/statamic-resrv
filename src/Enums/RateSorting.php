<?php

namespace Reach\StatamicResrv\Enums;

enum RateSorting: string
{
    case Order = 'order';
    case Price = 'price';

    /**
     * Normalize an arbitrary (developer-supplied) value, falling back to the
     * default "order" sorting for anything that is not a known case.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Order;
    }
}
