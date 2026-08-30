<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\InventoryAdmin;
use NimbusCMS\Inventory\Ledger;
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
}
