<?php

declare(strict_types=1);

namespace NimbusCMS\Inventory;

/**
 * A category cannot be deleted while something still points at it — a child
 * category or an item. Thrown rather than orphan-crashing or silently cascading;
 * the caller reparents or reassigns first (ADR 0022, Slice 1 blocks the delete).
 */
final class CategoryInUse extends \RuntimeException
{
    public function __construct(public readonly int $categoryId, string $what)
    {
        parent::__construct("Category {$categoryId} is still in use by {$what}.");
    }
}
