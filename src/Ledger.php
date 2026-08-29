<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Plugin\PluginStorage;

/**
 * The ledger — the one place a stock change happens, and the reason stock is
 * trustworthy.
 *
 * Every operation does exactly two things, **in one transaction**: append an
 * immutable row to {@see Schema::MOVEMENT} (the source of truth), and fold that
 * delta into the {@see Schema::STOCK} projection. If either half fails, both roll
 * back, so the cache can never disagree with the ledger — the invariant a test
 * asserts as `fold(ledger) == stock`.
 *
 * Decrements (issue, transfer-out) update the projection with a **conditional
 * guard** — the row changes only if `on_hand` still covers the amount — so two
 * concurrent issues cannot oversell: the loser sees zero affected rows and the
 * whole movement is rejected ({@see InsufficientStock}), ledger row included.
 *
 * `actor` and `occurred_at` are passed in by the caller and are **server-set**
 * there (the token's name, the server clock) — never taken from tool arguments,
 * so an agent cannot forge who did what or when.
 */
final class Ledger
{
    /**
     * @param \Closure():PluginStorage           $storage resolved lazily, so construction runs no query
     * @param ?\Closure(string,array<string,mixed>):void $emit    emits a namespaced plugin event (H1),
     *                                                            post-commit; null in tests
     */
    public function __construct(private \Closure $storage, private ?\Closure $emit = null)
    {
    }

    /**
     * Receive stock in (a positive movement). Creates the projection row if this
     * is the SKU's first movement at the location.
     *
     * @return array{movement_id:int,on_hand:string}
     */
    public function receive(string $sku, int $locationId, string $qty, string $uom, string $actor, string $occurredAt, string $reason = 'receipt', ?string $refType = null, ?string $refId = null, ?string $note = null): array
    {
        $r = $this->apply($sku, $locationId, $this->positive($qty), $uom, $reason, $refType, $refId, $actor, $note, $occurredAt);
        $this->announce($sku, $locationId, $r['on_hand'], $reason);
        return $r;
    }

    /**
     * Adjust stock by a signed amount (waste, correction, found). A negative
     * adjustment is guarded against oversell like an issue.
     *
     * @return array{movement_id:int,on_hand:string}
     */
    public function adjust(string $sku, int $locationId, string $qty, string $uom, string $actor, string $occurredAt, string $reason = 'adjustment', ?string $refType = null, ?string $refId = null, ?string $note = null): array
    {
        $r = $this->apply($sku, $locationId, $this->signed($qty), $uom, $reason, $refType, $refId, $actor, $note, $occurredAt);
        $this->announce($sku, $locationId, $r['on_hand'], $reason);
        return $r;
    }

    /**
     * Record a physical count: set on-hand to the counted figure by appending the
     * correcting delta, so the count is itself an auditable movement. Serialised
     * on the projection row so a concurrent movement can't slip between the read
     * and the correction.
     *
     * @return array{movement_id:int,on_hand:string}
     */
    public function count(string $sku, int $locationId, string $counted, string $uom, string $actor, string $occurredAt, ?string $note = null): array
    {
        $counted = $this->positive($counted);

        $movementId = $this->storage()->transaction(function () use ($sku, $locationId, $counted, $uom, $actor, $occurredAt, $note): int {
            $s       = $this->storage();
            $current = $this->lockedOnHand($s, $sku, $locationId);

            // The correcting delta (counted − current) is computed by MySQL, exactly
            // — no float, no bcmath — so the count is itself an auditable movement.
            $id = $s->insert(
                'INSERT INTO ' . Schema::MOVEMENT . ' (sku_code, location_id, qty, uom, reason, ref_type, ref_id, actor, note, occurred_at)
                 VALUES (:sku, :loc, (CAST(:counted AS DECIMAL(18,4)) - CAST(:current AS DECIMAL(18,4))), :uom, :reason, :ref_type, NULL, :actor, :note, :now)',
                ['sku' => $sku, 'loc' => $locationId, 'counted' => $counted, 'current' => $current, 'uom' => $uom, 'reason' => 'count', 'ref_type' => 'count', 'actor' => $actor, 'note' => $note, 'now' => $occurredAt],
            );
            $s->execute(
                'INSERT INTO ' . Schema::STOCK . ' (sku_code, location_id, on_hand, uom, updated_at)
                 VALUES (:sku, :loc, :counted, :uom, :now)
                 ON DUPLICATE KEY UPDATE on_hand = :counted2, updated_at = :now2',
                ['sku' => $sku, 'loc' => $locationId, 'counted' => $counted, 'uom' => $uom, 'now' => $occurredAt, 'counted2' => $counted, 'now2' => $occurredAt],
            );

            return $id;
        });

        $onHand = $this->onHand($sku, $locationId); // the canonical DECIMAL(18,4) value
        $this->announce($sku, $locationId, $onHand, 'count');
        return ['movement_id' => $movementId, 'on_hand' => $onHand];
    }

