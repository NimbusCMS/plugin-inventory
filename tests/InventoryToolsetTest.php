<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Auth\Authorizer;
use Nimbus\Database\Connection;
use Nimbus\Mcp\McpError;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\InventoryToolset;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Reservations;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The MCP surface. The gate itself lives in the core PluginToolset base (tested
 * there); here we prove Inventory's tools are wired to it correctly — the write
 * tools need `inventory:write`, reads need `:read` — and that the handlers set the
 * actor server-side and return domain errors as data.
 */
final class InventoryToolsetTest extends TestCase
{
    private InventoryToolset $toolset;
    private EntryOpContext $ctx;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach (Schema::all() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::MOVEMENT);
        $db->execute('TRUNCATE ' . Schema::STOCK);
        $db->execute('TRUNCATE ' . Schema::LOCATION);

        foreach (Schema::reservations() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::RESERVATION);

        $storage       = new PluginStorage($db);
        $ledger        = new Ledger(static fn (): PluginStorage => $storage);
        $this->toolset = new InventoryToolset($ledger, new Reservations(static fn (): PluginStorage => $storage, $ledger));
        $this->toolset->bindTo('nimbuscms.inventory'); // the registrar does this in prod
        $this->ctx = new EntryOpContext('127.0.0.1', '/api/v1/mcp');

        // Model the booted state: Application seals the plugin's capability into the
        // Authorizer as management, which is what makes it wildcard-immune.
        Authorizer::useManagement(['nimbuscms.inventory']);
    }

    protected function tearDown(): void
    {
        Authorizer::reset();
    }

    private function principal(string ...$scopes): TokenPrincipal
    {
        return new TokenPrincipal(1, 'warehouse-bot', array_values($scopes));
    }

    public function test_the_tools_are_namespaced_and_split_read_from_write(): void
    {
        $defs  = $this->toolset->definitions($this->principal('nimbuscms.inventory:read', 'nimbuscms.inventory:write'));
        $names = array_column($defs, 'name');

        self::assertSame([
            'inventory_receive', 'inventory_adjust', 'inventory_count',
            'inventory_transfer', 'inventory_reserve', 'inventory_release',
            'inventory_issue', 'inventory_stock', 'inventory_movements',
        ], $names);
    }

    public function test_a_read_only_token_sees_only_the_read_tools(): void
    {
        $names = array_column($this->toolset->definitions($this->principal('nimbuscms.inventory:read')), 'name');
        self::assertSame(['inventory_stock', 'inventory_movements'], $names);
    }

    public function test_a_content_token_cannot_move_stock(): void
    {
        // The A1 property, end to end: an "all collections" write token is not the
        // inventory capability, so the write tool is invisible and un-callable.
        self::assertSame([], $this->toolset->definitions($this->principal('*:write')));

        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "inventory_receive"');
        $this->toolset->call('inventory_receive', ['sku' => 'X', 'location' => 'main', 'qty' => '1', 'uom' => 'each'], $this->principal('*:write'), $this->ctx);
    }

    public function test_receive_then_stock_round_trips(): void
    {
        $write = $this->principal('nimbuscms.inventory:write');
        $out   = $this->toolset->call('inventory_receive', ['sku' => 'COFFEE', 'location' => 'main', 'qty' => '12', 'uom' => 'kg'], $write, $this->ctx);

        self::assertTrue($out['ok']);
        self::assertSame('12.0000', $out['on_hand']);

        $read  = $this->principal('nimbuscms.inventory:read');
        $stock = $this->toolset->call('inventory_stock', ['sku' => 'COFFEE'], $read, $this->ctx);
        self::assertSame('12.0000', $stock['locations'][0]['on_hand']);
    }

    public function test_the_actor_is_the_token_not_an_argument(): void
    {
        $rw = $this->principal('nimbuscms.inventory:read', 'nimbuscms.inventory:write');
        // Even if the caller tries to pass an actor, it is ignored — the token's
        // name is recorded.
        $this->toolset->call('inventory_receive', ['sku' => 'TEA', 'location' => 'main', 'qty' => '1', 'uom' => 'box', 'actor' => 'someone-else', 'occurred_at' => '1999-01-01 00:00:00'], $rw, $this->ctx);

        $moves = $this->toolset->call('inventory_movements', ['sku' => 'TEA'], $rw, $this->ctx);
        self::assertSame('warehouse-bot', $moves['movements'][0]['actor'], 'the token name, not the argument');
        self::assertNotSame('1999-01-01 00:00:00', $moves['movements'][0]['occurred_at'], 'the server clock, not the argument');
    }

    public function test_an_oversell_comes_back_as_data_not_an_exception(): void
    {
        $write = $this->principal('nimbuscms.inventory:write');
        $this->toolset->call('inventory_receive', ['sku' => 'W', 'location' => 'main', 'qty' => '2', 'uom' => 'each'], $write, $this->ctx);

        $out = $this->toolset->call('inventory_adjust', ['sku' => 'W', 'location' => 'main', 'qty' => '-5'], $write, $this->ctx);
        self::assertFalse($out['ok']);
        self::assertSame('insufficient_stock', $out['error']);
    }
}
