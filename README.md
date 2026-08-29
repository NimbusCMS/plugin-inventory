# NimbusCMS Inventory

Ledger-based inventory for [NimbusCMS](https://github.com/NimbusCMS/nimbus). An
official plugin — and the first to compose the full plugin **keystone**: it
declares a capability, exposes MCP tools that gate on it, and emits events, all on
its own tables, touching no core data.

## The idea: stock is a ledger, not a number

On-hand is **never a value you set** — it is the sum of an append-only log of
movements. Receiving, adjusting, counting and transferring all *append* a row; the
current level is a projection over that log. So you always have the full, auditable
history of how a SKU reached the number it shows, and the number can be rebuilt
from the ledger at any time. (`fold(ledger) == on-hand` is a tested invariant.)

The **catalog** — items and SKUs — lives in ordinary Nimbus content (collections),
so it is editable, themeable and API-native like everything else. Inventory tracks
quantities by a SKU's `sku_code`, with no foreign key into core: a catalog change
can never rewrite stock history.

## Agent-first

Every operation is an MCP tool, so an agent can run a stock take, receive a
delivery, or reconcile a count over the same interface a human uses — the
onboarding path this plugin is built around. The tools carry an agent guide (served
as an MCP resource) that teaches the model the model and the sharp edges.

| Tool | Needs | Does |
| --- | --- | --- |
| `inventory_receive` | `inventory:write` | Stock in (a positive movement). |
| `inventory_adjust` | `inventory:write` | A signed correction (waste, found). |
| `inventory_count` | `inventory:write` | Record a physical count as a correcting movement. |
| `inventory_transfer` | `inventory:write` | Move between locations (oversell-guarded). |
| `inventory_stock` | `inventory:read` | Current on-hand per location. |
| `inventory_movements` | `inventory:read` | The audit trail for a SKU. |

## Security

`inventory:write` is a **management** capability — the broad content `*:write`
wildcard can never reach it, so a "write all my content" token cannot move stock
(stock is money). The tools set the actor (your token) and the time server-side,
so history can't be forged; decrements are refused rather than allowed to go
negative (no backorder yet); and every write is one transaction, so the projection
can never disagree with the ledger.

## Install

```
composer require nimbuscms/inventory
```

Nimbus discovers it automatically. Run migrations (`nimbus migrate`) to create the
plugin's tables, then grant `Inventory` to a role (Admin → Roles) or mint a token
scoped to it.

## Not in v1 (by design)

Reservations / available-to-promise and backorder (they arrive with Commerce, the
real consumer of a reservation overlay); lots, expiry and serial units (the columns
are reserved — `lot_id` / `unit_id` are nullable from day one — so they slot in
without re-folding); suppliers, receipts, reorder rules, valuation and location
hierarchies. v1 is the ledger done right, not a warehouse-management chart.

## Development

Requires a MySQL test database (the tests provision their own tables). With the
Nimbus core checked out alongside:

```
composer install
composer check   # phpstan + phpunit
```

MIT licensed.