    /**
     * Move stock between two locations: one issue, one receipt, atomically. The
     * issue is oversell-guarded; if the source can't cover it, nothing moves.
     *
     * @return array{movement_id_out:int,movement_id_in:int,on_hand_from:string,on_hand_to:string}
     */
    public function transfer(string $sku, int $fromLocationId, int $toLocationId, string $qty, string $uom, string $actor, string $occurredAt, ?string $note = null): array
    {
        $qty = $this->positive($qty);

        /** @var array{movement_id_out:int,movement_id_in:int,on_hand_from:string,on_hand_to:string} $result */
        $result = $this->storage()->transaction(function () use ($sku, $fromLocationId, $toLocationId, $qty, $uom, $actor, $occurredAt, $note): array {
            $out = $this->applyWithin($sku, $fromLocationId, '-' . $qty, $uom, 'transfer', 'transfer', (string) $toLocationId, $actor, $note, $occurredAt);
            $in  = $this->applyWithin($sku, $toLocationId, $qty, $uom, 'transfer', 'transfer', (string) $fromLocationId, $actor, $note, $occurredAt);

            return [
                'movement_id_out' => $out['movement_id'],
                'movement_id_in'  => $in['movement_id'],
                'on_hand_from'    => $out['on_hand'],
                'on_hand_to'      => $in['on_hand'],
            ];
        });

        $this->announce($sku, $fromLocationId, $result['on_hand_from'], 'transfer');
        $this->announce($sku, $toLocationId, $result['on_hand_to'], 'transfer');
        return $result;
    }

    /** Current on-hand for a SKU at a location (0 if it has never moved there). */
    public function onHand(string $sku, int $locationId): string
    {
        $row = $this->storage()->selectOne(
            'SELECT on_hand FROM ' . Schema::STOCK . ' WHERE sku_code = :sku AND location_id = :loc',
            ['sku' => $sku, 'loc' => $locationId],
        );
        return $row === null ? '0.0000' : (string) $row['on_hand'];
    }

    /**
     * Every location's on-hand for a SKU, from the projection.
     *
     * @return list<array{location_id:int,on_hand:string,uom:string}>
     */
    public function stockOf(string $sku): array
    {
        $rows = $this->storage()->select(
            'SELECT location_id, on_hand, uom FROM ' . Schema::STOCK . ' WHERE sku_code = :sku ORDER BY location_id',
            ['sku' => $sku],
        );
        return array_map(
            static fn (array $r): array => ['location_id' => (int) $r['location_id'], 'on_hand' => (string) $r['on_hand'], 'uom' => (string) $r['uom']],
            $rows,
        );
    }

