<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * A decrement (issue/transfer-out) was refused because on-hand would go negative.
 * Backorder — allowing negative stock — is a Commerce-era concern, deferred; v1
 * refuses, so the ledger never records an issue the location could not satisfy.
 */
final class InsufficientStock extends \RuntimeException
{
    public function __construct(
        public readonly string $skuCode,
        public readonly int $locationId,
    ) {
        parent::__construct("Not enough stock of \"{$skuCode}\" at location {$locationId} for that movement.");
    }
}
