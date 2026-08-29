<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\InsufficientStock;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Reservations;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The reservation overlay (Commerce slice 1): available = on_hand − reserved, holds
 * that never exceed available, and issuing that ships + releases atomically.
 */
final class ReservationsTest extends TestCase
{
    private Ledger $ledger;
    private Reservations $res;
    private int $loc;
    private const T = '2026-01-01 09:00:00';

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
        $this->res    = new Reservations(static fn (): PluginStorage => $storage, $this->ledger);
        $this->loc    = $this->ledger->ensureLocation('main', 'Main', self::T);
        $this->ledger->receive('SKU', $this->loc, '10', 'each', 'setup', self::T);
    }

    public function test_reserving_reduces_available_but_not_on_hand(): void
    {
        $r = $this->res->reserve('SKU', $this->loc, '4', 'order-1', self::T);

        self::assertSame('6.0000', $r['available']);
        self::assertSame('6.0000', $this->res->availableOf('SKU', $this->loc));
        self::assertSame('10.0000', $this->ledger->onHand('SKU', $this->loc), 'the hold is soft — on-hand is untouched');
        self::assertSame('4.0000', $this->res->reservedOf('SKU', $this->loc));
    }

    public function test_a_hold_beyond_available_is_refused(): void
    {
        $this->res->reserve('SKU', $this->loc, '7', 'order-1', self::T);

        $this->expectException(InsufficientStock::class);
        $this->res->reserve('SKU', $this->loc, '4', 'order-2', self::T); // only 3 available
    }

    public function test_releasing_frees_the_hold(): void
    {
        $this->res->reserve('SKU', $this->loc, '4', 'order-1', self::T);
        $freed = $this->res->release('order-1');

        self::assertSame(1, $freed);
        self::assertSame('10.0000', $this->res->availableOf('SKU', $this->loc));
    }

    public function test_release_is_idempotent(): void
    {
        self::assertSame(0, $this->res->release('never-existed'), 'releasing an unknown ref is a no-op, not an error');
    }

    public function test_issuing_ships_the_stock_and_clears_the_hold_atomically(): void
    {
        $this->res->reserve('SKU', $this->loc, '4', 'order-1', self::T);
        $out = $this->res->issue('SKU', $this->loc, '4', 'order-1', 'picker', self::T);

        self::assertSame('6.0000', $out['on_hand'], 'on-hand dropped by the shipped quantity');
        self::assertSame('6.0000', $out['available'], 'and available now equals on-hand — the hold cleared');
        self::assertSame('0.0000', $this->res->reservedOf('SKU', $this->loc));
        // The issue is a real, auditable ledger movement.
        self::assertSame('issue', $this->ledger->movementsFor('SKU')[0]['reason']);
    }

    public function test_available_never_goes_negative(): void
    {
        // Reserve everything, then an adjustment removes stock below the hold —
        // available reports 0, never a negative number.
        $this->res->reserve('SKU', $this->loc, '10', 'order-1', self::T);
        $this->ledger->adjust('SKU', $this->loc, '-3', 'each', 'shrinkage', self::T, 'waste');

        self::assertSame('0.0000', $this->res->availableOf('SKU', $this->loc));
    }

    public function test_two_refs_stack_against_available(): void
    {
        $this->res->reserve('SKU', $this->loc, '4', 'order-1', self::T);
        $this->res->reserve('SKU', $this->loc, '5', 'order-2', self::T);

        self::assertSame('9.0000', $this->res->reservedOf('SKU', $this->loc));
        self::assertSame('1.0000', $this->res->availableOf('SKU', $this->loc));
    }
}
