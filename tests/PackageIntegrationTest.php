<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory\Tests;

use Nimbus\Auth\CapabilityRegistry;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Mcp\Guide\SkillRegistry;
use Nimbus\Mcp\McpToolsetRegistry;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginLoader;
use Nimbus\Plugin\ServiceRegistry;
use NimbusCMS\Inventory\InventoryPlugin;
use NimbusCMS\Inventory\ReservationPort;
use PHPUnit\Framework\TestCase;

/**
 * Proves the *package boundary*: a real Composer installation of this package is
 * discovered by Nimbus's own loader, from this package's real manifest, and
 * registers its migration, its grantable capability, its MCP toolset and its agent
 * guide — no database required, because storage is taken lazily.
 */
final class PackageIntegrationTest extends TestCase
{
    private string $installedJson;

    protected function setUp(): void
    {
        $this->installedJson = tempnam(sys_get_temp_dir(), 'nb-installed-') ?: '';
    }

    protected function tearDown(): void
    {
        @unlink($this->installedJson);
    }

    /** @return array<string,mixed> this package's actual composer manifest */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertIsArray($manifest);

        return $manifest;
    }

    private function installedAs(): string
    {
        $manifest = $this->manifest();
        file_put_contents($this->installedJson, json_encode([
            'packages' => [[
                'name'  => $manifest['name'],
                'type'  => $manifest['type'],
                'extra' => $manifest['extra'],
            ]],
        ], JSON_THROW_ON_ERROR));

        return $this->installedJson;
    }

    public function test_the_package_declares_nimbus_as_a_runtime_dependency(): void
    {
        $manifest = $this->manifest();

        self::assertArrayHasKey('nimbuscms/nimbus', $manifest['require']);
        self::assertArrayNotHasKey('nimbuscms/nimbus', $manifest['require-dev'] ?? []);
    }

    public function test_the_package_is_typed_as_a_nimbus_plugin(): void
    {
        self::assertSame('nimbuscms-plugin', $this->manifest()['type']);
    }

    public function test_the_id_is_namespaced_so_its_capability_cannot_be_a_collection_handle(): void
    {
        // The H2a invariant this plugin relies on: a dotted id means its
        // `inventory` capability resource can never collide with a content handle.
        self::assertStringContainsString('.', $this->manifest()['extra']['nimbus']['id']);
    }

    public function test_discovery_registers_the_migration_capability_toolset_and_guide(): void
    {
        $migrations   = new MigrationRegistry();
        $capabilities = new CapabilityRegistry();
        $mcpToolsets  = new McpToolsetRegistry();
        $skills       = new SkillRegistry();
        $services     = new ServiceRegistry();

        $loader      = new PluginLoader($this->installedAs());
        $diagnostics = $loader->load(new PluginCapabilities(
            migrations: $migrations,
            skills: $skills,
            capabilities: $capabilities,
            mcpToolsets: $mcpToolsets,
            services: $services,
        ));

        self::assertSame([], $diagnostics, 'a correctly installed package must load cleanly');
        self::assertSame([InventoryPlugin::ID => $this->manifest()['name']], $loader->registered());

        self::assertSame(['nimbuscms.inventory:001_ledger', 'nimbuscms.inventory:002_reservations', 'nimbuscms.inventory:003_items'], array_column($migrations->all(), 'name'), 'its ledger + reservation + item-master migrations');
        self::assertSame([InventoryPlugin::ID], $capabilities->managementResources(), 'its grantable capability');
        self::assertCount(1, $mcpToolsets->all(), 'its MCP toolset');
        self::assertNotSame([], $skills->documents(), 'its agent guide');
        self::assertInstanceOf(ReservationPort::class, $services->get(ReservationPort::class), 'it publishes the reservation port');
    }

    public function test_disabling_the_package_registers_nothing(): void
    {
        $migrations   = new MigrationRegistry();
        $capabilities = new CapabilityRegistry();

        (new PluginLoader($this->installedAs(), [InventoryPlugin::ID => false]))
            ->load(new PluginCapabilities(migrations: $migrations, capabilities: $capabilities));

        self::assertSame([], $migrations->all());
        self::assertSame([], $capabilities->managementResources());
    }
}
