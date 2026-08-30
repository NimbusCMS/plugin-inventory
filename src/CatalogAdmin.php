<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * The Catalog admin page (ADR 0022) — manage the sellable *item* record for a SKU
 * (name, price, category, unit, image, flags) and the two-level category
 * taxonomy. The merchandising counterpart to the stock workbench: kept on its own
 * page so the ops view (on-hand/movements) and the catalog view stay focused, and
 * each reads cleanly on a phone.
 *
 * Every author value is escaped on the way out ({@see e}); the store keeps it raw
 * (ADR 0022 — store raw, escape on render). Styling lives in one nonce-carrying
 * `<style>` block because the admin CSP is nonce-only for `style-src`.
 */
final class CatalogAdmin
{
    private const NOTICES = [
        'item-saved'    => ['ok', 'Item saved.'],
        'item-deleted'  => ['ok', 'Item deleted.'],
        'cat-saved'     => ['ok', 'Category saved.'],
        'cat-deleted'   => ['ok', 'Category deleted.'],
        'cat-inuse'     => ['err', 'That category is still in use — reassign its items or child categories first.'],
        'badprice'      => ['err', 'Enter a valid price — a non-negative amount with up to 2 decimal places.'],
        'badcat'        => ['err', 'Check the category details and try again.'],
        'invalid'       => ['err', 'Check the details and try again.'],
    ];

    public function __construct(private Catalog $catalog)
    {
    }

    /**
     * @param string  $csrf   CSRF token for the forms
     * @param ?string $notice a fixed notice code from the ?ok=/?err= redirect
     * @param ?string $edit   a SKU to load into the item form (from ?edit=)
     * @param ?string $editCat a category id to load into the category form (from ?editcat=)
     * @param string  $nonce  the request CSP nonce
     */
    public function render(string $csrf = '', ?string $notice = null, ?string $edit = null, ?string $editCat = null, string $nonce = ''): string
    {
        $categories = $this->catalog->allCategories();
        $catName    = [];
        foreach ($categories as $c) {
            $catName[$c['id']] = $c['name'];
        }

        $editItem = ($edit !== null && trim($edit) !== '') ? $this->catalog->getItem(trim($edit)) : null;
        $editCatId = ($editCat !== null && preg_match('/^\d+$/', trim($editCat)) === 1) ? (int) trim($editCat) : null;
        $editCategory = $editCatId !== null ? $this->catalog->getCategory($editCatId) : null;

        $html = $this->styles($nonce)
            . '<div class="nb-page-head"><h1>Catalog</h1></div>' . $this->notice($notice)
            . '<p class="nb-muted cx-intro">The sellable item behind each SKU — name, price, category and image — the source of truth a storefront reads. '
            . 'The same records an agent manages over the <code>inventory_item_*</code> tools.</p>';

        // Item editor + list.
        $html .= '<h2>' . ($editItem !== null ? 'Edit item' : 'Add an item') . '</h2>';
        $html .= $this->itemForm($csrf, $categories, $editItem);
        $html .= '<h2 class="cx-mt2">Items</h2>' . $this->itemList($catName);

        // Category editor + list.
        $html .= '<h2 class="cx-mt2">' . ($editCategory !== null ? 'Edit category' : 'Add a category') . '</h2>';
        $html .= $this->categoryForm($csrf, $categories, $editCategory);
        $html .= '<h2 class="cx-mt15">Categories</h2>' . $this->categoryList($csrf, $categories, $catName);

        return $html;
    }

