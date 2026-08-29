<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Http\Request;
use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginContext;
use Nimbus\Plugin\PluginStorage;

/**
 * The official Inventory plugin — stock as an append-only ledger, agent-drivable
 * over MCP. The first plugin to compose the full keystone triad: it **declares a
 * capability** (ADR 0015), **exposes MCP tools** that gate on it (ADR 0016), and
 * **emits events** under its own namespace (ADR 0014) — all on its **own tables**
 * (ADR 0005), touching no core data.
 *
 * The catalog (items and SKUs) lives in *content* — ordinary collections the
 * operator or an agent creates with the schema tools — so it is editable,
 * themeable and API-native. This plugin owns only the ledger: it references a SKU
 * by its opaque `sku_code`, with no foreign key into core, so a catalog change can
 * never rewrite stock history.
 */
final class InventoryPlugin implements Plugin
{
    /** Matches extra.nimbus.id in composer.json. */
    public const ID = 'nimbuscms.inventory';

    public function register(PluginContext $context): void
    {
        $context->migrations()->register('001_ledger', Schema::all());
        $context->migrations()->register('002_reservations', Schema::reservations());

        // Grantable, wildcard-immune: nimbuscms.inventory:read / :write. Moving
        // stock is moving money — a content *:write token can never reach it.
        $context->capabilities()->declare('Inventory', ['read', 'write']);

        // Storage is taken lazily, so register() runs no query and loads without a
        // database; events emit post-commit under this plugin's namespace.
        $storage = static fn (): PluginStorage => $context->storage();
        $emit    = static function (string $name, array $payload) use ($context): void {
            $context->events()->emit($name, $payload);
        };
        $ledger       = new Ledger($storage, $emit);
        $reservations = new Reservations($storage, $ledger);

        $context->mcp()->register(new InventoryToolset($ledger, $reservations));

        // Publish the reservation contract so Commerce (or any plugin) can reserve
        // stock synchronously without touching Inventory's tables (ADR 0019).
        $context->services()->provide(ReservationPort::class, new ReservationAdapter($ledger, $reservations));

        // A read-only admin overview (stock + movements); changes go through the tools.
        $context->adminPages()->register('inventory', 'Inventory', '📦', static fn (Request $r): string => (new InventoryAdmin($storage))->render());

        // Teach any MCP agent how to drive the ledger (ADR 0013).
        $context->skills()->register('Inventory', Guide::text());
    }
}
