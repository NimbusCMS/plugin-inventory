<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * Inventory's implementation of {@see ReservationPort} — the thin translation
 * between the port's location *codes* (what a consumer like Commerce knows) and
 * the {@see Reservations} service's location ids, and the server clock. All the
 * real work (the availability guard, the atomic issue) lives in Reservations; this
 * only adapts, so consuming the port grants no capability Inventory wouldn't.
 */
final class ReservationAdapter implements ReservationPort
{
    public function __construct(
        private Ledger $ledger,
        private Reservations $reservations,
    ) {
    }

    public function reserve(string $sku, string $location, string $qty, string $ref): array
    {
        return $this->reservations->reserve($sku, $this->locationId($location), $qty, $ref, $this->now());
    }

    public function release(string $ref): int
    {
        return $this->reservations->release($ref);
    }

    public function issue(string $sku, string $location, string $qty, string $ref, string $actor): array
    {
        return $this->reservations->issue($sku, $this->locationId($location), $qty, $ref, $actor, $this->now());
    }

    public function available(string $sku, string $location): string
    {
        return $this->reservations->availableOf($sku, $this->locationId($location));
    }

    private function locationId(string $code): int
    {
        return $this->ledger->ensureLocation($code, $code, $this->now());
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
