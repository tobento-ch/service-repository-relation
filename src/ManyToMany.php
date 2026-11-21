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

use Closure;
use Exception;
use Tobento\Service\Iterable\Iter;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Storage\StorageInterface;

/**
 * ManyToMany: e.g. an article has many tags, using a pivot table.
 *
 * Ids are stored and found. With a related repository, the related entities can be found too.
 * All queries are filtered by the owner and related type if set,
 * so several relations can share the same pivot table.
 */
class ManyToMany implements StorageAwareRelationInterface, DeletesWithOwnerInterface, ResolvableRelationInterface
{
    use LoadsRelatedEntities;

    /**
     * Create a new ManyToMany.
     *
     * @param string $name The relation name, e.g. 'tags'.
     * @param string $table The pivot table, e.g. 'resource_relations'.
     * @param null|string $ownerType The owner type stored in the pivot table, e.g. 'articles'.
     *        Null if the pivot table has no owner type column.
     * @param null|string $relatedType The related type stored in the pivot table, e.g. 'tags'.
     *        Null if the pivot table has no related type column.
     * @param null|StorageInterface $storage The storage of the pivot table.
     *        If null, the repository passes its own storage.
     * @param null|ReadRepositoryInterface|Closure(): ReadRepositoryInterface $repository The related repository,
     *        or a closure returning it, resolved on first use. Only needed to find the related entities.
     * @param string $ownerTypeColumn
     * @param string $ownerIdColumn
     * @param string $relatedTypeColumn
     * @param string $relatedIdColumn
     * @param string $ownerKey The key of the owner id in the owner items, e.g. 'id'.
     * @param string $relatedKey The key of the related entities, e.g. 'id'.
     * @param null|Closure(object): mixed $keyResolver Resolves the key of a related entity.
     * @param positive-int $chunkSize The max number of ids per query.
     */
    public function __construct(
        protected string $name,
        protected string $table,
        protected null|string $ownerType = null,
        protected null|string $relatedType = null,
        protected null|StorageInterface $storage = null,
        protected null|ReadRepositoryInterface|Closure $repository = null,
        protected string $ownerTypeColumn = 'owner_type',
        protected string $ownerIdColumn = 'owner_id',
        protected string $relatedTypeColumn = 'related_type',
        protected string $relatedIdColumn = 'related_id',
        protected string $ownerKey = 'id',
        protected string $relatedKey = 'id',
        protected null|Closure $keyResolver = null,
        protected int $chunkSize = 1000,
    ) {
        if (!is_null($storage)) {
            $this->registerTable($storage);
        }
    }

    /**
     * Returns the name.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the pivot table.
     *
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Returns the owner type or null if none.
     *
     * @return null|string
     */
    public function ownerType(): null|string
    {
        return $this->ownerType;
    }

    /**
     * Returns the related type or null if none.
     *
     * @return null|string
     */
    public function relatedType(): null|string
    {
        return $this->relatedType;
    }

    /**
     * Returns the owner type column of the pivot table.
     *
     * @return string
     */
    public function ownerTypeColumn(): string
    {
        return $this->ownerTypeColumn;
    }

    /**
     * Returns the owner id column of the pivot table.
     *
     * @return string
     */
    public function ownerIdColumn(): string
    {
        return $this->ownerIdColumn;
    }

    /**
     * Returns the related type column of the pivot table.
     *
     * @return string
     */
    public function relatedTypeColumn(): string
    {
        return $this->relatedTypeColumn;
    }

    /**
     * Returns the related id column of the pivot table.
     *
     * @return string
     */
    public function relatedIdColumn(): string
    {
        return $this->relatedIdColumn;
    }

    /**
     * Returns the key of the owner id in the owner items, e.g. 'id'.
     *
     * @return string
     */
    public function ownerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * Returns the key of the related entities, e.g. 'id'.
     *
     * @return string
     */
    public function relatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * Returns the pivot table columns, e.g. for the migration.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        $columns = [];

        if (!is_null($this->ownerType())) {
            $columns[] = $this->ownerTypeColumn;
        }

