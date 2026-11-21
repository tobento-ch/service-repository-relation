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
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\Storage\Column\ColumnInterface;
use Tobento\Service\Repository\Storage\Column\ColumnsInterface;
use Tobento\Service\Repository\Storage\StorageEntityFactoryInterface;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\StorageInterface;

/**
 * RelationStorageRepository: a storage repository with relations,
 * resolving the selected relations per call, e.g. withResolvedRelations('author', 'tags')->findAll().
 */
abstract class RelationStorageRepository extends StorageRepository implements RelationsAwareInterface
{
    use HasRelations;

    /**
     * Create a new RelationStorageRepository.
     *
     * @param StorageInterface $storage
     * @param string $table
     * @param null|iterable<ColumnInterface>|ColumnsInterface $columns
     * @param null|StorageEntityFactoryInterface $entityFactory
     * @param null|iterable<RelationInterface>|RelationsInterface $relations
     */
    public function __construct(
        StorageInterface $storage,
        string $table,
        null|iterable|ColumnsInterface $columns = null,
        null|StorageEntityFactoryInterface $entityFactory = null,
        null|iterable|RelationsInterface $relations = null,
    ) {
        parent::__construct(
            storage: $storage,
            table: $table,
            columns: $columns,
            entityFactory: $entityFactory,
        );

        $this->relations = $this->assignStorageToRelations($this->processRelations($relations));
        $this->passRelationsToEntityFactory();
    }

    /**
     * Clone repository.
     */
    public function __clone()
    {
        parent::__clone();

        $this->passRelationsToEntityFactory();
    }

    /**
     * Returns a new instance resolving exactly the relations of the given names.
     * Without names, no relations are resolved.
     *
     * @param string ...$names
     * @return static
     * @throws RelationNotFoundException If a relation does not exist.
     */
    public function withResolvedRelations(string ...$names): static
    {
        $this->checkRelationNames($names);

        $new = clone $this;
        $new->entityFactory = $new->resolvingEntityFactory()->withResolvedRelations(...$names);
        return $new;
    }

    /**
     * Returns a new instance resolving all relations.
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
     * @throws RelationNotFoundException If a relation does not exist.
     */
    public function withoutResolvedRelations(string ...$names): static
    {
        $this->checkRelationNames($names);

        $new = clone $this;
        $new->entityFactory = $new->resolvingEntityFactory()->withoutResolvedRelations(...$names);
        return $new;
    }

    /**
     * Returns the names of the relations to resolve.
     *
     * @return array<int, string> e.g. ['author', 'tags']
     */
    public function resolvedRelations(): array
    {
        $entityFactory = $this->entityFactory();

        return $entityFactory instanceof RelationsAwareInterface ? $entityFactory->resolvedRelations() : [];
    }

    /**
     * Delete an entity by id.
     * Deletes the relation data of the entity too, e.g. the pivot rows.
     *
     * @param string|int $id
     * @return object The deleted entity.
     * @throws RepositoryDeleteException
     */
    public function deleteById(string|int $id): object
    {
        $entity = parent::deleteById($id);

        try {
            $this->deleteRelationsForOwners(ownerIds: [$id]);
        } catch (Exception $e) {
            throw new RepositoryDeleteException(id: $id, message: $e->getMessage(), previous: $e);
        }

        return $entity;
    }

    /**
     * Delete entities.
     * Deletes the relation data of the entities too, e.g. the pivot rows.
     *
     * @param array $where The where parameters.
     * @return iterable<object> The deleted entities.
     * @throws RepositoryDeleteException
     */
    public function delete(array $where): iterable
    {
        // find the ids before deleting, as not every storage returns the deleted items:
        $ownerIds = $this->ownerDeletingRelations()->count() > 0
            ? $this->findIdsForWhere(where: $where)
            : [];

        $deleted = parent::delete($where);

        try {
            $this->deleteRelationsForOwners(ownerIds: $ownerIds);
        } catch (Exception $e) {
            throw new RepositoryDeleteException(message: $e->getMessage(), previous: $e);
        }

        return $deleted;
    }

    /**
     * Checks if relations of the given names exist.
     *
     * @param array<array-key, string> $names
     * @return void
     */
    protected function checkRelationNames(array $names): void
    {
        foreach($names as $name) {
            if (!$this->relations()->has($name)) {
                throw new RelationNotFoundException(sprintf('Relation [%s] not found', $name));
            }
        }
    }

    /**
     * Returns the entity factory resolving relations.
     *
     * @return RelationsAwareInterface&StorageEntityFactoryInterface
     */
    protected function resolvingEntityFactory(): RelationsAwareInterface&StorageEntityFactoryInterface
    {
        $entityFactory = $this->entityFactory();

        if (! $entityFactory instanceof RelationsAwareInterface) {
            throw new LogicException(sprintf(
                'The entity factory [%s] does not support resolving relations, use the %s',
                $entityFactory::class,
                RelationEntityFactory::class
            ));
        }

        return $entityFactory;
    }

    /**
     * Returns the relations whose data is deleted with the owner.
     *
     * @return RelationsInterface
     */
    protected function ownerDeletingRelations(): RelationsInterface
    {
        return $this->relations()->filter(
            fn (RelationInterface $relation): bool => $relation instanceof DeletesWithOwnerInterface
        );
    }

    /**
     * Deletes the relation data of the given owners.
     *
     * @param array<array-key, int|string> $ownerIds
     * @return void
     */
    protected function deleteRelationsForOwners(array $ownerIds): void
    {
        if (empty($ownerIds)) {
            return;
        }

        foreach($this->ownerDeletingRelations() as $relation) {
            if ($relation instanceof DeletesWithOwnerInterface) {
                $relation->deleteForOwners(ownerIds: $ownerIds);
            }
        }
    }

    /**
     * Returns the ids of the entities matching the given where parameters.
     *
     * @param array $where
     * @return array<int, int|string>
     */
    protected function findIdsForWhere(array $where): array
    {
        if (is_null($primaryKey = $this->primaryKey())) {
            return [];
        }

        $ids = $this->applyWhere($this->query(), $where)->column($primaryKey)->all();

        return array_values(array_filter(
            $ids,
            fn (mixed $id): bool => is_int($id) || is_string($id)
        ));
    }

    /**
     * Returns the relations with the repository storage assigned
     * to the storage aware relations having no storage.
     *
     * @param RelationsInterface $relations
     * @return RelationsInterface
     */
    protected function assignStorageToRelations(RelationsInterface $relations): RelationsInterface
    {
        foreach($relations as $relation) {
            if (
                $relation instanceof StorageAwareRelationInterface
                && is_null($relation->storage())
            ) {
                $relations = $relations->withAdded($relation->withStorage($this->storage()));
            }
        }

        return $relations;
    }

    /**
     * Passes the relations to the entity factory if it is relations aware.
     *
     * @return void
     */
    protected function passRelationsToEntityFactory(): void
    {
        $entityFactory = $this->entityFactory();

        if ($entityFactory instanceof RelationsAwareEntityFactoryInterface) {
            $entityFactory->setRelations($this->relations());
        }
    }
}