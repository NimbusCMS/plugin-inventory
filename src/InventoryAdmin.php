<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Plugin\PluginStorage;

/**
 * The Inventory admin page — an overview of stock (on-hand, reserved, available
 * per SKU and location) and the most recent movements, plus the four ledger
 * actions as forms (receive, adjust, count, transfer). The same operations an
 * agent drives over MCP; this is the human hand on the same ledger.
 *
 * SKU codes, locations and the filter term can originate from callers, so every
 * value is escaped before it reaches the page. The SKU/location inputs offer a
 * `<datalist>` of what already exists (a typo-guard, not a hard gate — receiving a
 * genuinely new SKU is still allowed by typing it).
 */
final class InventoryAdmin
{
    private const NOTICES = [
        'received'     => ['ok', 'Stock received.'],
        'adjusted'     => ['ok', 'Stock adjusted.'],
        'counted'      => ['ok', 'Stock count recorded.'],
        'transferred'  => ['ok', 'Stock transferred.'],
        'short'        => ['err', 'Not enough available for that movement.'],
        'badqty'       => ['err', 'Enter a valid quantity — a number with up to 4 decimal places (adjustments may be negative).'],
        'samelocation' => ['err', 'Choose two different locations to transfer between.'],
        'invalid'      => ['err', 'Check the SKU and quantity and try again.'],
    ];

    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * @param string  $csrf   the CSRF token for the forms (passed by core to the page handler)
     * @param ?string $notice a fixed notice code (from the ?ok=/?err= redirect), mapped to a message
     * @param ?string $q      a SKU filter substring (from ?q=), applied to the stock table
     */
    public function render(string $csrf = '', ?string $notice = null, ?string $q = null): string
    {
        $s = ($this->storage)();
        $q = $q !== null ? trim($q) : '';

        $banner = '';
        if ($notice !== null && isset(self::NOTICES[$notice])) {
            [$kind, $msg] = self::NOTICES[$notice];
            $banner = '<div class="nb-notice nb-notice-' . ($kind === 'ok' ? 'ok' : 'error') . '">' . $this->e($msg) . '</div>';
        }

        $locations = [];
        foreach ($s->select('SELECT id, code FROM ' . Schema::LOCATION . ' ORDER BY code') as $l) {
            $locations[(int) $l['id']] = (string) $l['code'];
        }
        /** @var list<string> $skus distinct SKUs already stocked — the datalist suggestions */
        $skus = array_map(
            static fn (array $r): string => (string) $r['sku_code'],
            $s->select('SELECT DISTINCT sku_code FROM ' . Schema::STOCK . ' ORDER BY sku_code'),
        );

        // Stock, optionally filtered to SKUs containing the term (bound LIKE — no
        // string-built SQL). available = on_hand − reserved.
        $where  = $q === '' ? '' : ' WHERE s.sku_code LIKE :q';
        $params = $q === '' ? [] : ['q' => '%' . $q . '%'];
        $stock  = $s->select(
            'SELECT s.sku_code, s.location_id, s.on_hand, s.uom,
                    COALESCE((SELECT SUM(qty) FROM ' . Schema::RESERVATION . ' r
                              WHERE r.sku_code = s.sku_code AND r.location_id = s.location_id), 0) AS reserved
             FROM ' . Schema::STOCK . ' s' . $where . '
             ORDER BY s.sku_code, s.location_id',
            $params,
        );

        $movements = $s->select(
            'SELECT sku_code, location_id, qty, uom, reason, actor, occurred_at
             FROM ' . Schema::MOVEMENT . ' ORDER BY id DESC LIMIT 20',
        );

        $html = '<div class="nb-page-head"><h1>Inventory</h1></div>' . $banner
            . '<p class="nb-muted" style="margin:-8px 0 20px">Stock as an append-only ledger — on-hand, reserved and available per location. '
            . 'Filter it below, record a movement, or drive it all over MCP.</p>'
            . $this->datalists($skus, array_values($locations));

        // Lead with the stock overview — the operator wants to *see* inventory
        // first; the movement forms come after.
        $html .= '<h2 style="margin-top:1.5rem">Stock on hand</h2>';
        $html .= $this->filterForm($q);
        if ($stock === []) {
            $html .= $q === ''
                ? '<p class="nb-muted">No stock yet. Record a receipt below, or use the <code>inventory_receive</code> tool.</p>'
                : '<p class="nb-muted">No stock matches “' . $this->e($q) . '”.</p>';
        } else {
            $html .= '<div class="nb-table-wrap nb-stack"><table class="nb-table"><thead><tr>'
                . '<th>SKU</th><th>Location</th><th style="text-align:right">On hand</th>'
                . '<th style="text-align:right">Reserved</th><th style="text-align:right">Available</th></tr></thead><tbody>';
            foreach ($stock as $r) {
                $onHand   = (string) $r['on_hand'];
                $reserved = (string) $r['reserved'];
                $avail    = number_format(max(0, (float) $onHand - (float) $reserved), 4, '.', '');
                $loc      = $locations[(int) $r['location_id']] ?? (string) $r['location_id'];
                $html .= '<tr><td data-label="SKU"><code>' . $this->e((string) $r['sku_code']) . '</code></td>'
                    . '<td data-label="Location">' . $this->e($loc) . '</td>'
                    . '<td data-label="On hand" style="text-align:right">' . $this->e($onHand) . ' ' . $this->e((string) $r['uom']) . '</td>'
                    . '<td data-label="Reserved" style="text-align:right">' . $this->e($reserved) . '</td>'
                    . '<td data-label="Available" style="text-align:right"><strong>' . $this->e($avail) . '</strong></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // The movement forms, after the overview.
        $html .= '<h2 style="margin-top:2rem">Record a movement</h2>';
        $html .= $this->forms($csrf);

        $html .= '<h2 style="margin-top:2rem">Recent movements</h2>';
        if ($movements === []) {
            $html .= '<p class="nb-muted">None yet.</p>';
        } else {
            $html .= '<div class="nb-table-wrap nb-stack"><table class="nb-table"><thead><tr>'
                . '<th>SKU</th><th>Location</th><th style="text-align:right">Qty</th><th>Reason</th><th>Actor</th><th>When</th></tr></thead><tbody>';
            foreach ($movements as $m) {
                $loc = $locations[(int) $m['location_id']] ?? (string) $m['location_id'];
                $html .= '<tr><td data-label="SKU"><code>' . $this->e((string) $m['sku_code']) . '</code></td>'
                    . '<td data-label="Location">' . $this->e($loc) . '</td>'
                    . '<td data-label="Qty" style="text-align:right">' . $this->e((string) $m['qty']) . ' ' . $this->e((string) $m['uom']) . '</td>'
                    . '<td data-label="Reason">' . $this->e((string) $m['reason']) . '</td>'
                    . '<td data-label="Actor">' . $this->e((string) $m['actor']) . '</td>'
                    . '<td data-label="When" class="nb-muted">' . $this->e((string) $m['occurred_at']) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html;
    }

    /**
     * The known-SKU and known-location suggestion lists, referenced by the form
     * inputs. Suggestions only — a new SKU/location can still be typed.
     *
     * @param list<string> $skus
     * @param list<string> $locations
     */
    private function datalists(array $skus, array $locations): string
    {
        $opts = static function (array $values, callable $e): string {
            $out = '';
            foreach ($values as $v) {
                $out .= '<option value="' . $e($v) . '"></option>';
            }
            return $out;
        };

        return '<datalist id="inv-skus">' . $opts($skus, [$this, 'e']) . '</datalist>'
            . '<datalist id="inv-locs">' . $opts($locations, [$this, 'e']) . '</datalist>';
    }

    /** The four ledger forms (receive, adjust, count, transfer). Each posts to its action with the CSRF token. */
    private function forms(string $csrf): string
    {
        $t = '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">';

        return '<div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.5rem">'
            . '<form class="nb-form-card" method="post" action="/admin/inventory/receive" style="flex:1 1 240px">'
            . '<h2>Receive stock</h2>' . $t
            . $this->field('SKU', 'sku', 'e.g. house-blend', 'inv-skus')
            . $this->field('Location', 'location', 'main', 'inv-locs')
            . $this->field('Quantity', 'qty', 'e.g. 12')
            . $this->field('Unit', 'uom', 'each')
            . '<button type="submit" class="nb-btn nb-btn-primary">Receive</button></form>'

            . '<form class="nb-form-card" method="post" action="/admin/inventory/adjust" style="flex:1 1 240px">'
            . '<h2>Adjust stock</h2>' . $t
            . $this->field('SKU', 'sku', 'e.g. house-blend', 'inv-skus')
            . $this->field('Location', 'location', 'main', 'inv-locs')
            . $this->field('Change (+/−)', 'qty', 'e.g. -3')
            . $this->field('Reason', 'reason', 'waste')
            . '<button type="submit" class="nb-btn nb-btn-primary">Adjust</button></form>'

            . '<form class="nb-form-card" method="post" action="/admin/inventory/count" style="flex:1 1 240px">'
            . '<h2>Count stock</h2>' . $t
            . '<p class="nb-muted" style="margin-top:-6px;font-size:.85rem">Set on-hand to a counted figure; the correction is recorded as a movement.</p>'
            . $this->field('SKU', 'sku', 'e.g. house-blend', 'inv-skus')
            . $this->field('Location', 'location', 'main', 'inv-locs')
            . $this->field('Counted', 'qty', 'e.g. 40')
            . '<button type="submit" class="nb-btn nb-btn-primary">Record count</button></form>'

            . '<form class="nb-form-card" method="post" action="/admin/inventory/transfer" style="flex:1 1 240px">'
            . '<h2>Transfer stock</h2>' . $t
            . $this->field('SKU', 'sku', 'e.g. house-blend', 'inv-skus')
            . $this->field('From', 'from', 'main', 'inv-locs')
            . $this->field('To', 'to', 'store', 'inv-locs')
            . $this->field('Quantity', 'qty', 'e.g. 6')
            . '<button type="submit" class="nb-btn nb-btn-primary">Transfer</button></form>'
            . '</div>';
    }

    /** A SKU substring filter for the stock table (GET, no JS). */
    private function filterForm(string $q): string
    {
        return '<form method="get" action="/admin/inventory" class="nb-stack" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.75rem">'
            . '<div class="nb-field" style="flex:1 1 220px;margin:0"><label for="inv-q">Filter by SKU</label>'
            . '<input id="inv-q" type="search" name="q" value="' . $this->e($q) . '" placeholder="e.g. house"></div>'
            . '<button type="submit" class="nb-btn">Filter</button>'
            . ($q === '' ? '' : ' <a class="nb-btn" href="/admin/inventory">Clear</a>')
            . '</form>';
    }

    private function field(string $label, string $name, string $placeholder, ?string $list = null): string
    {
        $listAttr = $list === null ? '' : ' list="' . $this->e($list) . '"';

        return '<div class="nb-field"><label for="inv-' . $this->e($name) . '">' . $this->e($label) . '</label>'
            . '<input type="text" id="inv-' . $this->e($name) . '" name="' . $this->e($name) . '"' . $listAttr
            . ' placeholder="' . $this->e($placeholder) . '"></div>';
    }

    public function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
