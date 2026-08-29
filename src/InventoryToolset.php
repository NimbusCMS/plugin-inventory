<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Mcp\PluginTool;
use Nimbus\Mcp\PluginToolset;

/**
 * Inventory over MCP — the agent-driven onboarding the initiative is built around.
 *
 * Six tools under the `inventory` namespace: four writes (receive, adjust, count,
 * transfer) and two reads (stock, movements). The {@see PluginToolset} base gates
 * every one on the plugin's own `nimbuscms.inventory` capability (ADR 0015/0016) —
 * a write tool needs `:write`, unreachable by a content token — so this class
 * writes no authorization code.
 *
 * Two things the handlers guarantee, per the security review:
 *  - **`actor` and `occurred_at` are server-set** — the token's name and the
 *    server clock — never read from arguments, so history can't be forged.
 *  - **Input and domain errors are returned as data**, not thrown, so a bad
 *    quantity or an oversell is a structured result the agent can act on, not a 500.
 */
final class InventoryToolset extends PluginToolset
{
    public function __construct(private Ledger $ledger, private Reservations $reservations)
    {
    }

    public function namespace(): string
    {
        return 'inventory';
    }

    protected function tools(): array
    {
        $loc  = ['type' => 'string', 'description' => 'Location code (created if new).'];
        $sku  = ['type' => 'string', 'description' => 'The SKU code (the sku entry\'s code/slug).'];
        $qty  = ['type' => 'string', 'description' => 'A decimal quantity, up to 4 places (e.g. "12" or "0.25").'];
        $uom  = ['type' => 'string', 'description' => 'Unit of measure (e.g. "each", "kg"). Optional if the SKU already has stock at the location.'];
        $note = ['type' => 'string', 'description' => 'Optional free-text note recorded on the movement.'];

        return [
            new PluginTool('receive', 'write', 'Receive stock into a location (a positive movement).', [
                'type'       => 'object',
                'required'   => ['sku', 'location', 'qty', 'uom'],
                'properties' => ['sku' => $sku, 'location' => $loc, 'qty' => $qty, 'uom' => $uom, 'reason' => ['type' => 'string', 'description' => 'Optional reason (default "receipt").'], 'ref' => ['type' => 'string', 'description' => 'Optional reference id (e.g. a PO number).'], 'note' => $note],
            ], $this->receive(...)),

            new PluginTool('adjust', 'write', 'Adjust stock by a signed amount (waste, correction, found).', [
                'type'       => 'object',
                'required'   => ['sku', 'location', 'qty'],
                'properties' => ['sku' => $sku, 'location' => $loc, 'qty' => ['type' => 'string', 'description' => 'A signed, non-zero decimal (e.g. "-3" for waste).'], 'uom' => $uom, 'reason' => ['type' => 'string', 'description' => 'Optional reason (default "adjustment").'], 'note' => $note],
            ], $this->adjust(...)),

            new PluginTool('count', 'write', 'Record a physical count: set on-hand to the counted figure (an auditable correction).', [
                'type'       => 'object',
                'required'   => ['sku', 'location', 'counted'],
                'properties' => ['sku' => $sku, 'location' => $loc, 'counted' => ['type' => 'string', 'description' => 'The counted on-hand, a decimal ≥ 0.'], 'uom' => $uom, 'note' => $note],
            ], $this->count(...)),

            new PluginTool('transfer', 'write', 'Move stock from one location to another (oversell-guarded).', [
                'type'       => 'object',
                'required'   => ['sku', 'from', 'to', 'qty'],
                'properties' => ['sku' => $sku, 'from' => ['type' => 'string', 'description' => 'Source location code.'], 'to' => ['type' => 'string', 'description' => 'Destination location code.'], 'qty' => $qty, 'uom' => $uom, 'note' => $note],
            ], $this->transfer(...)),

            new PluginTool('reserve', 'write', 'Hold stock for a SKU under a reference (a checkout). Refused if not enough is available.', [
                'type'       => 'object',
                'required'   => ['sku', 'location', 'qty', 'ref'],
                'properties' => ['sku' => $sku, 'location' => $loc, 'qty' => $qty, 'ref' => ['type' => 'string', 'description' => 'The holder\'s reference (e.g. an order-line id), used to release the hold later.']],
            ], $this->reserve(...)),

            new PluginTool('release', 'write', 'Release every hold under a reference (a cancelled or expired checkout).', [
                'type'       => 'object',
                'required'   => ['ref'],
                'properties' => ['ref' => ['type' => 'string', 'description' => 'The reference whose holds to release.']],
            ], $this->release(...)),

            new PluginTool('issue', 'write', 'Fulfil a hold: ship the quantity (a stock movement) and release its reference, together.', [
                'type'       => 'object',
                'required'   => ['sku', 'location', 'qty', 'ref'],
                'properties' => ['sku' => $sku, 'location' => $loc, 'qty' => $qty, 'ref' => ['type' => 'string', 'description' => 'The reservation reference being fulfilled.']],
            ], $this->issue(...)),

            new PluginTool('stock', 'read', 'Current on-hand and available (on-hand − reserved) for a SKU, per location.', [
                'type'       => 'object',
                'required'   => ['sku'],
                'properties' => ['sku' => $sku],
            ], $this->stock(...)),

            new PluginTool('movements', 'read', 'Recent stock movements for a SKU (the audit trail).', [
                'type'       => 'object',
                'required'   => ['sku'],
                'properties' => ['sku' => $sku, 'limit' => ['type' => 'integer', 'description' => 'Max rows (1–500, default 50).']],
            ], $this->movements(...)),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function receive(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a, $p): array {
            $sku   = $this->str($a, 'sku');
            $locId = $this->ledger->ensureLocation($this->str($a, 'location'), $this->str($a, 'location'), $this->now());
            $uom   = $this->uom($a, $sku, $locId);
            $r     = $this->ledger->receive($sku, $locId, $this->str($a, 'qty'), $uom, $this->actor($p), $this->now(), $this->strOr($a, 'reason', 'receipt'), $this->nullableStr($a, 'ref') !== null ? 'ref' : null, $this->nullableStr($a, 'ref'), $this->nullableStr($a, 'note'));
            return ['ok' => true, 'sku' => $sku, 'location' => $this->str($a, 'location'), 'on_hand' => $r['on_hand'], 'movement_id' => $r['movement_id']];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function adjust(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a, $p): array {
            $sku   = $this->str($a, 'sku');
            $locId = $this->ledger->ensureLocation($this->str($a, 'location'), $this->str($a, 'location'), $this->now());
            $uom   = $this->uom($a, $sku, $locId);
            $r     = $this->ledger->adjust($sku, $locId, $this->str($a, 'qty'), $uom, $this->actor($p), $this->now(), $this->strOr($a, 'reason', 'adjustment'), null, null, $this->nullableStr($a, 'note'));
            return ['ok' => true, 'sku' => $sku, 'location' => $this->str($a, 'location'), 'on_hand' => $r['on_hand'], 'movement_id' => $r['movement_id']];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function count(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a, $p): array {
            $sku   = $this->str($a, 'sku');
            $locId = $this->ledger->ensureLocation($this->str($a, 'location'), $this->str($a, 'location'), $this->now());
            $uom   = $this->uom($a, $sku, $locId);
            $r     = $this->ledger->count($sku, $locId, $this->str($a, 'counted'), $uom, $this->actor($p), $this->now(), $this->nullableStr($a, 'note'));
            return ['ok' => true, 'sku' => $sku, 'location' => $this->str($a, 'location'), 'on_hand' => $r['on_hand'], 'movement_id' => $r['movement_id']];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function transfer(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a, $p): array {
            $sku  = $this->str($a, 'sku');
            $from = $this->ledger->ensureLocation($this->str($a, 'from'), $this->str($a, 'from'), $this->now());
            $to   = $this->ledger->ensureLocation($this->str($a, 'to'), $this->str($a, 'to'), $this->now());
            $uom  = $this->uom($a, $sku, $from);
            $r    = $this->ledger->transfer($sku, $from, $to, $this->str($a, 'qty'), $uom, $this->actor($p), $this->now(), $this->nullableStr($a, 'note'));
            return ['ok' => true, 'sku' => $sku, 'from' => $this->str($a, 'from'), 'to' => $this->str($a, 'to'), 'on_hand_from' => $r['on_hand_from'], 'on_hand_to' => $r['on_hand_to']];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function reserve(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a): array {
            $sku   = $this->str($a, 'sku');
            $locId = $this->ledger->ensureLocation($this->str($a, 'location'), $this->str($a, 'location'), $this->now());
            $r     = $this->reservations->reserve($sku, $locId, $this->str($a, 'qty'), $this->str($a, 'ref'), $this->now());
            return ['ok' => true, 'sku' => $sku, 'location' => $this->str($a, 'location'), 'reservation_id' => $r['reservation_id'], 'available' => $r['available']];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function release(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a): array {
            $released = $this->reservations->release($this->str($a, 'ref'));
            return ['ok' => true, 'ref' => $this->str($a, 'ref'), 'released' => $released];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function issue(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        return $this->guard(function () use ($a, $p): array {
            $sku   = $this->str($a, 'sku');
            $locId = $this->ledger->ensureLocation($this->str($a, 'location'), $this->str($a, 'location'), $this->now());
            $r     = $this->reservations->issue($sku, $locId, $this->str($a, 'qty'), $this->str($a, 'ref'), $this->actor($p), $this->now());
            return ['ok' => true, 'sku' => $sku, 'location' => $this->str($a, 'location'), 'on_hand' => $r['on_hand'], 'available' => $r['available'], 'movement_id' => $r['movement_id']];
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function stock(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $sku = $this->str($a, 'sku');
        $out = [];
        foreach ($this->ledger->stockOf($sku) as $loc) {
            $out[] = [
                'location_id' => $loc['location_id'],
                'on_hand'     => $loc['on_hand'],
                'reserved'    => $this->reservations->reservedOf($sku, $loc['location_id']),
                'available'   => $this->reservations->availableOf($sku, $loc['location_id']),
                'uom'         => $loc['uom'],
            ];
        }
        return ['sku' => $sku, 'locations' => $out];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function movements(array $a, TokenPrincipal $p, EntryOpContext $c): array
    {
        $sku   = $this->str($a, 'sku');
        $limit = isset($a['limit']) && is_numeric($a['limit']) ? (int) $a['limit'] : 50;
        return ['sku' => $sku, 'movements' => $this->ledger->movementsFor($sku, $limit)];
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Run a write, turning input/domain errors into a structured result rather
     * than a 500 — the agent gets something it can correct.
     *
     * @param \Closure():array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function guard(\Closure $work): array
    {
        try {
            return $work();
        } catch (InsufficientStock $e) {
            return ['ok' => false, 'error' => 'insufficient_stock', 'message' => $e->getMessage()];
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'invalid', 'message' => $e->getMessage()];
        }
    }

    /**
     * Resolve the unit of measure: the argument, else the SKU's established one.
     *
     * @param array<string,mixed> $a
     */
    private function uom(array $a, string $sku, int $locationId): string
    {
        $given = $this->nullableStr($a, 'uom');
        if ($given !== null && $given !== '') {
            return $given;
        }
        $existing = $this->ledger->uomFor($sku, $locationId);
        if ($existing !== null) {
            return $existing;
        }
        throw new \InvalidArgumentException('This SKU has no stock at that location yet — a "uom" (unit of measure) is required.');
    }

    /** Server-set actor: the token's name, never an argument. */
    private function actor(TokenPrincipal $p): string
    {
        return $p->name;
    }

    /** Server clock, never an argument. */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $a */
    private function str(array $a, string $key): string
    {
        $v = $a[$key] ?? null;
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            throw new \InvalidArgumentException("\"{$key}\" is required.");
        }
        $s = trim((string) $v);
        if ($s === '') {
            throw new \InvalidArgumentException("\"{$key}\" is required.");
        }
        return $s;
    }

    /** @param array<string,mixed> $a */
    private function strOr(array $a, string $key, string $default): string
    {
        $v = $this->nullableStr($a, $key);
        return $v ?? $default;
    }

    /** @param array<string,mixed> $a */
    private function nullableStr(array $a, string $key): ?string
    {
        $v = $a[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
