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

use Exception;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Storage\ItemInterface;
use Tobento\Service\Storage\Items;
use Tobento\Service\Storage\ItemsInterface;

/**
 * RelationEntityResolver: resolves the given relations for owner items,
 * e.g. in the entity factory before creating the entities.
 *
 * Only relations implementing the ResolvableRelationInterface are resolved, others are skipped.
 * The selection is done by the relations, e.g. $relations->only('author', 'tags').
 */
class RelationEntityResolver
{
    /**
     * Create a new RelationEntityResolver.
     *
     * @param RelationsInterface $relations The relations to resolve.
     */
    public function __construct(
        protected RelationsInterface $relations,
    ) {}

    /**
     * Returns the relations to resolve.
     *
     * @return RelationsInterface
     */
    public function relations(): RelationsInterface
    {
        return $this->relations;
    }

    /**
     * Returns the items with the related entities assigned under the relation names.
     * Each relation loads its related entities for all items at once.
     *
     * @param ItemsInterface $items The owner items, e.g. the storage items.
     * @return ItemsInterface
     * @throws RelationException If resolving a relation fails, e.g. a database error or a misconfigured relation.
     */
    public function resolveForItems(ItemsInterface $items): ItemsInterface
    {
        $relations = $this->resolvableRelations();

        if (empty($relations)) {
            return $items;
        }

        $resolved = $items->all();

        foreach($relations as $relation) {
            try {
                $resolved = $relation->resolveForItems($resolved);
            } catch (Exception $e) {
                // any relation implementation, wrapped as one exception naming the relation:
                throw RelationException::wrap($relation->name(), $e);
            }
        }

        return new Items(items: $resolved, itemsCount: $items->count(), action: $items->action());
    }

    /**
     * Returns the attributes of the item with the related entities assigned under the relation names.
     *
     * @param ItemInterface|array $item The owner item, e.g. the storage item.
     * @return array
     * @throws RelationException If resolving a relation fails, e.g. a database error or a misconfigured relation.
     */
    public function resolveForItem(ItemInterface|array $item): array
    {
        $attributes = is_array($item) ? $item : $item->all();

        $resolved = $this->resolveForItems(new Items(items: [$attributes]))->all();

        $first = $resolved[0] ?? null;

        return is_array($first) ? $first : $attributes;
    }

    /**
     * Returns the relations implementing the ResolvableRelationInterface.
     *
     * @return array<string, ResolvableRelationInterface>
     */
    protected function resolvableRelations(): array
    {
        $relations = [];

        foreach($this->relations()->all() as $name => $relation) {
            if ($relation instanceof ResolvableRelationInterface) {
                $relations[$name] = $relation;
            }
        }

        return $relations;
    }
}