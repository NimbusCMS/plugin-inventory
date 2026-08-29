<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * The contract Inventory publishes for other plugins to reserve stock against
 * (ADR 0019 service ports). Commerce depends on this **interface** — not on
 * Inventory's implementation — and obtains the live one at request time via
 * `$ctx->services()->get(ReservationPort::class)`, which is `null` when no
 * inventory plugin is installed (so a consumer can degrade gracefully).
 *
 * It speaks the caller's terms: a SKU code, a location code (created if new), a
 * decimal quantity string, and a `ref` — the caller's own key for the hold (an
 * order-line id), used to release or fulfil it later.
 */
interface ReservationPort
{
    /**
     * Hold stock under `ref`, if that much is available.
     *
     * @return array{reservation_id:int,available:string}
     *
     * @throws \RuntimeException if available won't cover the hold
     */
    public function reserve(string $sku, string $location, string $qty, string $ref): array;

    /**
     * Release every hold under `ref` (a cancelled or expired order). Idempotent.
     *
     * @return int holds released
     */
    public function release(string $ref): int;

    /**
     * Fulfil a hold: ship the quantity (an auditable stock movement) and release
     * its `ref`, atomically. `actor` is recorded on the movement.
     *
     * @return array{movement_id:int,on_hand:string,available:string}
     */
    public function issue(string $sku, string $location, string $qty, string $ref, string $actor): array;

    /** Available (on-hand − reserved) for a SKU at a location. */
    public function available(string $sku, string $location): string;
}
