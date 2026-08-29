<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\ReservationAdapter;
use NimbusCMS\Inventory\ReservationPort;
use NimbusCMS\Inventory\Reservations;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The port Commerce consumes (ADR 0019): it speaks location *codes* and the server
 * clock, delegating the real guards to Reservations. This is the contract a
 * consumer relies on, so it is exercised as a consumer would.
 */
final class ReservationPortTest extends TestCase
{
    private ReservationPort $port;
    private Ledger $ledger;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach ([...Schema::all(), ...Schema::reservations()] as $sql) {
            $db->execute($sql);
        }
        foreach ([Schema::MOVEMENT, Schema::STOCK, Schema::LOCATION, Schema::RESERVATION] as $t) {
            $db->execute('TRUNCATE ' . $t);
        }

        $storage      = new PluginStorage($db);
        $this->ledger = new Ledger(static fn (): PluginStorage => $storage);
        $this->port   = new ReservationAdapter($this->ledger, new Reservations(static fn (): PluginStorage => $storage, $this->ledger));
        $this->ledger->receive('SKU', $this->ledger->ensureLocation('main', 'Main', '2026-01-01 00:00:00'), '10', 'each', 'setup', '2026-01-01 00:00:00');
    }

    public function test_a_consumer_reserves_by_code_and_available_drops(): void
    {
        $this->port->reserve('SKU', 'main', '3', 'order-1');

        self::assertSame('7.0000', $this->port->available('SKU', 'main'));
    }

    public function test_release_by_ref_restores_availability(): void
    {
        $this->port->reserve('SKU', 'main', '3', 'order-1');
        self::assertSame(1, $this->port->release('order-1'));
        self::assertSame('10.0000', $this->port->available('SKU', 'main'));
    }

    public function test_issue_ships_and_records_the_actor(): void
    {
        $this->port->reserve('SKU', 'main', '3', 'order-1');
        $out = $this->port->issue('SKU', 'main', '3', 'order-1', 'fulfilment-bot');

        self::assertSame('7.0000', $out['on_hand']);
        self::assertSame('fulfilment-bot', $this->ledger->movementsFor('SKU')[0]['actor']);
    }

    public function test_a_new_location_code_is_created_on_use(): void
    {
        // available on an unknown location is 0 (it is created empty), never an error.
        self::assertSame('0.0000', $this->port->available('SKU', 'annex'));
    }
}