    /**
     * @param list<array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}> $categories
     * @param array{sku_code:string,name:string,price:string,unit:?string,description:?string,image_media_id:?int,category_id:?int,active:bool,featured:bool,created_at:string,updated_at:string}|null $item
     */
    private function itemForm(string $csrf, array $categories, ?array $item): string
    {
        // Null-safe locals up front, so the markup never indexes a possibly-null
        // $item (a create renders blank fields; an edit renders the stored values).
        $editing  = $item !== null;
        $e        = fn (string $s): string => $this->e($s);
        $sku      = $editing ? $item['sku_code'] : '';
        $name     = $editing ? $item['name'] : '';
        $price    = $editing ? $item['price'] : '';
        $unit     = ($editing && $item['unit'] !== null) ? $item['unit'] : '';
        $desc     = ($editing && $item['description'] !== null) ? $item['description'] : '';
        $image    = ($editing && $item['image_media_id'] !== null) ? (string) $item['image_media_id'] : '';
        $catId    = $editing ? $item['category_id'] : null;
        $active   = !$editing || $item['active'];
        $featured = $editing && $item['featured'];

        $skuField = $editing
            ? '<input type="hidden" name="sku" value="' . $e($sku) . '">'
              . '<div class="nb-field"><label>SKU</label><p class="cx-fixed"><code>' . $e($sku) . '</code></p></div>'
            : '<div class="nb-field"><label for="cx-sku">SKU code</label>'
              . '<input type="text" id="cx-sku" name="sku" placeholder="e.g. banana-loose" required></div>';

        return '<form class="nb-form-card cx-form" method="post" action="/admin/catalog/item-save">'
            . '<input type="hidden" name="_token" value="' . $e($csrf) . '">'
            . $skuField
            . '<div class="nb-field"><label for="cx-name">Name</label>'
            . '<input type="text" id="cx-name" name="name" value="' . $e($name) . '" placeholder="e.g. Bananas (loose)" required></div>'
            . '<div class="cx-row">'
            . '<div class="nb-field cx-half"><label for="cx-price">Price</label>'
            . '<input type="text" id="cx-price" name="price" inputmode="decimal" value="' . $e($price) . '" placeholder="e.g. 0.35"></div>'
            . '<div class="nb-field cx-half"><label for="cx-unit">Unit</label>'
            . '<input type="text" id="cx-unit" name="unit" value="' . $e($unit) . '" placeholder="e.g. each, kg"></div>'
            . '</div>'
            . '<div class="nb-field"><label for="cx-category">Category</label>'
            . $this->categorySelect('category_id', $categories, $catId, true)
            . '</div>'
            . '<div class="nb-field"><label for="cx-image">Image (media ID)</label>'
            . '<input type="text" id="cx-image" name="image_media_id" inputmode="numeric" value="' . $e($image) . '" placeholder="optional"></div>'
            . '<div class="nb-field"><label for="cx-desc">Description</label>'
            . '<textarea id="cx-desc" name="description" rows="3" placeholder="Plain text — no HTML">' . $e($desc) . '</textarea></div>'
            . '<div class="cx-checks">'
            . '<label class="cx-check"><input type="checkbox" name="active" value="1"' . ($active ? ' checked' : '') . '> Active (sellable)</label>'
            . '<label class="cx-check"><input type="checkbox" name="featured" value="1"' . ($featured ? ' checked' : '') . '> Featured</label>'
            . '</div>'
            . '<div class="cx-actions"><button type="submit" class="nb-btn nb-btn-primary">' . ($editing ? 'Save item' : 'Add item') . '</button>'
            . ($editing ? ' <a class="nb-btn" href="/admin/catalog">Cancel</a>' : '')
            . '</div></form>'
            . ($editing
                ? '<form class="cx-form cx-delete" method="post" action="/admin/catalog/item-delete" data-confirm="Delete this item? Its stock ledger is kept.">'
                  . '<input type="hidden" name="_token" value="' . $e($csrf) . '">'
                  . '<input type="hidden" name="sku" value="' . $e($sku) . '">'
                  . '<button type="submit" class="nb-link-danger">Delete this item</button></form>'
                : '');
    }

    /** @param array<int,string> $catName category id → name, for display */
    private function itemList(array $catName): string
    {
        $items = $this->catalog->allItems();
        if ($items === []) {
            return '<p class="nb-muted">No items yet. Add one above, or use the <code>inventory_item_set</code> tool.</p>';
        }

        $html = '<div class="nb-table-wrap nb-stack"><table class="nb-table"><thead><tr>'
            . '<th>SKU</th><th>Name</th><th class="cx-r">Price</th><th>Category</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($items as $it) {
            $cat = $it['category_id'] !== null ? ($catName[$it['category_id']] ?? '#' . $it['category_id']) : '—';
            $badges = ($it['active'] ? '<span class="nb-badge nb-badge-ok">Active</span>' : '<span class="nb-badge nb-badge-muted">Hidden</span>')
                . ($it['featured'] ? ' <span class="nb-badge nb-badge-official">Featured</span>' : '');
            $html .= '<tr><td data-label="SKU"><code>' . $this->e($it['sku_code']) . '</code></td>'
                . '<td data-label="Name">' . $this->e($it['name']) . '</td>'
                . '<td data-label="Price" class="cx-r">' . $this->e($it['price']) . ($it['unit'] !== null ? ' <span class="nb-muted">/ ' . $this->e($it['unit']) . '</span>' : '') . '</td>'
                . '<td data-label="Category">' . $this->e($cat) . '</td>'
                . '<td data-label="Status">' . $badges . '</td>'
                . '<td data-label="" class="cx-r"><a class="nb-btn nb-btn-sm" href="/admin/catalog?edit=' . $this->e(rawurlencode($it['sku_code'])) . '">Edit</a></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * @param list<array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}> $categories
     * @param array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}|null $cat
     */
    private function categoryForm(string $csrf, array $categories, ?array $cat): string
    {
        // A category being edited can't be its own parent; only top-level
        // categories (other than this one) can be parents.
        $parents = array_values(array_filter(
            $categories,
            static fn (array $c): bool => $c['parent_id'] === null && ($cat === null || $c['id'] !== $cat['id']),
        ));

        return '<form class="nb-form-card cx-form" method="post" action="/admin/catalog/category-save">'
            . '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">'
            . ($cat !== null ? '<input type="hidden" name="id" value="' . $cat['id'] . '">' : '')
            . '<div class="nb-field"><label for="cx-catname">Name</label>'
            . '<input type="text" id="cx-catname" name="name" value="' . $this->e($cat['name'] ?? '') . '" placeholder="e.g. Fruit &amp; Veg" required></div>'
            . '<div class="nb-field"><label for="cx-catparent">Parent</label>'
            . $this->categorySelect('parent_id', $parents, $cat['parent_id'] ?? null, true, 'cx-catparent')
            . '<p class="nb-muted cx-hint">Leave as “Top level” for a main category. Categories are two levels deep.</p></div>'
            . '<div class="cx-actions"><button type="submit" class="nb-btn nb-btn-primary">' . ($cat !== null ? 'Save category' : 'Add category') . '</button>'
            . ($cat !== null ? ' <a class="nb-btn" href="/admin/catalog">Cancel</a>' : '')
            . '</div></form>';
    }

