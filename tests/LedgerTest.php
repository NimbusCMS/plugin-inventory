<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\InsufficientStock;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The ledger is the source of truth; `inventory_stock` is only a cache of it. The
 * one invariant these lock down is **`fold(ledger) == stock`** — after any mix of
 * operations the projection equals a fresh fold of every movement — plus the
 * atomicity and oversell guards that keep it true under failure and concurrency.
 */
final class LedgerTest extends TestCase
{
    private Connection $db;
    private Ledger $ledger;
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
        foreach (Schema::all() as $sql) {
            $this->db->execute($sql);
        }
        $this->db->execute('TRUNCATE ' . Schema::MOVEMENT);
        $this->db->execute('TRUNCATE ' . Schema::STOCK);
        $this->db->execute('TRUNCATE ' . Schema::LOCATION);

        $storage      = new PluginStorage($this->db);
        $this->ledger = new Ledger(static fn (): PluginStorage => $storage);
    }

    private function loc(string $code = 'main'): int
    {
        return $this->ledger->ensureLocation($code, ucfirst($code), self::T);
    }

    /** The invariant: the projection must equal a fresh fold of the whole ledger. */
    private function assertProjectionMatchesLedger(): void
    {
        $folded = [];
        foreach ($this->ledger->foldLedger() as $key => $onHand) {
            $folded[$key] = number_format((float) $onHand, 4, '.', '');
        }
        $cached = [];
        foreach ($this->db->select('SELECT sku_code, location_id, on_hand FROM ' . Schema::STOCK) as $r) {
            $cached[$r['sku_code'] . "\0" . $r['location_id']] = number_format((float) $r['on_hand'], 4, '.', '');
        }
        self::assertSame($folded, $cached, 'fold(ledger) must equal the stock projection');
    }

    public function test_receive_raises_on_hand_and_records_a_movement(): void
    {
        $loc = $this->loc();
        $r   = $this->ledger->receive('COFFEE', $loc, '10', 'kg', 'alice', self::T);

        self::assertSame('10.0000', $r['on_hand']);
        self::assertGreaterThan(0, $r['movement_id']);
        self::assertSame('10.0000', $this->ledger->onHand('COFFEE', $loc));
        $this->assertProjectionMatchesLedger();
    }

    public function test_decimal_quantities_are_exact(): void
    {
        $loc = $this->loc();
        $this->ledger->receive('COFFEE', $loc, '0.2', 'kg', 'a', self::T);
        $this->ledger->receive('COFFEE', $loc, '0.1', 'kg', 'a', self::T);

        self::assertSame('0.3000', $this->ledger->onHand('COFFEE', $loc), 'no float drift');
        $this->assertProjectionMatchesLedger();
    }

    public function test_a_negative_adjustment_below_zero_is_refused(): void
    {
        $loc = $this->loc();
        $this->ledger->receive('WIDGET', $loc, '5', 'each', 'a', self::T);

        try {
            $this->ledger->adjust('WIDGET', $loc, '-6', 'each', 'a', self::T, 'waste');
            self::fail('an oversell must be refused');
        } catch (InsufficientStock) {
            // expected
        }

        self::assertSame('5.0000', $this->ledger->onHand('WIDGET', $loc), 'the refused movement left stock untouched');
        self::assertCount(1, $this->ledger->movementsFor('WIDGET'), 'and recorded no ledger row');
        $this->assertProjectionMatchesLedger();
    }

    public function test_count_sets_on_hand_and_records_the_delta(): void
    {
        $loc = $this->loc();
        $this->ledger->receive('TEA', $loc, '8', 'box', 'a', self::T);
        $r = $this->ledger->count('TEA', $loc, '5', 'box', 'auditor', self::T);

        self::assertSame('5.0000', $r['on_hand']);
        $movements = $this->ledger->movementsFor('TEA');
        self::assertSame('-3.0000', (string) $movements[0]['qty'], 'the count recorded the correcting delta');
        self::assertSame('count', $movements[0]['reason']);
        $this->assertProjectionMatchesLedger();
    }

    public function test_transfer_moves_between_locations_atomically(): void
    {
        $a = $this->loc('shelf-a');
        $b = $this->loc('shelf-b');
        $this->ledger->receive('BOLT', $a, '20', 'each', 'a', self::T);

        $r = $this->ledger->transfer('BOLT', $a, $b, '7', 'each', 'a', self::T);

        self::assertSame('13.0000', $r['on_hand_from']);
        self::assertSame('7.0000', $r['on_hand_to']);
        $this->assertProjectionMatchesLedger();
    }

    public function test_transfer_is_refused_and_rolls_back_when_the_source_is_short(): void
    {
        $a = $this->loc('shelf-a');
        $b = $this->loc('shelf-b');
        $this->ledger->receive('BOLT', $a, '3', 'each', 'a', self::T);

        try {
            $this->ledger->transfer('BOLT', $a, $b, '5', 'each', 'a', self::T);
            self::fail('a short transfer must be refused');
        } catch (InsufficientStock) {
            // expected
        }

        self::assertSame('3.0000', $this->ledger->onHand('BOLT', $a), 'source untouched');
        self::assertSame('0.0000', $this->ledger->onHand('BOLT', $b), 'destination untouched — the whole transfer rolled back');
        $this->assertProjectionMatchesLedger();
    }

    public function test_actor_and_time_are_recorded_as_given_by_the_caller(): void
    {
        // The Ledger records what it is handed; the toolset is what makes them
        // server-set (covered in InventoryToolsetTest).
        $loc = $this->loc();
        $this->ledger->receive('COFFEE', $loc, '1', 'kg', 'system', '2026-02-02 12:00:00');
        $m = $this->ledger->movementsFor('COFFEE')[0];

        self::assertSame('system', $m['actor']);
        self::assertSame('2026-02-02 12:00:00', $m['occurred_at']);
    }

    public function test_a_non_positive_receive_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->receive('X', $this->loc(), '0', 'each', 'a', self::T);
    }

    public function test_the_projection_survives_a_realistic_mix(): void
    {
        $a = $this->loc('a');
        $b = $this->loc('b');
        $this->ledger->receive('SKU1', $a, '100', 'each', 'u', self::T);
        $this->ledger->receive('SKU2', $a, '50', 'each', 'u', self::T);
        $this->ledger->adjust('SKU1', $a, '-10', 'each', 'u', self::T, 'waste');
        $this->ledger->transfer('SKU1', $a, $b, '30', 'each', 'u', self::T);
        $this->ledger->count('SKU2', $a, '48', 'each', 'u', self::T);
        $this->ledger->receive('SKU1', $b, '5', 'each', 'u', self::T);

        self::assertSame('60.0000', $this->ledger->onHand('SKU1', $a));
        self::assertSame('35.0000', $this->ledger->onHand('SKU1', $b));
        self::assertSame('48.0000', $this->ledger->onHand('SKU2', $a));
        $this->assertProjectionMatchesLedger();
    }
}
