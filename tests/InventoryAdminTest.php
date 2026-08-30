<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\InventoryAdmin;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Reservations;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The Inventory admin page renders the ledger and its forms. These lock down the
 * Phase 1 "Workbench" additions: the SKU/location datalists, the bound SKU filter
 * (no SQLi, term escaped — no reflected XSS), and honest notice mapping.
 */
final class InventoryAdminTest extends TestCase
{
    private Connection $db;
    private InventoryAdmin $admin;
    private const T = '2026-01-01 09:00:00';

    protected function setUp(): void
    {
        $this->db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...Schema::all(), ...Schema::reservations()] as $sql) {
            $this->db->execute($sql);
        }
        foreach ([Schema::MOVEMENT, Schema::STOCK, Schema::LOCATION, Schema::RESERVATION] as $t) {
            $this->db->execute('TRUNCATE ' . $t);
        }

        $storage = new PluginStorage($this->db);
        $ledger  = new Ledger(static fn (): PluginStorage => $storage);
        $ledger->receive('house-blend', $ledger->ensureLocation('main', 'Main', self::T), '12', 'each', 'seed', self::T);
        $ledger->receive('oat-milk', $ledger->ensureLocation('store', 'Store', self::T), '4', 'each', 'seed', self::T);

        $this->admin = new InventoryAdmin(static fn (): PluginStorage => $storage);
    }

    public function test_the_datalists_list_the_known_skus_and_locations(): void
    {
        $html = $this->admin->render('tok');

        self::assertStringContainsString('<datalist id="inv-skus">', $html);
        self::assertStringContainsString('<option value="house-blend">', $html);
        self::assertStringContainsString('<option value="oat-milk">', $html);
        self::assertStringContainsString('<datalist id="inv-locs">', $html);
        self::assertStringContainsString('<option value="main">', $html);
        self::assertStringContainsString('<option value="store">', $html);
    }

    public function test_the_four_ledger_forms_are_present(): void
    {
        $html = $this->admin->render('tok');

        self::assertStringContainsString('action="/admin/inventory/receive"', $html);
        self::assertStringContainsString('action="/admin/inventory/adjust"', $html);
        self::assertStringContainsString('action="/admin/inventory/count"', $html);
        self::assertStringContainsString('action="/admin/inventory/transfer"', $html);
    }

    public function test_the_filter_narrows_the_stock_table_to_matching_skus(): void
    {
        $html = $this->admin->render('tok', null, 'house');

        // The Available cell (a <strong>) is unique to the stock table (the datalist
        // and the unfiltered movements table both still mention oat-milk).
        self::assertStringContainsString('<strong>12.0000</strong>', $html, 'house-blend stock row shown');
        self::assertStringNotContainsString('<strong>4.0000</strong>', $html, 'oat-milk stock row filtered out');
    }

    public function test_the_filter_term_is_escaped_and_never_injects(): void
    {
        // Reflected-XSS + SQLi guard: a hostile term is bound (no error, no rows)
        // and echoed back escaped — never as live markup.
        $html = $this->admin->render('tok', null, '"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_notice_code_maps_to_a_message_and_unknown_shows_nothing(): void
    {
        self::assertStringContainsString('Stock transferred.', $this->admin->render('tok', 'transferred'));
        self::assertStringContainsString('Choose two different locations', $this->admin->render('tok', 'samelocation'));
        self::assertStringNotContainsString('nb-notice', $this->admin->render('tok', 'nonsense-code'));
    }

    public function test_the_stock_rows_link_to_the_sku_drilldown(): void
    {
        $html = $this->admin->render('tok');
        self::assertStringContainsString('href="/admin/inventory?sku=house-blend"', $html);
    }

    public function test_the_sku_drilldown_shows_stock_holds_and_trail(): void
    {
        // Put a hold on house-blend so the drill-down has an open hold to explain.
        $storage = new PluginStorage($this->db);
        $ledger  = new Ledger(static fn (): PluginStorage => $storage);
        $res     = new Reservations(static fn (): PluginStorage => $storage, $ledger);
        $loc     = $ledger->ensureLocation('main', 'Main', '2026-01-01 09:00:00');
        $res->reserve('house-blend', $loc, '5', 'ORD-TEST:1', '2026-01-01 10:00:00');

        $html = $this->admin->render('tok', null, null, 'house-blend');

        self::assertStringContainsString('SKU <code>house-blend</code>', $html);
        self::assertStringContainsString('Stock by location', $html);
        self::assertStringContainsString('Open holds', $html);
        self::assertStringContainsString('ORD-TEST:1', $html, 'the hold ref explains the reservation');
        self::assertStringContainsString('Movement trail', $html);
        self::assertStringContainsString('receipt', $html, 'the seeding receipt is in the trail');
    }

    public function test_the_drilldown_escapes_and_binds_a_hostile_sku(): void
    {
        // Reflected-XSS + SQLi guard on ?sku=: bound (no error, no rows) and escaped.
        $html = $this->admin->render('tok', null, null, '"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Nothing recorded for this SKU', $html);
    }

    public function test_an_unknown_sku_shows_a_helpful_note(): void
    {
        $html = $this->admin->render('tok', null, null, 'no-such-sku');
        self::assertStringContainsString('Nothing recorded for this SKU', $html);
    }

    public function test_styling_is_a_nonced_style_block_not_inline_attributes(): void
    {
        // The admin CSP is nonce-only for style-src, so inline style= is dropped.
        // Both the overview and the drill-down must style via one nonce'd block.
        $overview = $this->admin->render('tok', null, null, null, 'NONCE123');
        self::assertStringContainsString('<style nonce="NONCE123">', $overview, 'a nonce-carrying style block');
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*"/', $overview, 'no inline style= in the overview');

        $detail = $this->admin->render('tok', null, null, 'house-blend', 'NONCE123');
        self::assertStringContainsString('<style nonce="NONCE123">', $detail);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*"/', $detail, 'no inline style= in the drill-down');
    }
}
