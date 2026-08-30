<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Database\Connection;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginLoader;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The Inventory admin form actions (H3), exercised through the *real* plugin
 * loader — so this also proves the page can gate on the plugin's own capability
 * (ADR 0020; registration would throw on an older core) and that each action maps
 * domain failures to an honest notice instead of a generic catch-all.
 */
final class InventoryAdminActionsTest extends TestCase
{
    private Connection $db;
    private string $installedJson;
    /** @var array<string,callable(Request):Response> */
    private array $actions;

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

        // Load the package the way Nimbus does, capturing the admin actions.
        $manifest            = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->installedJson = (string) tempnam(sys_get_temp_dir(), 'nb-installed-');
        file_put_contents($this->installedJson, json_encode([
            'packages' => [['name' => $manifest['name'], 'type' => $manifest['type'], 'extra' => $manifest['extra']]],
        ], JSON_THROW_ON_ERROR));

        $adminPages  = new AdminPageRegistry();
        $diagnostics = (new PluginLoader($this->installedJson))->load(new PluginCapabilities(
            adminPages: $adminPages,
            db: $this->db,
        ));
        self::assertSame([], $diagnostics, 'the plugin (page gated on nimbuscms.inventory:write) loads cleanly on this core');

        $this->actions = [];
        foreach ($adminPages->actions() as $a) {
            $this->actions[$a['action']] = $a['handler'];
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->installedJson);
    }

    /** @param array<string,string> $input */
    private function post(string $action, array $input): Response
    {
        return ($this->actions[$action])(new Request('POST', '/admin/inventory/' . $action, [], $input, [], []));
    }

    private function onHand(string $sku, string $location): string
    {
        $ledger = new Ledger(fn (): \Nimbus\Plugin\PluginStorage => new \Nimbus\Plugin\PluginStorage($this->db));
        $loc    = $ledger->ensureLocation($location, $location, '2026-01-01 00:00:00');
        return $ledger->onHand($sku, $loc);
    }

    public function test_receive_lands_stock_and_redirects_ok(): void
    {
        $r = $this->post('receive', ['sku' => 'house-blend', 'location' => 'main', 'qty' => '12', 'uom' => 'each']);

        self::assertSame(302, $r->status);
        self::assertSame('/admin/inventory?ok=received', $r->header('Location'));
        self::assertSame('12.0000', $this->onHand('house-blend', 'main'));
    }

    public function test_receive_a_bad_quantity_is_an_honest_badqty_notice(): void
    {
        $r = $this->post('receive', ['sku' => 'house-blend', 'location' => 'main', 'qty' => 'not-a-number']);

        self::assertSame('/admin/inventory?err=badqty', $r->header('Location'));
    }

    public function test_a_missing_field_is_invalid(): void
    {
        $r = $this->post('receive', ['sku' => '', 'qty' => '5']);
        self::assertSame('/admin/inventory?err=invalid', $r->header('Location'));
    }

    public function test_adjust_below_zero_is_short(): void
    {
        $this->post('receive', ['sku' => 'oat-milk', 'location' => 'main', 'qty' => '3']);
        $r = $this->post('adjust', ['sku' => 'oat-milk', 'location' => 'main', 'qty' => '-9', 'reason' => 'waste']);

        self::assertSame('/admin/inventory?err=short', $r->header('Location'));
    }

    public function test_count_sets_on_hand_and_redirects_ok(): void
    {
        $this->post('receive', ['sku' => 'cups', 'location' => 'main', 'qty' => '10']);
        $r = $this->post('count', ['sku' => 'cups', 'location' => 'main', 'qty' => '7']);

        self::assertSame('/admin/inventory?ok=counted', $r->header('Location'));
        self::assertSame('7.0000', $this->onHand('cups', 'main'));
    }

    public function test_transfer_moves_stock_between_locations(): void
    {
        $this->post('receive', ['sku' => 'beans', 'location' => 'main', 'qty' => '10']);
        $r = $this->post('transfer', ['sku' => 'beans', 'from' => 'main', 'to' => 'store', 'qty' => '4']);

        self::assertSame('/admin/inventory?ok=transferred', $r->header('Location'));
        self::assertSame('6.0000', $this->onHand('beans', 'main'));
        self::assertSame('4.0000', $this->onHand('beans', 'store'));
    }

    public function test_transfer_to_the_same_location_is_refused(): void
    {
        $r = $this->post('transfer', ['sku' => 'beans', 'from' => 'main', 'to' => 'main', 'qty' => '1']);
        self::assertSame('/admin/inventory?err=samelocation', $r->header('Location'));
    }
}
