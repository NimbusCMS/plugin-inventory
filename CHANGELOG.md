# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project aims to
follow semantic versioning once it reaches 1.0.

## [Unreleased]

### Added

- **Reservation overlay** (the groundwork for Commerce): `available = on_hand −
  reserved`, with `inventory_reserve` / `inventory_release` / `inventory_issue`
  tools and available/reserved shown by `inventory_stock`. A hold never exceeds
  available (serialised on the stock row), and issuing ships + releases atomically.
  New `inventory_reservation` table (migration `002_reservations`).
- Initial release: ledger-based inventory for NimbusCMS.
  - An append-only movement ledger (`inventory_movement`) with a rebuildable
    on-hand projection (`inventory_stock`) and flat locations (`inventory_location`)
    — the invariant `fold(ledger) == projection` is enforced in one transaction per
    operation.
  - MCP tools: `inventory_receive`, `inventory_adjust`, `inventory_count`,
    `inventory_transfer`, `inventory_stock`, `inventory_movements`, gated on the
    plugin's own `inventory:read` / `inventory:write` capability.
  - Events: `nimbuscms.inventory.recorded` on every movement and
    `nimbuscms.inventory.depleted` when a location reaches zero.
  - An agent guide served over MCP.
  - Decimal quantities with a per-movement unit of measure; oversell refused
    (no backorder in v1); `actor` and time recorded server-side; `lot_id` /
    `unit_id` reserved (nullable) for later.
