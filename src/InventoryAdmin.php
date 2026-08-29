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
    /** @param \Closure():PluginStorage $storage */
    public function __construct(private \Closure $storage)
    {
    }

    public function render(): string
    {
        $s = ($this->storage)();

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

        $html = '<div class="nb-page-head"><h1>Inventory</h1></div>'
            . '<p class="nb-muted" style="margin:-8px 0 20px">Stock as an append-only ledger — on-hand, reserved and available per location. '
            . 'Managed through the MCP tools (an agent can receive, adjust, count and transfer).</p>';

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

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
