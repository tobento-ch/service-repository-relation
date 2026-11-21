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

use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Storage\Column\ColumnInterface;
use Tobento\Service\Repository\Storage\Column\ColumnsInterface;
use Tobento\Service\Repository\Storage\EntityFactory;
use Tobento\Service\Storage\ItemInterface;
use Tobento\Service\Storage\ItemsInterface;

/**
 * RelationEntityFactory: an entity factory getting the relations passed by the repository,
 * resolving the selected relations in batch when creating the entities.
 */
class RelationEntityFactory extends EntityFactory implements RelationsAwareEntityFactoryInterface, RelationsAwareInterface
{
    use HasEntityFactoryRelations;

    /**
     * Create a new RelationEntityFactory.
     *
     * @param null|iterable<ColumnInterface>|ColumnsInterface $columns
     * @param array<int, string> $resolveRelations The names of the relations to resolve, none by default.
     * @throws InvalidArgumentException If a name is not a string.
     */
    public function __construct(
        null|iterable|ColumnsInterface $columns = null,
        array $resolveRelations = [],
    ) {
        parent::__construct($columns);

        foreach($resolveRelations as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('The names of the relations to resolve must be strings');
            }
        }

        $this->resolveRelations = array_values(array_unique($resolveRelations));
    }

    /**
     * Create an entity from storage item, with the selected relations resolved.
     *
     * @param ItemInterface $item
     * @return object The created entity.
     * @throws RelationException If resolving a relation fails, e.g. a database error or a misconfigured relation.
     */
    public function createEntityFromStorageItem(ItemInterface $item): object
    {
        return $this->createEntityFromArray($this->resolveRelationsForItem($item));
    }

    /**
     * Create entities from storage items, with the selected relations resolved in batch.
     *
     * @param ItemsInterface $items
     * @return iterable<object> The created entities.
     * @throws RelationException If resolving a relation fails, e.g. a database error or a misconfigured relation.
     */
    public function createEntitiesFromStorageItems(ItemsInterface $items): iterable
    {
        return parent::createEntitiesFromStorageItems($this->resolveRelationsForItems($items));
    }
}