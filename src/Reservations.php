<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Plugin\PluginStorage;

/**
 * The reservation overlay (Commerce slice 1) — soft holds on stock, so a checkout
 * can promise an item before it ships without two customers being sold the same
 * unit. `available = on_hand − reserved`.
 *
 * Reservations are deliberately **not** ledger movements: a hold is mutable (it is
 * made, then released or turned into an issue), whereas the ledger is append-only
 * fact. So this is a separate mutable table read against the ledger's on-hand.
 *
 * Three invariants these methods keep:
 *  - **A reservation never exceeds available.** `reserve` re-reads reserved inside
 *    its transaction and rejects if the hold wouldn't fit, so two concurrent
 *    checkouts cannot over-promise the same stock.
 *  - **Issuing is atomic.** `issue` appends the −movement *and* releases the hold
 *    in one transaction: stock ships and the hold clears together, or neither does.
 *  - **`available` never goes negative** — it is `on_hand − reserved`, floored at 0
 *    for reporting (an over-issue can't happen because the ledger guards on_hand).
 */
final class Reservations
{
    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage, private Ledger $ledger)
    {
    }

    /**
     * Hold `qty` of a SKU at a location under `ref`, if that much is available.
     *
     * @return array{reservation_id:int,available:string}
     *
     * @throws InsufficientStock if available won't cover the hold
     */
    public function reserve(string $sku, int $locationId, string $qty, string $ref, string $now): array
    {
        $qty = $this->positive($qty);

        return $this->storage()->transaction(function () use ($sku, $locationId, $qty, $ref, $now): array {
            $s = $this->storage();
            // Serialise concurrent reserves for this SKU+location by locking its
            // stock row: on_hand is read under the lock and no other reserve can
            // slip a hold in beneath us before we commit, so two checkouts can't
            // over-promise the same stock (the phantom the naive read-then-insert
            // would allow). A SKU never received has no row → available 0 → reject.
            $stock  = $s->selectOne(
                'SELECT on_hand FROM ' . Schema::STOCK . ' WHERE sku_code = :sku AND location_id = :loc FOR UPDATE',
                ['sku' => $sku, 'loc' => $locationId],
            );
            $onHand   = $stock === null ? '0.0000' : (string) $stock['on_hand'];
            $reserved = $this->reservedOf($sku, $locationId);

            // available = on_hand − reserved; the new hold must fit. Compared in
            // MySQL (exact decimals), no float.
            $fits = $s->selectOne(
                'SELECT (CAST(:on AS DECIMAL(18,4)) - CAST(:res AS DECIMAL(18,4))) >= CAST(:qty AS DECIMAL(18,4)) AS ok',
                ['on' => $onHand, 'res' => $reserved, 'qty' => $qty],
            );
            if ($fits === null || (int) $fits['ok'] !== 1) {
                throw new InsufficientStock($sku, $locationId);
            }

            $id = $s->insert(
                'INSERT INTO ' . Schema::RESERVATION . ' (ref, sku_code, location_id, qty, created_at)
                 VALUES (:ref, :sku, :loc, :qty, :now)',
                ['ref' => $ref, 'sku' => $sku, 'loc' => $locationId, 'qty' => $qty, 'now' => $now],
            );

            return ['reservation_id' => $id, 'available' => $this->availableOf($sku, $locationId)];
        });
    }

    /**
     * Release every hold under a ref (a cancelled or expired checkout). Idempotent:
     * releasing an unknown ref frees nothing and is not an error.
     *
     * @return int the number of holds released
     */
    public function release(string $ref): int
    {
        return $this->storage()->execute(
            'DELETE FROM ' . Schema::RESERVATION . ' WHERE ref = :ref',
            ['ref' => $ref],
        );
    }

    /**
     * Fulfil a hold: ship `qty` (a −movement on the ledger, reason `issue`) and
     * release the ref's holds, atomically. If the ledger can't cover the issue the
     * whole thing rolls back.
     *
     * @return array{movement_id:int,on_hand:string,available:string}
     */
    public function issue(string $sku, int $locationId, string $qty, string $ref, string $actor, string $now): array
    {
        $qty = $this->positive($qty);

        /** @var array{movement_id:int,on_hand:string} $moved */
        $moved = $this->storage()->transaction(function () use ($sku, $locationId, $qty, $ref, $actor, $now): array {
            $m = $this->ledger->adjust($sku, $locationId, '-' . $qty, $this->uom($sku, $locationId), $actor, $now, 'issue', 'reservation', $ref);
            $this->release($ref);
            return $m;
        });

        return ['movement_id' => $moved['movement_id'], 'on_hand' => $moved['on_hand'], 'available' => $this->availableOf($sku, $locationId)];
    }

    /** Total currently reserved for a SKU at a location. */
    public function reservedOf(string $sku, int $locationId): string
    {
        $row = $this->storage()->selectOne(
            'SELECT COALESCE(SUM(qty), 0) AS reserved FROM ' . Schema::RESERVATION . ' WHERE sku_code = :sku AND location_id = :loc',
            ['sku' => $sku, 'loc' => $locationId],
        );
        return $row === null ? '0.0000' : (string) $row['reserved'];
    }

    /** available = on_hand − reserved (floored at 0 for reporting). */
    public function availableOf(string $sku, int $locationId): string
    {
        $row = $this->storage()->selectOne(
            'SELECT GREATEST(0, CAST(:on AS DECIMAL(18,4)) - CAST(:res AS DECIMAL(18,4))) AS avail',
            ['on' => $this->ledger->onHand($sku, $locationId), 'res' => $this->reservedOf($sku, $locationId)],
        );
        return $row === null ? '0.0000' : (string) $row['avail'];
    }

    private function uom(string $sku, int $locationId): string
    {
        return $this->ledger->uomFor($sku, $locationId) ?? 'each';
    }

    private function positive(string $qty): string
    {
        $q = trim($qty);
        // A well-formed decimal (≤4 places), and not zero — no float, matching the
        // ledger's exact-decimal discipline.
        if (preg_match('/^\d+(\.\d{1,4})?$/', $q) !== 1 || preg_match('/^0+(\.0+)?$/', $q) === 1) {
            throw new \InvalidArgumentException('A reservation quantity must be greater than zero.');
        }
        return $q;
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
