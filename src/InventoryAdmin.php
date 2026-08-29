<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

use Nimbus\Plugin\PluginStorage;

/**
 * The Inventory admin page — a read-only overview of stock (on-hand, reserved,
 * available per SKU and location) and the most recent movements. Registered as a
 * GET-only plugin admin page; changes are made through the MCP tools (or an agent),
 * so this is a window, not an editor.
 *
 * SKU codes and locations can originate from tool callers, so every value is
 * escaped before it reaches the page.
 */
final class InventoryAdmin
{
    private const NOTICES = [
        'received' => ['ok', 'Stock received.'],
        'adjusted' => ['ok', 'Stock adjusted.'],
        'invalid'  => ['err', 'Check the SKU and quantity and try again.'],
        'short'    => ['err', 'Not enough available for that adjustment.'],
    ];

    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage)
    {
    }

    /**
     * @param string  $csrf   the CSRF token for the forms (passed by core to the page handler)
     * @param ?string $notice a fixed notice code (from the ?ok=/?err= redirect), mapped to a message
     */
    public function render(string $csrf = '', ?string $notice = null): string
    {
        $s = ($this->storage)();

        $banner = '';
        if ($notice !== null && isset(self::NOTICES[$notice])) {
            [$kind, $msg] = self::NOTICES[$notice];
            $banner = '<div class="nb-notice nb-notice-' . ($kind === 'ok' ? 'ok' : 'error') . '">' . $this->e($msg) . '</div>';
        }

        $locations = [];
        foreach ($s->select('SELECT id, code FROM ' . Schema::LOCATION) as $l) {
            $locations[(int) $l['id']] = (string) $l['code'];
        }

        $stock = $s->select(
            'SELECT s.sku_code, s.location_id, s.on_hand, s.uom,
                    COALESCE((SELECT SUM(qty) FROM ' . Schema::RESERVATION . ' r
                              WHERE r.sku_code = s.sku_code AND r.location_id = s.location_id), 0) AS reserved
             FROM ' . Schema::STOCK . ' s
             ORDER BY s.sku_code, s.location_id',
        );

        $movements = $s->select(
            'SELECT sku_code, location_id, qty, uom, reason, actor, occurred_at
             FROM ' . Schema::MOVEMENT . ' ORDER BY id DESC LIMIT 20',
        );

        $html = '<div class="nb-page-head"><h1>Inventory</h1></div>' . $banner
            . '<p class="nb-muted" style="margin:-8px 0 20px">Stock as an append-only ledger — on-hand, reserved and available per location. '
            . 'Receive or adjust below, or drive it over MCP (an agent can also count and transfer).</p>'
            . $this->forms($csrf);

        if ($stock === []) {
            $html .= '<p class="nb-muted">No stock yet. Receive some with the <code>inventory_receive</code> tool.</p>';
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

    /** The receive + adjust forms. Each posts to its plugin admin action with the CSRF token. */
    private function forms(string $csrf): string
    {
        $t = '<input type="hidden" name="_token" value="' . $this->e($csrf) . '">';

        return '<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem">'
            . '<form class="nb-form-card" method="post" action="/admin/inventory/receive" style="flex:1 1 260px">'
            . '<h2>Receive stock</h2>' . $t
            . $this->field('SKU', 'sku', 'text', 'e.g. house-blend')
            . $this->field('Location', 'location', 'text', 'main')
            . $this->field('Quantity', 'qty', 'text', 'e.g. 12')
            . $this->field('Unit', 'uom', 'text', 'each')
            . '<button type="submit" class="nb-btn nb-btn-primary">Receive</button></form>'
            . '<form class="nb-form-card" method="post" action="/admin/inventory/adjust" style="flex:1 1 260px">'
            . '<h2>Adjust stock</h2>' . $t
            . $this->field('SKU', 'sku', 'text', 'e.g. house-blend')
            . $this->field('Location', 'location', 'text', 'main')
            . $this->field('Change (+/−)', 'qty', 'text', 'e.g. -3')
            . $this->field('Reason', 'reason', 'text', 'waste')
            . '<button type="submit" class="nb-btn nb-btn-primary">Adjust</button></form>'
            . '</div>';
    }

    private function field(string $label, string $name, string $type, string $placeholder): string
    {
        return '<div class="nb-field"><label for="inv-' . $this->e($name) . '">' . $this->e($label) . '</label>'
            . '<input type="' . $this->e($type) . '" id="inv-' . $this->e($name) . '" name="' . $this->e($name) . '" placeholder="' . $this->e($placeholder) . '"></div>';
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
