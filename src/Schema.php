<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * The plugin's own tables (ADR 0005 — prefixed away from core `nb_*`).
 *
 * Three tables realise the design's load-bearing decision: **stock is never a
 * mutable number — it is a projection over an append-only movement ledger.**
 *
 *  - `inventory_movement` — the ledger. Append-only; every stock change is a
 *    signed row with a reason and an actor. This is the source of truth.
 *  - `inventory_location` — flat named locations (v1: no hierarchy).
 *  - `inventory_stock` — a *materialised projection* of on-hand per (sku,
 *    location). A cache: rebuildable from the ledger at any time, and the test
 *    oracle is `fold(ledger) == stock`.
 *
 * Five decisions the design review made structural:
 *  1. `qty`/`on_hand` are DECIMAL, not INT — a café weighs coffee, a recipe uses
 *     0.2 kg. `uom` (unit of measure) rides each movement, denormalised.
 *  2. **No foreign key from a movement into a core `nb_*` table.** A movement
 *     carries a denormalised, immutable `sku_code` string; deleting the catalog
 *     entry can never cascade-destroy history, and the ledger never joins core.
 *  3. `reason` / `ref_type` are an **open string vocabulary**, never a DB enum —
 *     a new domain's reason must not be an Inventory migration.
 *  4. `lot_id` / `unit_id` are nullable from day one, so lots and serials slot in
 *     later without re-folding the ledger.
 *  5. Catalog (item/SKU) lives in *content* (collections), not here — the ledger
 *     references a SKU only by its opaque `sku_code`.
 */
final class Schema
{
    public const MOVEMENT    = 'inventory_movement';
    public const LOCATION    = 'inventory_location';
    public const STOCK       = 'inventory_stock';
    public const RESERVATION = 'inventory_reservation';

    /**
     * The reservation overlay (Commerce slice 1). A soft hold on stock —
     * `available = on_hand − reserved` — kept **separate** from the append-only
     * ledger: reservations are mutable (made, released, or turned into an issue),
     * so they are not movements. `ref` is the holder's key (an order line), used to
     * release the whole hold on cancel.
     *
     * @return list<string>
     */
    public static function reservations(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::RESERVATION . ' (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ref         VARCHAR(80) NOT NULL,
                sku_code    VARCHAR(80) NOT NULL,
                location_id BIGINT UNSIGNED NOT NULL,
                qty         DECIMAL(18,4) NOT NULL,
                created_at  DATETIME NOT NULL,
                INDEX idx_sku_location (sku_code, location_id),
                INDEX idx_ref (ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /** @return list<string> each statement individually idempotent (ADR 0005) */
    public static function all(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::LOCATION . ' (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code       VARCHAR(40) NOT NULL,
                name       VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_location_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS ' . self::MOVEMENT . ' (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sku_code    VARCHAR(80) NOT NULL,
                location_id BIGINT UNSIGNED NOT NULL,
                lot_id      BIGINT UNSIGNED NULL,
                unit_id     BIGINT UNSIGNED NULL,
                qty         DECIMAL(18,4) NOT NULL,
                uom         VARCHAR(16) NOT NULL,
                reason      VARCHAR(40) NOT NULL,
                ref_type    VARCHAR(40) NULL,
                ref_id      VARCHAR(80) NULL,
                actor       VARCHAR(120) NOT NULL,
                note        VARCHAR(255) NULL,
                occurred_at DATETIME NOT NULL,
                INDEX idx_sku_location (sku_code, location_id),
                INDEX idx_occurred (occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS ' . self::STOCK . ' (
                sku_code    VARCHAR(80) NOT NULL,
                location_id BIGINT UNSIGNED NOT NULL,
                on_hand     DECIMAL(18,4) NOT NULL DEFAULT 0,
                uom         VARCHAR(16) NOT NULL,
                updated_at  DATETIME NOT NULL,
                PRIMARY KEY (sku_code, location_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}
