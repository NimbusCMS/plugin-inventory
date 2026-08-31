<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * Inventory's implementation of {@see CatalogReadPort} — a thin delegate to the
 * {@see Catalog} service's public reads, which own the public-safe query
 * (active-only, coarse availability, allow-listed sort, bound search). This only
 * forwards, so consuming the port grants no capability Inventory wouldn't, and
 * the boundary (ADR 0005) holds: the consumer never sees a table.
 */
final class CatalogReadAdapter implements CatalogReadPort
{
    public function __construct(private Catalog $catalog)
    {
    }

    public function list(array $filters): array
    {
        return $this->catalog->publicList($filters);
    }

    public function get(string $sku): ?array
    {
        return $this->catalog->publicGet($sku);
    }

    public function categories(): array
    {
        return $this->catalog->publicCategories();
    }
}
