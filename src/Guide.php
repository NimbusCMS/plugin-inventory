<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * The agent-facing guide (ADR 0013), served as an MCP resource. Reference
 * documentation, not instructions — it teaches an agent the mental model and the
 * sharp edges so it drives the tools correctly the first time.
 */
final class Guide
{
    public static function text(): string
    {
        return <<<'MD'
            # Inventory

            Stock is an **append-only ledger**, not a number you set. Every change is
            a movement; on-hand is the sum of movements. You never write a stock
            level directly — you record what happened.

            ## Model

            - A **SKU** is identified by its `sku_code` (the sku entry's code/slug).
              The catalog (items, SKUs) lives in ordinary collections — manage it
              with the content tools. Inventory only tracks quantities by `sku_code`.
            - A **location** is a named place stock sits (`shelf-a`, `main`). Passing
              a new location code creates it.
            - **On-hand** is per (SKU, location). Quantities are decimals with up to
              4 places; every movement carries a **unit of measure** (`uom`).

            ## Tools (all namespaced `inventory_`)

            - `inventory_receive` — stock coming in (a positive movement). `uom`
              required the first time a SKU is stocked at a location.
            - `inventory_adjust` — a signed correction (`-3` for waste, `+2` found).
            - `inventory_count` — record a physical count; sets on-hand to the
              counted figure by appending the correcting delta.
            - `inventory_transfer` — move between locations (refused if the source
              can't cover it).
            - `inventory_reserve` — soft-hold stock under a `ref` (an order line) so
              a checkout can promise it; refused if not enough is **available**.
            - `inventory_release` — free every hold under a `ref` (cancel/expire).
            - `inventory_issue` — fulfil a hold: ship the quantity (a real movement)
              and release its `ref`, together.
            - `inventory_stock` — on-hand, reserved, and **available** (on-hand −
              reserved) per location for a SKU.
            - `inventory_movements` — the audit trail for a SKU.

            ## Reservations

            `available = on_hand − reserved`. A hold is soft — it does not move
            stock — so a reserved item still shows in on-hand until you `issue` it.
            Reserve at checkout, release on cancel, issue on fulfilment. Two holds
            can never together exceed available.

            ## Sharp edges

            - **A decrement can be refused.** `adjust` with a negative, or a
              `transfer`, returns `{"ok": false, "error": "insufficient_stock"}` if
              on-hand won't cover it. There is no backorder yet — receive first.
            - **`uom` is sticky.** Omit it after the first receipt and the SKU's
              established unit is used. Passing a different unit does not convert.
            - **You do not set `actor` or the time** — the server records who (your
              token) and when. Don't pass them.
            - Quantities are strings so decimals stay exact: send `"0.25"`, not 0.25.

            All write tools require the `inventory:write` capability; the read tools
            require `inventory:read`.
            MD;
    }
}
