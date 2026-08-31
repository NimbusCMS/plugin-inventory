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
 * A SKU may **optionally** carry a sellable *item* record here — name, price,
 * category, unit, image, flags — the retail counterpart to a content catalog, for
 * goods you stock and sell as-is (ADR 0022, {@see Catalog}). It is additive: the
 * ledger still references a SKU only by its opaque `sku_code` with no foreign key
 * into core (a catalog change never rewrites stock history), a SKU can carry stock
 * with no item, and a pure-ledger user is untouched. Editorial catalogs (a menu, a
 * showcase) still live in content collections; the item master is for operational
 * stock you sell.
 */
final class InventoryPlugin implements Plugin
{
    /** Matches extra.nimbus.id in composer.json. */
    public const ID = 'nimbuscms.inventory';

    public function register(PluginContext $context): void
    {
        $context->migrations()->register('001_ledger', Schema::all());
        $context->migrations()->register('002_reservations', Schema::reservations());
        $context->migrations()->register('003_items', Schema::items());

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
        $catalog      = new Catalog($storage);

        $context->mcp()->register(new InventoryToolset($ledger, $reservations, $catalog));

        // Publish the reservation contract so Commerce (or any plugin) can reserve
        // stock synchronously without touching Inventory's tables (ADR 0019).
        $context->services()->provide(ReservationPort::class, new ReservationAdapter($ledger, $reservations));

        // Publish the public catalog read contract (ADR 0023) so a storefront can
        // render items — active-only, coarse availability — without touching tables.
        $context->services()->provide(CatalogReadPort::class, new CatalogReadAdapter($catalog));

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

        // Catalog admin (ADR 0022): manage the sellable item behind each SKU and the
        // category taxonomy. Its own page so merchandising stays separate from the
        // stock workbench; same `nimbuscms.inventory:write` gate + CSRF as the ledger.
        $context->adminPages()->register(
            'catalog',
            'Catalog',
            '📇',
            static fn (Request $r, string $nonce = '', string $csrf = ''): string => (new CatalogAdmin($catalog))->render($csrf, $r->query('ok') ?? $r->query('err'), $r->query('edit'), $r->query('editcat'), $nonce),
            self::ID . ':write',
        );
        $context->adminPages()->action('catalog', 'item-save', static function (Request $r) use ($catalog): Response {
            $sku = trim((string) ($r->input('sku') ?? ''));
            if ($sku === '') {
                return Response::redirect('/admin/catalog?err=invalid');
            }
            // Build the field set from a known allow-list only — never the raw
            // request — so no unexpected key (a forged flag/timestamp) is assigned.
            $fields = [
                'name'           => (string) ($r->input('name') ?? ''),
                'price'          => (string) ($r->input('price') ?? ''),
                'unit'           => (string) ($r->input('unit') ?? ''),
                'description'    => (string) ($r->input('description') ?? ''),
                'image_media_id' => (string) ($r->input('image_media_id') ?? ''),
                'category_id'    => (string) ($r->input('category_id') ?? ''),
                'active'         => $r->input('active') !== null,
                'featured'       => $r->input('featured') !== null,
            ];
            try {
                $catalog->saveItem($sku, $fields, date('Y-m-d H:i:s'));
                return Response::redirect('/admin/catalog?ok=item-saved');
            } catch (\InvalidArgumentException $e) {
                return Response::redirect('/admin/catalog?err=' . (str_contains($e->getMessage(), 'price') ? 'badprice' : 'invalid'));
            } catch (\Throwable) {
                return Response::redirect('/admin/catalog?err=invalid');
            }
        });
        $context->adminPages()->action('catalog', 'item-delete', static function (Request $r) use ($catalog): Response {
            $sku = trim((string) ($r->input('sku') ?? ''));
            if ($sku !== '') {
                $catalog->deleteItem($sku);
            }
            return Response::redirect('/admin/catalog?ok=item-deleted');
        });
        $context->adminPages()->action('catalog', 'category-save', static function (Request $r) use ($catalog): Response {
            $name = trim((string) ($r->input('name') ?? ''));
            $idIn = trim((string) ($r->input('id') ?? ''));
            $parIn = trim((string) ($r->input('parent_id') ?? ''));
            $id     = ($idIn !== '' && ctype_digit($idIn)) ? (int) $idIn : null;
            $parent = ($parIn !== '' && ctype_digit($parIn)) ? (int) $parIn : null;
            try {
                $catalog->saveCategory($id, $name, $parent, date('Y-m-d H:i:s'));
                return Response::redirect('/admin/catalog?ok=cat-saved');
            } catch (\InvalidArgumentException) {
                return Response::redirect('/admin/catalog?err=badcat');
            } catch (\Throwable) {
                return Response::redirect('/admin/catalog?err=invalid');
            }
        });
        $context->adminPages()->action('catalog', 'category-delete', static function (Request $r) use ($catalog): Response {
            $idIn = trim((string) ($r->input('id') ?? ''));
            if ($idIn === '' || !ctype_digit($idIn)) {
                return Response::redirect('/admin/catalog?err=invalid');
            }
            try {
                $catalog->deleteCategory((int) $idIn);
                return Response::redirect('/admin/catalog?ok=cat-deleted');
            } catch (CategoryInUse) {
                return Response::redirect('/admin/catalog?err=cat-inuse');
            } catch (\Throwable) {
                return Response::redirect('/admin/catalog?err=invalid');
            }
        });

        // Teach any MCP agent how to drive the ledger (ADR 0013).
        $context->skills()->register('Inventory', Guide::text());
    }
}
