<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Database\Connection;
use Nimbus\Plugin\PluginStorage;
use NimbusCMS\Inventory\Catalog;
use NimbusCMS\Inventory\CatalogAdmin;
use NimbusCMS\Inventory\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The Catalog admin page (ADR 0022). These lock the security + surface contracts:
 * author values are escaped on render (never live markup), styling is one nonce'd
 * block with no inline `style=` (the admin CSP), and tables carry `data-label`
 * cells so they reflow on a phone rather than overflow.
 */
final class CatalogAdminTest extends TestCase
{
    private Catalog $catalog;
    private CatalogAdmin $admin;
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
        foreach (Schema::items() as $sql) {
            $db->execute($sql);
        }
        $db->execute('TRUNCATE ' . Schema::ITEM);
        $db->execute('TRUNCATE ' . Schema::CATEGORY);

        $storage       = new PluginStorage($db);
        $this->catalog = new Catalog(static fn (): PluginStorage => $storage);
        $this->admin   = new CatalogAdmin($this->catalog);
    }

    public function test_the_item_and_category_forms_are_present(): void
    {
        $html = $this->admin->render('tok');
        self::assertStringContainsString('action="/admin/catalog/item-save"', $html);
        self::assertStringContainsString('action="/admin/catalog/category-save"', $html);
        self::assertStringContainsString('name="_token" value="tok"', $html);
    }

    public function test_an_items_values_are_escaped_on_render_not_live_markup(): void
    {
        // The store keeps author input raw (proven in CatalogTest); the page must
        // escape it — this is the standing contract the storefront (Slice 2) shares.
        $this->catalog->saveItem('xss', ['name' => '<script>alert(1)</script>', 'price' => '1.00'], self::T);
        $html = $this->admin->render('tok');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_editing_an_item_prefills_the_form(): void
    {
        $this->catalog->saveItem('milk', ['name' => 'Whole Milk', 'price' => '1.20', 'unit' => 'litre'], self::T);
        $html = $this->admin->render('tok', null, 'milk');

        self::assertStringContainsString('value="Whole Milk"', $html);
        self::assertStringContainsString('value="1.20"', $html);
        self::assertStringContainsString('action="/admin/catalog/item-delete"', $html, 'editing offers a delete');
    }

    public function test_a_category_can_be_chosen_and_children_are_shown_nested(): void
    {
        $top   = $this->catalog->saveCategory(null, 'Grocery', null, self::T);
        $this->catalog->saveCategory(null, 'Fruit', $top, self::T);
        $html = $this->admin->render('tok');

        self::assertStringContainsString('<select id="cx-category" name="category_id">', $html);
        self::assertStringContainsString('Grocery', $html);
        self::assertStringContainsString('Fruit', $html);
    }

    public function test_tables_reflow_on_mobile_via_data_label_cells(): void
    {
        $this->catalog->saveItem('apple', ['name' => 'Apple', 'price' => '0.30'], self::T);
        $html = $this->admin->render('tok');
        // data-label cells are how the shared admin CSS stacks a table into cards
        // at narrow widths — their presence is the mobile-reflow contract.
        self::assertStringContainsString('data-label="SKU"', $html);
        self::assertStringContainsString('data-label="Price"', $html);
    }

    public function test_styling_is_a_nonced_style_block_not_inline_attributes(): void
    {
        $this->catalog->saveItem('a', ['name' => 'A', 'price' => '1.00'], self::T);
        $html = $this->admin->render('tok', null, null, null, 'NONCE123');

        self::assertStringContainsString('<style nonce="NONCE123">', $html, 'a nonce-carrying style block');
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*"/', $html, 'no inline style= anywhere on the page');
    }

    public function test_a_notice_code_maps_to_a_message_and_unknown_shows_nothing(): void
    {
        self::assertStringContainsString('Item saved.', $this->admin->render('tok', 'item-saved'));
        self::assertStringContainsString('still in use', $this->admin->render('tok', 'cat-inuse'));
        self::assertStringNotContainsString('nb-notice', $this->admin->render('tok', 'nonsense-code'));
    }
}
