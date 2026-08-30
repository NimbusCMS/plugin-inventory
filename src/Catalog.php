<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Plugin\PluginStorage;

/**
 * The item master (ADR 0022) — a SKU's optional sellable attributes and a
 * lightweight two-level category taxonomy, alongside the ledger's stock.
 *
 * This is the retail counterpart to a content catalog: for goods you stock and
 * sell as-is, the SKU *is* the product. It is deliberately additive — a SKU can
 * carry stock with no item here, and an item with no stock — so a pure-ledger
 * user is unaffected.
 *
 * Every write is the security boundary the review pinned:
 *  - **Store raw, escape on render.** `name`/`description`/`unit` are kept
 *    byte-exact (no lossy pre-escape); the render layer escapes (`View::e`). So
 *    this class never HTML-encodes on the way in.
 *  - **Price is validated** to a non-negative decimal (≤2 places) — never a raw
 *    string into DECIMAL, never negative.
 *  - **Field allow-list.** A row is built only from known keys; `sku_code` is the
 *    addressed key and timestamps are server-set, so no arbitrary key can be
 *    mass-assigned.
 *  - **`image_media_id` is a soft ref** (a nullable int into core's public media
 *    library) resolved defensively at render — no FK, no traversal.
 *  - **Category integrity.** A parent must exist and be top-level (fixing depth
 *    at two and making cycles impossible); a category in use can't be deleted.
 */
final class Catalog
{
    /** @param \Closure():PluginStorage $storage resolved lazily, so construction runs no query */
    public function __construct(private \Closure $storage)
    {
    }

    // --- items -----------------------------------------------------------

