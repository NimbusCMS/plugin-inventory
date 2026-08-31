<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * The read contract Inventory publishes for a storefront to render its catalog
 * (ADR 0019 service ports; ADR 0023 themed pages). A presentation plugin (the
 * Storefront) depends on this **interface**, obtains the live one at request time
 * via `$ctx->services()->get(CatalogReadPort::class)` — `null` when no inventory
 * is installed, so it degrades to an empty catalog rather than a 500 — and never
 * touches Inventory's tables.
 *
 * Everything here is **public-safe by construction**: active items only,
 * **coarse** availability (`in_stock` / `low` / `out`, never a raw count), and no
 * cost, on-hand, reserved, or location detail. The port cannot be used to read
 * hidden items or exact stock.
 */
interface CatalogReadPort
{
    /**
     * A paginated public listing. `sort` is one of `featured|name|price_asc|
     * price_desc` (anything else → the default); `category` is a category **slug**;
     * `q` is a free-text search over name/description; `page` is 1-based.
     *
     * @param array{category?:?string,q?:?string,sort?:?string,page?:int} $filters
     * @return array{items:list<array{sku_code:string,name:string,price:string,unit:?string,description:?string,image_media_id:?int,category_id:?int,category:?string,featured:bool,availability:string}>,total:int,page:int,per_page:int,pages:int}
     */
    public function list(array $filters): array;

    /**
     * One public item by SKU, or null when it is absent **or not active** (so a
     * hidden SKU is indistinguishable from a missing one — the caller 404s both).
     *
     * @return array{sku_code:string,name:string,price:string,unit:?string,description:?string,image_media_id:?int,category_id:?int,category:?string,featured:bool,availability:string}|null
     */
    public function get(string $sku): ?array;

    /**
     * The category tree (two levels) for storefront navigation — public fields.
     *
     * @return list<array{id:int,name:string,slug:string,parent_id:?int}>
     */
    public function categories(): array;
}
