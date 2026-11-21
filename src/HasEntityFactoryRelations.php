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
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;
use Tobento\Service\Storage\ItemInterface;
use Tobento\Service\Storage\ItemsInterface;

/**
 * HasEntityFactoryRelations: provides the relations passed by the repository to an entity factory,
 * and resolves the selected relations for the storage items.
 *
 * The entity factory must implement the RelationsAwareEntityFactoryInterface (passing the relations)
 * and the RelationsAwareInterface (providing and resolving the relations).
 */
trait HasEntityFactoryRelations
{
    /**
     * @var null|RelationsInterface
     */
    protected null|RelationsInterface $relations = null;

    /**
     * @var array<int, string> The names of the relations to resolve.
     */
    protected array $resolveRelations = [];

    /**
     * Sets the relations.
     *
     * @param RelationsInterface $relations
     * @return void
     */
    public function setRelations(RelationsInterface $relations): void
    {
        $this->relations = $relations;
    }

    /**
     * Returns the relations, empty until the repository passes its relations.
     *
     * @return RelationsInterface
     */
    public function relations(): RelationsInterface
    {
        if (is_null($this->relations)) {
            $this->relations = new Relations();
        }

        return $this->relations;
    }

    /**
     * Returns a new instance resolving exactly the relations of the given names.
     * Without names, no relations are resolved.
     *
     * @param string ...$names
     * @return static
     */
    public function withResolvedRelations(string ...$names): static
    {
        $new = clone $this;
        $new->resolveRelations = array_values(array_unique($names));
        return $new;
    }

    /**
     * Returns a new instance resolving all current relations.
     * The names are taken from the relations at the time of calling,
     * so call it after the repository passed its relations (e.g. on the repository).
     *
     * @return static
     */
    public function withAllResolvedRelations(): static
    {
        return $this->withResolvedRelations(...array_keys($this->relations()->all()));
    }

    /**
     * Returns a new instance not resolving the relations of the given names anymore.
     * Without names, nothing changes.
     *
     * @param string ...$names
     * @return static
     */
    public function withoutResolvedRelations(string ...$names): static
    {
        $new = clone $this;
        $new->resolveRelations = array_values(array_diff($this->resolvedRelations(), $names));
        return $new;
    }

    /**
     * Returns the names of the relations to resolve.
     *
     * @return array<int, string> e.g. ['author', 'tags']
     */
    public function resolvedRelations(): array
    {
        return $this->resolveRelations;
    }

    /**
     * Returns the items with the selected relations resolved.
     *
     * @param ItemsInterface $items
     * @return ItemsInterface
     */
    protected function resolveRelationsForItems(ItemsInterface $items): ItemsInterface
    {
        if (empty($this->resolvedRelations())) {
            return $items;
        }

        try {
            return new RelationEntityResolver($this->relationsToResolve())->resolveForItems($items);
        } catch (Exception $e) {
            throw RelationException::wrap(implode(', ', $this->resolvedRelations()), $e);
        }
    }

    /**
     * Returns the attributes of the item with the selected relations resolved.
     *
     * @param ItemInterface $item
     * @return array
     */
    protected function resolveRelationsForItem(ItemInterface $item): array
    {
        if (empty($this->resolvedRelations())) {
            return $item->all();
        }

        try {
            return new RelationEntityResolver($this->relationsToResolve())->resolveForItem($item);
        } catch (Exception $e) {
            throw RelationException::wrap(implode(', ', $this->resolvedRelations()), $e);
        }
    }

    /**
     * Returns the relations to resolve.
     *
     * @return RelationsInterface
     */
    protected function relationsToResolve(): RelationsInterface
    {
        $relations = $this->relations();

        foreach($this->resolvedRelations() as $name) {
            if (!$relations->has($name)) {
                throw new RelationNotFoundException(sprintf(
                    'Relation [%s] to resolve not found',
                    $name
                ));
            }
        }

        return $relations->only(...$this->resolvedRelations());
    }
}