    /**
     * Create or update an item by its SKU code. Reads only known fields from
     * `$fields` (the over-posting guard); unset fields keep their stored value on
     * an update, or take their column default on a create.
     *
     * @param array<string,mixed> $fields
     */
    public function saveItem(string $sku, array $fields, string $now): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('A SKU code is required.');
        }

        $existing = $this->getItem($sku);

        // Build the row from the allow-list only, defaulting to the existing row
        // (update) or the column defaults (create).
        $name        = $this->name($fields, $existing);
        $price       = $this->price($fields, $existing);
        $unit        = $this->optStr($fields, 'unit', $existing, 32);
        $description = $this->description($fields, $existing);
        $imageId     = $this->optId($fields, 'image_media_id', $existing);
        $categoryId  = $this->categoryRef($fields, $existing);
        $active      = $this->flag($fields, 'active', $existing, true);
        $featured    = $this->flag($fields, 'featured', $existing, false);

        $this->storage()->execute(
            'INSERT INTO ' . Schema::ITEM . '
                (sku_code, name, price, unit, description, image_media_id, category_id, active, featured, created_at, updated_at)
             VALUES (:sku, :name, :price, :unit, :description, :image, :category, :active, :featured, :created, :updated)
             ON DUPLICATE KEY UPDATE
                name = :name2, price = :price2, unit = :unit2, description = :description2,
                image_media_id = :image2, category_id = :category2, active = :active2,
                featured = :featured2, updated_at = :updated2',
            [
                'sku' => $sku, 'name' => $name, 'price' => $price, 'unit' => $unit,
                'description' => $description, 'image' => $imageId, 'category' => $categoryId,
                'active' => $active, 'featured' => $featured, 'created' => $now, 'updated' => $now,
                'name2' => $name, 'price2' => $price, 'unit2' => $unit, 'description2' => $description,
                'image2' => $imageId, 'category2' => $categoryId, 'active2' => $active,
                'featured2' => $featured, 'updated2' => $now,
            ],
        );
    }

    /**
     * One item by SKU, or null. Values are returned raw (unescaped) — the render
     * layer escapes; this is the source of truth, not a view.
     *
     * @return array{sku_code:string,name:string,price:string,unit:?string,description:?string,image_media_id:?int,category_id:?int,active:bool,featured:bool,created_at:string,updated_at:string}|null
     */
    public function getItem(string $sku): ?array
    {
        $row = $this->storage()->selectOne(
            'SELECT sku_code, name, price, unit, description, image_media_id, category_id, active, featured, created_at, updated_at
             FROM ' . Schema::ITEM . ' WHERE sku_code = :sku',
            ['sku' => trim($sku)],
        );
        return $row === null ? null : $this->hydrateItem($row);
    }

    /**
     * Items for the admin list, most-recent first, optionally filtered to those
     * whose SKU or name contains `$q` (bound LIKE — no string-built SQL).
     *
     * @return list<array{sku_code:string,name:string,price:string,unit:?string,description:?string,image_media_id:?int,category_id:?int,active:bool,featured:bool,created_at:string,updated_at:string}>
     */
    public function allItems(?string $q = null): array
    {
        $q      = $q !== null ? trim($q) : '';
        $where  = $q === '' ? '' : ' WHERE sku_code LIKE :q OR name LIKE :q2';
        $params = $q === '' ? [] : ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%'];

        $rows = $this->storage()->select(
            'SELECT sku_code, name, price, unit, description, image_media_id, category_id, active, featured, created_at, updated_at
             FROM ' . Schema::ITEM . $where . ' ORDER BY updated_at DESC, sku_code',
            $params,
        );
        return array_map($this->hydrateItem(...), $rows);
    }

    /** Delete an item by SKU; returns the number of rows removed (0 if none). */
    public function deleteItem(string $sku): int
    {
        return $this->storage()->execute(
            'DELETE FROM ' . Schema::ITEM . ' WHERE sku_code = :sku',
            ['sku' => trim($sku)],
        );
    }

    // --- categories ------------------------------------------------------

    /**
     * Create (id null) or rename/reparent (id given) a category. `parentId` must
     * reference an existing top-level category and may not be the category itself;
     * a category that is a parent of others cannot be given a parent (two levels).
     *
     * @return int the category id
     */
    public function saveCategory(?int $id, string $name, ?int $parentId, string $now): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A category name is required.');
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('A category name must be 120 characters or fewer.');
        }

        $parentId = $this->validParent($id, $parentId);
        $slug     = $this->uniqueSlug($this->slugify($name), $id);

        if ($id === null) {
            return $this->storage()->insert(
                'INSERT INTO ' . Schema::CATEGORY . ' (name, slug, parent_id, created_at, updated_at)
                 VALUES (:name, :slug, :parent, :created, :updated)',
                ['name' => $name, 'slug' => $slug, 'parent' => $parentId, 'created' => $now, 'updated' => $now],
            );
        }

        $affected = $this->storage()->execute(
            'UPDATE ' . Schema::CATEGORY . ' SET name = :name, slug = :slug, parent_id = :parent, updated_at = :now WHERE id = :id',
            ['name' => $name, 'slug' => $slug, 'parent' => $parentId, 'now' => $now, 'id' => $id],
        );
        if ($affected === 0 && $this->getCategory($id) === null) {
            throw new \InvalidArgumentException("No category with id {$id}.");
        }
        return $id;
    }

    /**
     * @return array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}|null
     */
    public function getCategory(int $id): ?array
    {
        $row = $this->storage()->selectOne(
            'SELECT id, name, slug, parent_id, created_at, updated_at FROM ' . Schema::CATEGORY . ' WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->hydrateCategory($row);
    }

    /**
     * Every category, ordered as a two-level tree (each top-level followed by its
     * children), for the admin list and the (later) storefront nav.
     *
     * @return list<array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}>
     */
    public function allCategories(): array
    {
        $rows = array_map(
            $this->hydrateCategory(...),
            $this->storage()->select('SELECT id, name, slug, parent_id, created_at, updated_at FROM ' . Schema::CATEGORY . ' ORDER BY name'),
        );

        $tops     = array_values(array_filter($rows, static fn (array $c): bool => $c['parent_id'] === null));
        $children = [];
        foreach ($rows as $c) {
            if ($c['parent_id'] !== null) {
                $children[$c['parent_id']][] = $c;
            }
        }

        $ordered = [];
        foreach ($tops as $top) {
            $ordered[] = $top;
            foreach ($children[$top['id']] ?? [] as $child) {
                $ordered[] = $child;
            }
        }
        return $ordered;
    }

    /**
     * Delete a category. Blocked ({@see CategoryInUse}) while a child category or
     * any item still references it — the caller reparents/reassigns first.
     */
    public function deleteCategory(int $id): void
    {
        $s = $this->storage();
        $childCount = $s->selectOne('SELECT COUNT(*) AS n FROM ' . Schema::CATEGORY . ' WHERE parent_id = :id', ['id' => $id]);
        if ($childCount !== null && (int) $childCount['n'] > 0) {
            throw new CategoryInUse($id, 'child categories');
        }
        $itemCount = $s->selectOne('SELECT COUNT(*) AS n FROM ' . Schema::ITEM . ' WHERE category_id = :id', ['id' => $id]);
        if ($itemCount !== null && (int) $itemCount['n'] > 0) {
            throw new CategoryInUse($id, 'items');
        }
        $s->execute('DELETE FROM ' . Schema::CATEGORY . ' WHERE id = :id', ['id' => $id]);
    }

    // --- validation / hydration -----------------------------------------

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function name(array $fields, ?array $existing): string
    {
        if (!array_key_exists('name', $fields)) {
            if ($existing !== null) {
                return (string) $existing['name'];
            }
            throw new \InvalidArgumentException('An item name is required.');
        }
        $name = trim((string) $fields['name']);
        if ($name === '') {
            throw new \InvalidArgumentException('An item name is required.');
        }
        if (mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('An item name must be 200 characters or fewer.');
        }
        return $name;
    }

    /**
     * A non-negative decimal with up to 2 places, validated as a string (no float,
     * no bcmath) exactly like the ledger validates quantities.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function price(array $fields, ?array $existing): string
    {
        if (!array_key_exists('price', $fields)) {
            return $existing !== null ? (string) $existing['price'] : '0.00';
        }
        $price = trim((string) $fields['price']);
        if ($price === '') {
            return '0.00';
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $price) !== 1) {
            throw new \InvalidArgumentException("\"{$price}\" is not a valid price (a non-negative amount with up to 2 decimal places).");
        }
        return $price;
    }

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function description(array $fields, ?array $existing): ?string
    {
        if (!array_key_exists('description', $fields)) {
            return $existing !== null ? ($existing['description'] === null ? null : (string) $existing['description']) : null;
        }
        // Plain text v1 (no HTML): stored raw and byte-exact — the render layer
        // escapes. Capped to a sane length so a write can't be used to bloat a row.
        $desc = (string) $fields['description'];
        if (mb_strlen($desc) > 5000) {
            throw new \InvalidArgumentException('A description must be 5000 characters or fewer.');
        }
        $desc = trim($desc);
        return $desc === '' ? null : $desc;
    }

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function optStr(array $fields, string $key, ?array $existing, int $max): ?string
    {
        if (!array_key_exists($key, $fields)) {
            return $existing !== null ? ($existing[$key] === null ? null : (string) $existing[$key]) : null;
        }
        $v = trim((string) $fields[$key]);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new \InvalidArgumentException("\"{$key}\" must be {$max} characters or fewer.");
        }
        return $v;
    }

    /**
     * A nullable positive id (image media ref). Empty/absent → null; a
     * non-positive or non-numeric value is rejected rather than silently stored.
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function optId(array $fields, string $key, ?array $existing): ?int
    {
        if (!array_key_exists($key, $fields)) {
            return $existing !== null ? ($existing[$key] === null ? null : (int) $existing[$key]) : null;
        }
        $raw = trim((string) $fields[$key]);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            throw new \InvalidArgumentException("\"{$key}\" must be a positive whole number or blank.");
        }
        return (int) $raw;
    }

    /**
     * The category a new/updated item points at: null, or an id that must exist in
     * this install (a soft ref, checked at write so a dangling id can't be stored).
     *
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function categoryRef(array $fields, ?array $existing): ?int
    {
        $id = $this->optId($fields, 'category_id', $existing);
        if ($id !== null && $this->getCategory($id) === null) {
            throw new \InvalidArgumentException("No category with id {$id}.");
        }
        return $id;
    }

    /**
     * @param array<string,mixed>      $fields
     * @param array<string,mixed>|null $existing
     */
    private function flag(array $fields, string $key, ?array $existing, bool $default): int
    {
        if (!array_key_exists($key, $fields)) {
            if ($existing !== null) {
                return (bool) $existing[$key] ? 1 : 0;
            }
            return $default ? 1 : 0;
        }
        return $this->truthy($fields[$key]) ? 1 : 0;
    }

    private function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
        }
        return false;
    }

    /**
     * Resolve and validate a proposed parent for category `$id` (null on create):
     * it must exist, be top-level, and not be the category itself.
     */
    private function validParent(?int $id, ?int $parentId): ?int
    {
        if ($parentId === null) {
            return null;
        }
        if ($id !== null && $parentId === $id) {
            throw new \InvalidArgumentException('A category cannot be its own parent.');
        }
        $parent = $this->getCategory($parentId);
        if ($parent === null) {
            throw new \InvalidArgumentException("No category with id {$parentId} to be a parent.");
        }
        if ($parent['parent_id'] !== null) {
            throw new \InvalidArgumentException('Categories are only two levels deep — the chosen parent is already a child.');
        }
        // A category that already has children can't itself become a child.
        if ($id !== null) {
            $hasChildren = $this->storage()->selectOne('SELECT COUNT(*) AS n FROM ' . Schema::CATEGORY . ' WHERE parent_id = :id', ['id' => $id]);
            if ($hasChildren !== null && (int) $hasChildren['n'] > 0) {
                throw new \InvalidArgumentException('This category has children, so it must stay top-level.');
            }
        }
        return $parentId;
    }

    /** Lowercase, allow-list to `[a-z0-9-]`, collapse and trim dashes. */
    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug === '' ? 'category' : $slug;
    }

    /** Ensure the slug is unique (excluding this category), suffixing -2, -3, … */
    private function uniqueSlug(string $base, ?int $excludeId): string
    {
        $slug = $base;
        $n    = 1;
        while (true) {
            $row = $this->storage()->selectOne(
                'SELECT id FROM ' . Schema::CATEGORY . ' WHERE slug = :slug' . ($excludeId !== null ? ' AND id <> :id' : ''),
                $excludeId !== null ? ['slug' => $slug, 'id' => $excludeId] : ['slug' => $slug],
            );
            if ($row === null) {
                return $slug;
            }
            $n++;
            $slug = $base . '-' . $n;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array{sku_code:string,name:string,price:string,unit:?string,description:?string,image_media_id:?int,category_id:?int,active:bool,featured:bool,created_at:string,updated_at:string}
     */
    private function hydrateItem(array $row): array
    {
        return [
            'sku_code'       => (string) $row['sku_code'],
            'name'           => (string) $row['name'],
            'price'          => (string) $row['price'],
            'unit'           => $row['unit'] === null ? null : (string) $row['unit'],
            'description'    => $row['description'] === null ? null : (string) $row['description'],
            'image_media_id' => $row['image_media_id'] === null ? null : (int) $row['image_media_id'],
            'category_id'    => $row['category_id'] === null ? null : (int) $row['category_id'],
            'active'         => (bool) $row['active'],
            'featured'       => (bool) $row['featured'],
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}
     */
    private function hydrateCategory(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'slug'       => (string) $row['slug'],
            'parent_id'  => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function storage(): PluginStorage
    {
        return ($this->storage)();
    }
}
