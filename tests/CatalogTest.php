<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\Catalog;
use NimbusCMS\Inventory\CategoryInUse;
use NimbusCMS\Inventory\Ledger;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The item master service (ADR 0022). These prove the security controls the
 * review pinned at the write boundary: raw storage, price validation, the field
 * allow-list (no over-posting), the media-id soft ref, and category integrity —
 * and (ADR 0023) the public storefront reads: active-only, coarse availability,
 * a public-safe shape, and allow-listed sort / bound search.
 */
final class CatalogTest extends TestCase
{
    private Catalog $catalog;
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
        foreach ([...Schema::items(), ...Schema::all(), ...Schema::reservations()] as $sql) {
            $db->execute($sql);
        }
        foreach ([Schema::ITEM, Schema::CATEGORY, Schema::STOCK, Schema::MOVEMENT, Schema::LOCATION, Schema::RESERVATION] as $t) {
            $db->execute('TRUNCATE ' . $t);
        }

        $storage       = new PluginStorage($db);
        $this->catalog = new Catalog(static fn (): PluginStorage => $storage);
        $this->ledger  = new Ledger(static fn (): PluginStorage => $storage);
    }

    /** Give a SKU on-hand stock so its public availability is computable. */
    private function stock(string $sku, string $qty): void
    {
        $now = $this->now();
        $this->ledger->receive($sku, $this->ledger->ensureLocation('main', 'Main', $now), $qty, 'each', 'seed', $now);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function test_an_item_updates_in_place_and_keeps_unsent_fields(): void
    {
        $this->catalog->saveItem('milk', ['name' => 'Milk', 'price' => '1.20', 'unit' => 'litre'], $this->now());
        // A partial update leaves price/unit untouched.
        $this->catalog->saveItem('milk', ['name' => 'Whole Milk'], $this->now());

        $item = $this->catalog->getItem('milk');
        self::assertNotNull($item);
        self::assertSame('Whole Milk', $item['name']);
        self::assertSame('1.20', $item['price']);
        self::assertSame('litre', $item['unit']);
    }

    public function test_price_must_be_a_non_negative_two_place_decimal(): void
    {
        foreach (['-1', '3.999', 'abc', '1e3', '  '] as $bad) {
            try {
                $this->catalog->saveItem('x', ['name' => 'X', 'price' => $bad], $this->now());
                if (trim($bad) !== '') {
                    self::fail("price \"{$bad}\" should have been rejected");
                }
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Blank price defaults to 0.00 rather than erroring.
        $this->catalog->saveItem('free', ['name' => 'Sample', 'price' => ''], $this->now());
        self::assertSame('0.00', $this->catalog->getItem('free')['price']);
    }

    public function test_the_write_ignores_unknown_keys_and_never_mass_assigns(): void
    {
        // An over-posting attempt: a forged sku_code, timestamps, and a junk column.
        $this->catalog->saveItem('real', [
            'name'       => 'Real',
            'price'      => '2.00',
            'sku_code'   => 'hijacked',
            'created_at' => '1999-01-01 00:00:00',
            'is_admin'   => '1',
            'nonsense'   => 'x',
        ], $this->now());

        // The addressed SKU is the one written; the forged sku_code did nothing.
        self::assertNotNull($this->catalog->getItem('real'));
        self::assertNull($this->catalog->getItem('hijacked'));
        // created_at is server-set, not the forged value.
        self::assertNotSame('1999-01-01 00:00:00', $this->catalog->getItem('real')['created_at']);
    }

    public function test_image_media_id_is_a_nullable_positive_int(): void
    {
        $this->catalog->saveItem('a', ['name' => 'A', 'image_media_id' => '7'], $this->now());
        self::assertSame(7, $this->catalog->getItem('a')['image_media_id']);

        $this->catalog->saveItem('b', ['name' => 'B', 'image_media_id' => ''], $this->now());
        self::assertNull($this->catalog->getItem('b')['image_media_id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->saveItem('c', ['name' => 'C', 'image_media_id' => '0'], $this->now());
    }

    public function test_an_item_category_must_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->saveItem('d', ['name' => 'D', 'category_id' => '999'], $this->now());
    }

    public function test_categories_are_two_levels_and_cannot_cycle(): void
    {
        $top   = $this->catalog->saveCategory(null, 'Grocery', null, $this->now());
        $child = $this->catalog->saveCategory(null, 'Fruit', $top, $this->now());

        // A child cannot be a parent (third level).
        try {
            $this->catalog->saveCategory(null, 'Bananas', $child, $this->now());
            self::fail('a third level should be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // A category cannot be its own parent.
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->saveCategory($top, 'Grocery', $top, $this->now());
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $top   = $this->catalog->saveCategory(null, 'Grocery', null, $this->now());
        $child = $this->catalog->saveCategory(null, 'Fruit', $top, $this->now());

        // Blocked by a child category.
        try {
            $this->catalog->deleteCategory($top);
            self::fail('deleting a parent with children should be blocked');
        } catch (CategoryInUse) {
            $this->addToAssertionCount(1);
        }

        // Blocked by an item.
        $this->catalog->saveItem('apple', ['name' => 'Apple', 'category_id' => (string) $child], $this->now());
        $this->expectException(CategoryInUse::class);
        $this->catalog->deleteCategory($child);
    }

    public function test_slugs_are_allow_listed_and_unique(): void
    {
        $a = $this->catalog->saveCategory(null, 'Fruit & Veg!', null, $this->now());
        $b = $this->catalog->saveCategory(null, 'Fruit & Veg!', null, $this->now());

        self::assertSame('fruit-veg', $this->catalog->getCategory($a)['slug']);
        self::assertSame('fruit-veg-2', $this->catalog->getCategory($b)['slug'], 'a clashing slug is suffixed');
    }

    // --- public storefront reads (ADR 0023) ------------------------------

    public function test_public_reads_exclude_inactive_items(): void
    {
        $this->catalog->saveItem('shown', ['name' => 'Shown', 'price' => '1.00', 'active' => true], $this->now());
        $this->catalog->saveItem('hidden', ['name' => 'Hidden', 'price' => '1.00', 'active' => false], $this->now());

        $skus = array_column($this->catalog->publicList([])['items'], 'sku_code');
        self::assertContains('shown', $skus);
        self::assertNotContains('hidden', $skus, 'an inactive item is invisible on the storefront');

        // And a direct fetch of the hidden SKU is indistinguishable from missing.
        self::assertNull($this->catalog->publicGet('hidden'));
        self::assertNull($this->catalog->publicGet('no-such-sku'));
        self::assertNotNull($this->catalog->publicGet('shown'));
    }

    public function test_availability_is_coarse_and_leaks_no_counts(): void
    {
        $this->catalog->saveItem('plenty', ['name' => 'Plenty', 'price' => '1.00'], $this->now());
        $this->catalog->saveItem('few', ['name' => 'Few', 'price' => '1.00'], $this->now());
        $this->catalog->saveItem('none', ['name' => 'None', 'price' => '1.00'], $this->now());
        $this->stock('plenty', '50');
        $this->stock('few', '3');
        // 'none' has no stock at all.

        $byId = [];
        foreach ($this->catalog->publicList([])['items'] as $it) {
            $byId[$it['sku_code']] = $it;
        }

        self::assertSame('in_stock', $byId['plenty']['availability']);
        self::assertSame('low', $byId['few']['availability']);
        self::assertSame('out', $byId['none']['availability']);

        // The public shape carries no raw stock, reserved, cost, or active flag.
        foreach (['on_hand', 'reserved', 'available', 'cost', 'active'] as $leak) {
            self::assertArrayNotHasKey($leak, $byId['plenty'], "public item must not carry {$leak}");
        }
    }

    public function test_an_unknown_sort_falls_back_and_never_errors(): void
    {
        $this->catalog->saveItem('a', ['name' => 'Apple', 'price' => '2.00'], $this->now());
        $this->catalog->saveItem('b', ['name' => 'Banana', 'price' => '1.00'], $this->now());

        // A hostile sort value is not interpolated — it simply falls back.
        $out = $this->catalog->publicList(['sort' => 'price; DROP TABLE inventory_item']);
        self::assertSame(2, $out['total']);

        $asc = array_column($this->catalog->publicList(['sort' => 'price_asc'])['items'], 'sku_code');
        self::assertSame(['b', 'a'], $asc, 'price_asc orders cheapest first');
    }

    public function test_search_is_bound_and_matches_name(): void
    {
        $this->catalog->saveItem('a', ['name' => 'Green Apple', 'price' => '1.00'], $this->now());
        $this->catalog->saveItem('b', ['name' => 'Banana', 'price' => '1.00'], $this->now());

        $hits = array_column($this->catalog->publicList(['q' => 'apple'])['items'], 'sku_code');
        self::assertSame(['a'], $hits);

        // A LIKE wildcard in the term is a literal, not a match-all.
        self::assertSame([], $this->catalog->publicList(['q' => '%'])['items'], 'a bare % matches nothing literally');
    }

    public function test_category_filter_resolves_a_slug(): void
    {
        $fruit = $this->catalog->saveCategory(null, 'Fruit', null, $this->now());
        $this->catalog->saveItem('a', ['name' => 'Apple', 'price' => '1.00', 'category_id' => (string) $fruit], $this->now());
        $this->catalog->saveItem('b', ['name' => 'Bread', 'price' => '1.00'], $this->now());

        $hits = array_column($this->catalog->publicList(['category' => 'fruit'])['items'], 'sku_code');
        self::assertSame(['a'], $hits);

        // An unknown category yields nothing, never the unfiltered list.
        self::assertSame([], $this->catalog->publicList(['category' => 'no-such'])['items']);
    }
}