    /**
     * @param list<array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}> $categories
     * @param array<int,string> $catName
     */
    private function categoryList(string $csrf, array $categories, array $catName): string
    {
        if ($categories === []) {
            return '<p class="nb-muted">No categories yet.</p>';
        }
        $html = '<div class="nb-table-wrap nb-stack"><table class="nb-table"><thead><tr>'
            . '<th>Name</th><th>Slug</th><th></th></tr></thead><tbody>';
        foreach ($categories as $c) {
            $label = $c['parent_id'] !== null
                ? '<span class="cx-child">↳</span> ' . $this->e($c['name']) . ' <span class="nb-muted cx-parent">in ' . $this->e($catName[$c['parent_id']] ?? '#' . $c['parent_id']) . '</span>'
                : '<strong>' . $this->e($c['name']) . '</strong>';
            $html .= '<tr><td data-label="Name">' . $label . '</td>'
                . '<td data-label="Slug"><code>' . $this->e($c['slug']) . '</code></td>'
                . '<td data-label="" class="cx-r"><a class="nb-btn nb-btn-sm" href="/admin/catalog?editcat=' . $c['id'] . '">Edit</a> '
                . '<form class="cx-inline" method="post" action="/admin/catalog/category-delete" data-confirm="Delete this category?">'
                . '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . $c['id'] . '">'
                . '<button type="submit" class="nb-link-danger">Delete</button></form></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * A category `<select>` (optionally with a "none/top-level" first option),
     * showing children indented under their parent.
     *
     * @param list<array{id:int,name:string,slug:string,parent_id:?int,created_at:string,updated_at:string}> $categories
     */
    private function categorySelect(string $name, array $categories, ?int $selected, bool $allowNone, string $id = 'cx-category'): string
    {
        $out = '<select id="' . $this->e($id) . '" name="' . $this->e($name) . '">';
        if ($allowNone) {
            $out .= '<option value="">' . ($name === 'parent_id' ? 'Top level' : '— none —') . '</option>';
        }
        foreach ($categories as $c) {
            $prefix = $c['parent_id'] !== null ? '— ' : '';
            $sel    = ($selected !== null && $selected === $c['id']) ? ' selected' : '';
            $out .= '<option value="' . $c['id'] . '"' . $sel . '>' . $prefix . $this->e($c['name']) . '</option>';
        }
        return $out . '</select>';
    }

    private function styles(string $nonce): string
    {
        $css = '.cx-intro{margin:-8px 0 20px}'
            . '.cx-mt15{margin-top:1.5rem}.cx-mt2{margin-top:2rem}'
            . '.cx-r{text-align:right}'
            . '.cx-form{max-width:520px;margin-bottom:1.5rem}'
            . '.cx-row{display:flex;gap:1rem;flex-wrap:wrap}.cx-half{flex:1 1 160px}'
            . '.cx-checks{display:flex;gap:1.25rem;flex-wrap:wrap;margin:.25rem 0 1rem}'
            . '.cx-check{display:flex;align-items:center;gap:.4rem;font-weight:500}'
            . '.cx-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}'
            . '.cx-fixed{margin:.2rem 0 0}.cx-hint{margin:.3rem 0 0;font-size:.85rem}'
            . '.cx-delete{margin-top:-.75rem;margin-bottom:1.5rem}'
            . '.cx-inline{display:inline}'
            . '.cx-child{opacity:.6}.cx-parent{font-size:.85rem}';
        return '<style nonce="' . $this->e($nonce) . '">' . $css . '</style>';
    }

    private function notice(?string $notice): string
    {
        if ($notice === null || !isset(self::NOTICES[$notice])) {
            return '';
        }
        [$kind, $msg] = self::NOTICES[$notice];
        return '<div class="nb-notice nb-notice-' . ($kind === 'ok' ? 'ok' : 'error') . '">' . $this->e($msg) . '</div>';
    }

    public function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