    /**
     * The most recent movements for a SKU — the audit trail.
     *
     * @return list<array<string,mixed>>
     */
    public function movementsFor(string $sku, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return $this->storage()->select(
            'SELECT id, location_id, lot_id, unit_id, qty, uom, reason, ref_type, ref_id, actor, note, occurred_at
             FROM ' . Schema::MOVEMENT . ' WHERE sku_code = :sku ORDER BY id DESC LIMIT ' . $limit,
            ['sku' => $sku],
        );
    }

    /**
     * Fold the whole ledger into per-(sku,location) on-hand — the definition the
     * projection is a cache of. The test oracle: this must equal {@see Schema::STOCK}.
     *
     * @return array<string,string> "{sku}\0{location}" => summed on_hand
     */
    public function foldLedger(): array
    {
        $rows = $this->storage()->select(
            'SELECT sku_code, location_id, SUM(qty) AS on_hand FROM ' . Schema::MOVEMENT . ' GROUP BY sku_code, location_id',
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['sku_code'] . "\0" . $r['location_id']] = (string) $r['on_hand'];
        }
        return $out;
    }

    /** The unit of measure a SKU was last stocked in at a location, if any. */
    public function uomFor(string $sku, int $locationId): ?string
    {
        $row = $this->storage()->selectOne(
            'SELECT uom FROM ' . Schema::STOCK . ' WHERE sku_code = :sku AND location_id = :loc',
            ['sku' => $sku, 'loc' => $locationId],
        );
        return $row === null ? null : (string) $row['uom'];
    }

    /** Find or create a location by code; returns its id. */
    public function ensureLocation(string $code, string $name, string $now): int
    {
        $s   = $this->storage();
        $row = $s->selectOne('SELECT id FROM ' . Schema::LOCATION . ' WHERE code = :code', ['code' => $code]);
        if ($row !== null) {
            return (int) $row['id'];
        }
        return $s->insert(
            'INSERT INTO ' . Schema::LOCATION . ' (code, name, created_at) VALUES (:code, :name, :now)',
            ['code' => $code, 'name' => $name, 'now' => $now],
        );
    }

    // --- internals -------------------------------------------------------

    /**
     * Append + project, wrapped in its own transaction (the single-operation case).
     *
     * @return array{movement_id:int,on_hand:string}
     */
    private function apply(string $sku, int $locationId, string $qty, string $uom, string $reason, ?string $refType, ?string $refId, string $actor, ?string $note, string $occurredAt): array
    {
        return $this->storage()->transaction(fn (): array => $this->applyWithin($sku, $locationId, $qty, $uom, $reason, $refType, $refId, $actor, $note, $occurredAt));
    }

    /**
     * Append one movement and fold it into the projection. Assumes it is already
     * inside a transaction (single ops wrap it; transfer batches two).
     *
     * @return array{movement_id:int,on_hand:string}
     */
    private function applyWithin(string $sku, int $locationId, string $qty, string $uom, string $reason, ?string $refType, ?string $refId, string $actor, ?string $note, string $occurredAt): array
    {
        $s  = $this->storage();
        $id = $this->append($s, $sku, $locationId, $qty, $uom, $reason, $refType, $refId, $actor, $note, $occurredAt);

        if ($this->sign($qty) < 0) {
            // Decrement: change the row only if it still covers the amount. Zero
            // affected rows means either no such stock or not enough — reject, and
            // the transaction rolls the ledger row back with it.
            $affected = $s->execute(
                'UPDATE ' . Schema::STOCK . '
                 SET on_hand = on_hand + :qty, updated_at = :now
                 WHERE sku_code = :sku AND location_id = :loc AND on_hand + :qty2 >= 0',
                ['qty' => $qty, 'now' => $occurredAt, 'sku' => $sku, 'loc' => $locationId, 'qty2' => $qty],
            );
            if ($affected === 0) {
                throw new InsufficientStock($sku, $locationId);
            }
        } else {
            $s->execute(
                'INSERT INTO ' . Schema::STOCK . ' (sku_code, location_id, on_hand, uom, updated_at)
                 VALUES (:sku, :loc, :qty, :uom, :now)
                 ON DUPLICATE KEY UPDATE on_hand = on_hand + :qty2, updated_at = :now2',
                ['sku' => $sku, 'loc' => $locationId, 'qty' => $qty, 'uom' => $uom, 'now' => $occurredAt, 'qty2' => $qty, 'now2' => $occurredAt],
            );
        }

        return ['movement_id' => $id, 'on_hand' => $this->onHand($sku, $locationId)];
    }

    private function append(PluginStorage $s, string $sku, int $locationId, string $qty, string $uom, string $reason, ?string $refType, ?string $refId, string $actor, ?string $note, string $occurredAt): int
    {
        return $s->insert(
            'INSERT INTO ' . Schema::MOVEMENT . ' (sku_code, location_id, qty, uom, reason, ref_type, ref_id, actor, note, occurred_at)
             VALUES (:sku, :loc, :qty, :uom, :reason, :ref_type, :ref_id, :actor, :note, :now)',
            [
                'sku' => $sku, 'loc' => $locationId, 'qty' => $qty, 'uom' => $uom, 'reason' => $reason,
                'ref_type' => $refType, 'ref_id' => $refId, 'actor' => $actor, 'note' => $note, 'now' => $occurredAt,
            ],
        );
    }

    /** On-hand under a row lock, for read-modify-write (count). */
    private function lockedOnHand(PluginStorage $s, string $sku, int $locationId): string
    {
        $row = $s->selectOne(
            'SELECT on_hand FROM ' . Schema::STOCK . ' WHERE sku_code = :sku AND location_id = :loc FOR UPDATE',
            ['sku' => $sku, 'loc' => $locationId],
        );
        return $row === null ? '0.0000' : (string) $row['on_hand'];
    }

    /** A quantity that must be > 0 (receive/count/transfer amounts). */
    private function positive(string $qty): string
    {
        if ($this->sign($qty) <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }
        return $this->normalize($qty);
    }

    /** A signed, non-zero quantity (adjustments). */
    private function signed(string $qty): string
    {
        if ($this->sign($qty) === 0) {
            throw new \InvalidArgumentException('An adjustment must be a non-zero quantity.');
        }
        return $this->normalize($qty);
    }

    /**
     * The sign of a decimal string, without floats or bcmath: -1, 0, or +1.
     * Validates the shape first (up to 4 places, matching the column), so a
     * non-numeric argument is rejected rather than silently treated as zero.
     */
    private function sign(string $qty): int
    {
        $n      = $this->normalize($qty);
        $digits = ltrim($n, '-');
        if (preg_match('/^0(\.0+)?$/', $digits) === 1) {
            return 0;
        }
        return $n[0] === '-' ? -1 : 1;
    }

    /** Validate a signed decimal (≤4 places) and canonicalise `-0` to `0`. */
    private function normalize(string $qty): string
    {
        $q = trim($qty);
        if (preg_match('/^-?\d+(\.\d{1,4})?$/', $q) !== 1) {
            throw new \InvalidArgumentException("\"{$qty}\" is not a valid quantity (a decimal with up to 4 places).");
        }
        return $q;
    }

    /**
     * Emit the post-commit stock events (H1) — `inventory.recorded` for every
     * movement, and `inventory.depleted` when a location hits zero, for a
     * notifications/reorder plugin to consume. Best-effort by the dispatcher; a
     * listener can't fail the write that already committed.
     */
    private function announce(string $sku, int $locationId, string $onHand, string $reason): void
    {
        if ($this->emit === null) {
            return;
        }
        $payload = ['sku_code' => $sku, 'location_id' => $locationId, 'on_hand' => $onHand, 'reason' => $reason];
        ($this->emit)('recorded', $payload);
        if ($this->sign($onHand) === 0) {
            ($this->emit)('depleted', $payload);
        }
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