        $columns[] = $this->ownerIdColumn;

        if (!is_null($this->relatedType())) {
            $columns[] = $this->relatedTypeColumn;
        }

        $columns[] = $this->relatedIdColumn;

        return $columns;
    }

    /**
     * Returns the storage or null if none.
     *
     * @return null|StorageInterface
     */
    public function storage(): null|StorageInterface
    {
        return $this->storage;
    }

    /**
     * Returns a new instance with the given storage.
     *
     * @param StorageInterface $storage
     * @return static
     */
    public function withStorage(StorageInterface $storage): static
    {
        $new = clone $this;
        $new->storage = $storage;
        $new->registerTable($storage);
        return $new;
    }

    /**
     * Returns the related repository or null if none.
     *
     * @return null|ReadRepositoryInterface
     */
    public function repository(): null|ReadRepositoryInterface
    {
        return $this->resolveRepository();
    }

    /**
     * Returns the related entities of the given owner, in the order of the found ids.
     * Ids without entity are skipped.
     *
     * @param int|string $ownerId
     * @return array<int, object> e.g. [Tag 1, Tag 3]
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedForOwner(int|string $ownerId): array
    {
        try {
            $relatedIds = $this->findRelatedIdsForOwner(ownerId: $ownerId);
            $entities = $this->loadRelatedByIds(ids: $relatedIds);

            return $this->pickEntities(ids: $relatedIds, entities: $entities);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related entities grouped by the given owners, loaded at once.
     * Owners without related entities are not included, ids without entity are skipped.
     *
     * @param array<array-key, int|string> $ownerIds
     * @return array<int|string, array<int, object>> e.g. [5 => [Tag 1, Tag 3], 6 => [Tag 2]]
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedGroupedByOwner(array $ownerIds): array
    {
        try {
            $groupedIds = $this->findRelatedIdsGroupedByOwner(ownerIds: $ownerIds);

            if (empty($groupedIds)) {
                return [];
            }

            $allIds = [];

            foreach($groupedIds as $relatedIds) {
                foreach($relatedIds as $relatedId) {
                    $allIds[$relatedId] = $relatedId;
                }
            }

            $entities = $this->loadRelatedByIds(ids: array_values($allIds));
            $grouped = [];

            foreach($groupedIds as $ownerId => $relatedIds) {
                $picked = $this->pickEntities(ids: $relatedIds, entities: $entities);

                if (!empty($picked)) {
                    $grouped[$ownerId] = $picked;
                }
            }

            return $grouped;
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the items with the related entities assigned under the relation name, or [] if none.
     * Without a repository, the related ids are assigned instead of the entities.
     * Loads the related entities of all items at once. The item keys are kept
     * and an existing value under the relation name is replaced.
     *
     * @param iterable<array-key, array> $items The owner items, e.g. storage rows.
     * @return array<array-key, array>
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function resolveForItems(iterable $items): array
    {
        try {
            $items = Iter::toArray($items);

            // owner ids of all items, invalid and duplicate ids are filtered by the find methods:
            $ownerIds = array_column($items, $this->ownerKey);

            $grouped = is_null($this->repository())
                ? $this->findRelatedIdsGroupedByOwner(ownerIds: $ownerIds)
                : $this->findRelatedGroupedByOwner(ownerIds: $ownerIds);

            $name = $this->name();

            foreach($items as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $ownerId = $item[$this->ownerKey] ?? null;
                $item[$name] = is_int($ownerId) || is_string($ownerId) ? ($grouped[$ownerId] ?? []) : [];
                $items[$key] = $item;
            }

            return $items;
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related ids of the given owner.
     *
     * @param int|string $ownerId
     * @return array<int, int|string> e.g. [1, 3]
     * @throws RelationException If reading the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedIdsForOwner(int|string $ownerId): array
    {
        try {
            $ids = $this->query()
                ->where($this->ownerIdColumn, '=', $ownerId)
                ->column($this->relatedIdColumn)
                ->all();

            return $this->filterIds($ids);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related ids grouped by the given owners. Owners without related ids are not included.
     *
     * @param array<array-key, int|string> $ownerIds
     * @return array<int|string, array<int, int|string>> e.g. [5 => [1, 3], 6 => [2]]
     * @throws RelationException If reading the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedIdsGroupedByOwner(array $ownerIds): array
    {
        try {
            $grouped = [];

            foreach($this->chunkIds($ownerIds) as $chunk) {
                // only the needed columns, so a covering index can be used:
                $rows = $this->query()
                    ->select($this->ownerIdColumn, $this->relatedIdColumn)
                    ->whereIn($this->ownerIdColumn, $chunk)
                    ->get();

                foreach($rows as $row) {
                    $ownerId = $row[$this->ownerIdColumn] ?? null;
                    $relatedId = $row[$this->relatedIdColumn] ?? null;

                    if (
                        (is_int($ownerId) || is_string($ownerId))
                        && (is_int($relatedId) || is_string($relatedId))
                    ) {
                        $grouped[$ownerId][$relatedId] = $relatedId;
                    }
                }
            }

            return array_map(array_values(...), $grouped);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the owner ids of the given related.
     *
     * @param int|string $relatedId
     * @return array<int, int|string> e.g. [5]
     * @throws RelationException If reading the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function findOwnerIdsForRelated(int|string $relatedId): array
    {
        try {
            $ids = $this->query()
                ->where($this->relatedIdColumn, '=', $relatedId)
                ->column($this->ownerIdColumn)
                ->all();

            return $this->filterIds($ids);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the owner ids having any of the given related ids.
     *
     * @param array<array-key, int|string> $relatedIds
     * @return array<int, int|string> e.g. [5, 6]
     * @throws RelationException If reading the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function findOwnerIdsForRelatedIds(array $relatedIds): array
    {
        try {
            $ownerIds = [];

            foreach($this->chunkIds($relatedIds) as $chunk) {
                $ids = $this->query()
                    ->whereIn($this->relatedIdColumn, $chunk)
                    ->column($this->ownerIdColumn)
                    ->all();

                foreach($this->filterIds($ids) as $id) {
                    $ownerIds[$id] = $id;
                }
            }

            return array_values($ownerIds);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Updates the related ids of the given owner, so it has exactly the given related ids.
     * Only the removed rows are deleted and only the new rows are inserted.
     *
     * @param int|string $ownerId
     * @param array<array-key, int|string> $relatedIds
     * @return void
     * @throws RelationException If writing the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function updateRelatedForOwner(int|string $ownerId, array $relatedIds): void
    {
        try {
            $relatedIds = $this->filterIds($relatedIds);
            $existingIds = $this->findRelatedIdsForOwner(ownerId: $ownerId);

            $deleteIds = array_values(array_diff($existingIds, $relatedIds));
            $insertIds = array_values(array_diff($relatedIds, $existingIds));

            foreach($this->chunkIds($deleteIds) as $chunk) {
                $this->query()
                    ->where($this->ownerIdColumn, '=', $ownerId)
                    ->whereIn($this->relatedIdColumn, $chunk)
                    ->delete();
            }

            // each row needs one placeholder per column, so the insert has its own chunk size:
            foreach(array_chunk($insertIds, $this->insertChunkSize()) as $chunk) {
                $rows = [];

                foreach($chunk as $relatedId) {
                    $rows[] = $this->createRow(ownerId: $ownerId, relatedId: $relatedId);
                }

                $this->query()->insertItems($rows);
            }
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Deletes the related ids of the given owner.
     *
     * @param int|string $ownerId
     * @return void
     * @throws RelationException If deleting the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function deleteForOwner(int|string $ownerId): void
    {
        try {
            $this->deleteForOwners(ownerIds: [$ownerId]);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Deletes the related ids of the given owners.
     *
     * @param array<array-key, int|string> $ownerIds
     * @return void
     * @throws RelationException If deleting the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function deleteForOwners(array $ownerIds): void
    {
        try {
            foreach($this->chunkIds($ownerIds) as $chunk) {
                $this->query()->whereIn($this->ownerIdColumn, $chunk)->delete();
            }
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Deletes the owner ids of the given related, e.g. when a tag gets deleted.
     *
     * @param int|string $relatedId
     * @return void
     * @throws RelationException If deleting the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function deleteForRelated(int|string $relatedId): void
    {
        try {
            $this->deleteForRelatedIds(relatedIds: [$relatedId]);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Deletes the owner ids of the given related ids.
     *
     * @param array<array-key, int|string> $relatedIds
     * @return void
     * @throws RelationException If deleting the pivot rows fails, e.g. a database error or a misconfigured relation.
     */
    public function deleteForRelatedIds(array $relatedIds): void
    {
        try {
            foreach($this->chunkIds($relatedIds) as $chunk) {
                $this->query()->whereIn($this->relatedIdColumn, $chunk)->delete();
            }
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the storage with the pivot table and the type conditions applied.
     *
     * @return StorageInterface
     */
    protected function query(): StorageInterface
    {
        $storage = $this->storage();

        if (is_null($storage)) {
            throw new LogicException(sprintf(
                'The relation [%s] has no storage. Pass a storage or set the relation on a repository.',
                $this->name()
            ));
        }

        $query = $storage->table($this->table());

        if (!is_null($ownerType = $this->ownerType())) {
            $query->where($this->ownerTypeColumn, '=', $ownerType);
        }

        if (!is_null($relatedType = $this->relatedType())) {
            $query->where($this->relatedTypeColumn, '=', $relatedType);
        }

        return $query;
    }

    /**
     * Returns a new pivot row.
     *
     * @param int|string $ownerId
     * @param int|string $relatedId
     * @return array<string, int|string>
     */
    protected function createRow(int|string $ownerId, int|string $relatedId): array
    {
        $row = [];

        if (!is_null($ownerType = $this->ownerType())) {
            $row[$this->ownerTypeColumn] = $ownerType;
        }

        $row[$this->ownerIdColumn] = $ownerId;

        if (!is_null($relatedType = $this->relatedType())) {
            $row[$this->relatedTypeColumn] = $relatedType;
        }

        $row[$this->relatedIdColumn] = $relatedId;

        return $row;
    }

    /**
     * Registers the pivot table on the given storage,
     * so storages such as the InMemoryStorage know its columns.
     *
     * @param StorageInterface $storage
     * @return void
     */
    protected function registerTable(StorageInterface $storage): void
    {
        $storage->tables()->add($this->table(), $this->columns());
    }

    /**
     * Returns the entities of the given ids in the order of the ids, skipping ids without entity.
     *
     * @param array<array-key, int|string> $ids
     * @param array<int|string, object> $entities
     * @return array<int, object>
     */
    protected function pickEntities(array $ids, array $entities): array
    {
        $picked = [];

        foreach($ids as $id) {
            if (isset($entities[$id])) {
                $picked[] = $entities[$id];
            }
        }

        return $picked;
    }

    /**
     * Returns the max number of rows per insert, so the placeholders stay below the limit
     * of prepared statements (e.g. MySQL allows 65,535 per statement).
     *
     * @return positive-int
     */
    protected function insertChunkSize(): int
    {
        return max(1, min($this->chunkSize, intdiv(60000, count($this->columns()))));
    }

    /**
     * Returns the unique valid ids.
     *
     * @param array $ids
     * @return array<int, int|string>
     */
    protected function filterIds(array $ids): array
    {
        $filtered = [];

        foreach($ids as $id) {
            if (is_int($id) || is_string($id)) {
                $filtered[$id] = $id;
            }
        }

        return array_values($filtered);
    }

    /**
     * Returns the unique valid ids in chunks.
     *
     * @param array $ids
     * @return array<int, array<int, int|string>>
     */
    protected function chunkIds(array $ids): array
    {
        $ids = $this->filterIds($ids);

        if (empty($ids)) {
            return [];
        }

        return array_chunk($ids, max(1, $this->chunkSize));
    }
}