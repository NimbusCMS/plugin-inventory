<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\Catalog;
use NimbusCMS\Inventory\CategoryInUse;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The item master service (ADR 0022). These prove the security controls the
 * review pinned at the write boundary: raw storage, price validation, the field
 * allow-list (no over-posting), the media-id soft ref, and category integrity.
 */
final class CatalogTest extends TestCase
{
    private Catalog $catalog;

    protected function setUp(): void
    {
        $db = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: 'db',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => ($p = getenv('TEST_DB_PASS')) !== false ? $p : 'root',
        ]);
        foreach (Schema::items() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::ITEM);
        $db->execute('TRUNCATE ' . Schema::CATEGORY);

        $storage       = new PluginStorage($db);
        $this->catalog = new Catalog(static fn (): PluginStorage => $storage);
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
}
