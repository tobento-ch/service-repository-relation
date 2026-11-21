<?php

/**
 * TOBENTO
 *
 * @copyright   Tobias Strub, TOBENTO
 * @license     MIT License, see LICENSE file distributed with this source code.
 * @author      Tobias Strub
 * @link        https://www.tobento.ch
 */

declare(strict_types=1);

namespace Tobento\Service\Repository\Relation;

/**
 * ResolvableRelationInterface: relations assigning their related entities to the owner items.
 */
interface ResolvableRelationInterface extends RelationInterface
{
    /**
     * Returns the items with the related entities assigned under the relation name.
     * Loads the related entities of all items at once. The item keys are kept
     * and an existing value under the relation name is replaced.
     *
     * @param iterable<array-key, array> $items The owner items, e.g. storage rows.
     * @return array<array-key, array>
     */
    public function resolveForItems(iterable $items): array;
}