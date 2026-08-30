<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
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

        // Admin page: an overview plus the receive/adjust/count/transfer forms (H3).
        // Gated on this plugin's own wildcard-immune capability (ADR 0020) — moving
        // stock in the UI needs `nimbuscms.inventory:write`, exactly like the MCP
        // tools, so a content-only editor can't. The handler gets a CSRF token (3rd
        // arg) for the forms and shows the redirect notice.
        $context->adminPages()->register(
            'inventory',
            'Inventory',
            '📦',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new InventoryAdmin($storage))->render($csrf, $r->query('ok') ?? $r->query('err'), $r->query('q'), $r->query('sku'), $nonce),
            self::ID . ':write',
        );
        $context->adminPages()->action('inventory', 'receive', static function (Request $r) use ($ledger): Response {
            $sku = trim((string) ($r->input('sku') ?? ''));
            $qty = trim((string) ($r->input('qty') ?? ''));
            if ($sku === '' || $qty === '') {
                return Response::redirect('/admin/inventory?err=invalid');
            }
            $loc = trim((string) ($r->input('location') ?? '')) ?: 'main';
            $uom = trim((string) ($r->input('uom') ?? '')) ?: 'each';
            try {
                $now = date('Y-m-d H:i:s');
                $ledger->receive($sku, $ledger->ensureLocation($loc, $loc, $now), $qty, $uom, 'admin-ui', $now);
                return Response::redirect('/admin/inventory?ok=received');
            } catch (\InvalidArgumentException) {
                return Response::redirect('/admin/inventory?err=badqty');
            } catch (\Throwable) {
                return Response::redirect('/admin/inventory?err=invalid');
            }
        });
        $context->adminPages()->action('inventory', 'adjust', static function (Request $r) use ($ledger): Response {
            $sku = trim((string) ($r->input('sku') ?? ''));
            $qty = trim((string) ($r->input('qty') ?? ''));
            if ($sku === '' || $qty === '') {
                return Response::redirect('/admin/inventory?err=invalid');
            }
            $loc    = trim((string) ($r->input('location') ?? '')) ?: 'main';
            $reason = trim((string) ($r->input('reason') ?? '')) ?: 'adjustment';
            try {
                $now   = date('Y-m-d H:i:s');
                $locId = $ledger->ensureLocation($loc, $loc, $now);
                $ledger->adjust($sku, $locId, $qty, $ledger->uomFor($sku, $locId) ?? 'each', 'admin-ui', $now, $reason);
                return Response::redirect('/admin/inventory?ok=adjusted');
            } catch (InsufficientStock) {
                return Response::redirect('/admin/inventory?err=short');
            } catch (\InvalidArgumentException) {
                return Response::redirect('/admin/inventory?err=badqty');
            } catch (\Throwable) {
                return Response::redirect('/admin/inventory?err=invalid');
            }
        });
        $context->adminPages()->action('inventory', 'count', static function (Request $r) use ($ledger): Response {
            $sku     = trim((string) ($r->input('sku') ?? ''));
            $counted = trim((string) ($r->input('qty') ?? ''));
            if ($sku === '' || $counted === '') {
                return Response::redirect('/admin/inventory?err=invalid');
            }
            $loc = trim((string) ($r->input('location') ?? '')) ?: 'main';
            try {
                $now   = date('Y-m-d H:i:s');
                $locId = $ledger->ensureLocation($loc, $loc, $now);
                $ledger->count($sku, $locId, $counted, $ledger->uomFor($sku, $locId) ?? 'each', 'admin-ui', $now);
                return Response::redirect('/admin/inventory?ok=counted');
            } catch (\InvalidArgumentException) {
                return Response::redirect('/admin/inventory?err=badqty');
            } catch (\Throwable) {
                return Response::redirect('/admin/inventory?err=invalid');
            }
        });
        $context->adminPages()->action('inventory', 'transfer', static function (Request $r) use ($ledger): Response {
            $sku  = trim((string) ($r->input('sku') ?? ''));
            $qty  = trim((string) ($r->input('qty') ?? ''));
            $from = trim((string) ($r->input('from') ?? ''));
            $to   = trim((string) ($r->input('to') ?? ''));
            if ($sku === '' || $qty === '' || $from === '' || $to === '') {
                return Response::redirect('/admin/inventory?err=invalid');
            }
            if ($from === $to) {
                return Response::redirect('/admin/inventory?err=samelocation');
            }
            try {
                $now    = date('Y-m-d H:i:s');
                $fromId = $ledger->ensureLocation($from, $from, $now);
                $toId   = $ledger->ensureLocation($to, $to, $now);
                $ledger->transfer($sku, $fromId, $toId, $qty, $ledger->uomFor($sku, $fromId) ?? 'each', 'admin-ui', $now);
                return Response::redirect('/admin/inventory?ok=transferred');
            } catch (InsufficientStock) {
                return Response::redirect('/admin/inventory?err=short');
            } catch (\InvalidArgumentException) {
                return Response::redirect('/admin/inventory?err=badqty');
            } catch (\Throwable) {
                return Response::redirect('/admin/inventory?err=invalid');
            }
        });

        // Teach any MCP agent how to drive the ledger (ADR 0013).
        $context->skills()->register('Inventory', Guide::text());
    }
}
