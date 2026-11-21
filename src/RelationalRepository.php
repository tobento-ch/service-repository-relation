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

use ArrayAccess;
use Closure;
use Exception;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\PdoAwareInterface;
use Tobento\Service\Storage\StorageInterface;

/**
 * RelationalRepository: finds the entities of a repository by conditions on its related entities,
 * e.g. the published articles having the tag 'php' and an author from country 5.
 *
 * Read only. Per relation in the related where, the related ids are found by the related repository
 * (so its where handling applies, e.g. translated columns), then the entities are found by one query
 * of the repository, so sorting and limit apply to the whole result. A pivot table in the same storage
 * as the repository is queried by a subquery, otherwise the owner ids are passed as list.
 *
 * Supports the ManyToMany (and ManyToManyInverse), ManyToOne and OneToMany relations.
 * Like unknown columns of the storage, unknown relation names, values not being an array and relations
 * not supported are ignored, so the related where may come from user input, e.g. a list filter.
 */
class RelationalRepository
{
    /**
     * Create a new RelationalRepository.
     *
     * @param ReadRepositoryInterface&RelationsAwareInterface $repository The repository of the entities
     *        to find, e.g. the article repository, providing the relations used by the related where.
     */
    public function __construct(
        protected ReadRepositoryInterface&RelationsAwareInterface $repository,
    ) {}

    /**
     * Returns the repository.
     *
     * @return ReadRepositoryInterface&RelationsAwareInterface
     */
    public function repository(): ReadRepositoryInterface&RelationsAwareInterface
    {
        return $this->repository;
    }

    /**
     * Returns the found entities.
     *
     * @param array $where The where parameters of the entities, e.g. ['status' => 'published'].
     * @param array<string, array> $relatedWhere The where parameters of the related entities by relation name,
     *        e.g. ['tags' => ['slug' => 'php'], 'author' => ['country_id' => 5]]. All must match.
     *        Unknown relation names, values not being an array and relations not supported are ignored.
     * @param array $orderBy The order by parameters.
     * @param null|int|array $limit The limit e.g. 5 or [5(number), 10(offset)].
     * @return iterable<object>
     * @throws RelationException If querying by related fails, e.g. a database error or a misconfigured relation.
     */
    public function findAll(
        array $where = [],
        array $relatedWhere = [],
        array $orderBy = [],
        null|int|array $limit = null,
    ): iterable {
        $where = $this->buildWhere(where: $where, relatedWhere: $relatedWhere);

        if (is_null($where)) {
            return [];
        }

        // the main query, holding the subqueries of the pivot tables in the same storage:
        try {
            return $this->repository()->findAll(where: $where, orderBy: $orderBy, limit: $limit);
        } catch (Exception $e) {
            throw RelationException::wrap(implode(', ', array_keys($relatedWhere)), $e);
        }
    }

    /**
     * Returns the found entity or null if none.
     *
     * @param array $where The where parameters of the entity.
     * @param array<string, array> $relatedWhere The where parameters of the related entities by relation name.
     * @return null|object
     * @throws RelationException If querying by related fails, e.g. a database error or a misconfigured relation.
     */
    public function findOne(array $where = [], array $relatedWhere = []): null|object
    {
        $where = $this->buildWhere(where: $where, relatedWhere: $relatedWhere);

        if (is_null($where)) {
            return null;
        }

        try {
            return $this->repository()->findOne(where: $where);
        } catch (Exception $e) {
            throw RelationException::wrap(implode(', ', array_keys($relatedWhere)), $e);
        }
    }

    /**
     * Returns the number of the found entities.
     *
     * @param array $where The where parameters of the entities.
     * @param array<string, array> $relatedWhere The where parameters of the related entities by relation name.
     * @return int
     * @throws RelationException If querying by related fails, e.g. a database error or a misconfigured relation.
     */
    public function count(array $where = [], array $relatedWhere = []): int
    {
        $where = $this->buildWhere(where: $where, relatedWhere: $relatedWhere);

        if (is_null($where)) {
            return 0;
        }

        try {
            return $this->repository()->count(where: $where);
        } catch (Exception $e) {
            throw RelationException::wrap(implode(', ', array_keys($relatedWhere)), $e);
        }
    }

    /**
     * Returns the where parameters of the repository with the conditions of the related where added,
     * or null if no entity can match (e.g. no tag found). Useful for other repository methods.
     *
     * @param array $where The where parameters of the entities.
     * @param array<string, array> $relatedWhere The where parameters of the related entities by relation name.
     * @return null|array
     * @throws RelationException If querying by related fails, e.g. a database error or a misconfigured relation.
     */
    public function buildWhere(array $where = [], array $relatedWhere = []): null|array
    {
        foreach($relatedWhere as $name => $relatedParameters) {
            // ignored like unknown columns, as the related where may come from user input:
            if (
                !is_string($name)
                || !is_array($relatedParameters)
                || !$this->repository()->relations()->has($name)
            ) {
                continue;
            }

            $relation = $this->repository()->relations()->get($name);

            try {
                $condition = match (true) {
                    $relation instanceof ManyToMany => $this->manyToManyCondition($relation, $relatedParameters),
                    $relation instanceof ManyToOne => $this->manyToOneCondition($relation, $relatedParameters),
                    $relation instanceof OneToMany => $this->oneToManyCondition($relation, $relatedParameters),
                    // not supported, e.g. the ManyToOneByType:
                    default => false,
                };
            } catch (Exception $e) {
                throw RelationException::wrap($name, $e);
            }

            if ($condition === false) {
                continue;
            }

            if (is_null($condition)) {
                return null;
            }

            [$column, $values] = $condition;

            // as group, so several conditions on the same column (e.g. id) are possible:
            $where[] = [$column => ['in' => $values]];
        }

        return $where;
    }

    /**
     * Returns the condition [column, values] of a many to many relation,
     * or null if no related entity matches.
     *
     * @param ManyToMany $relation
     * @param array $relatedWhere
     * @return null|array{0: string, 1: array|Closure}
     */
    protected function manyToManyCondition(ManyToMany $relation, array $relatedWhere): null|array
    {
        $repository = $relation->repository() ?? throw new LogicException(sprintf(
            'The relation [%s] has no repository to query the related entities.',
            $relation->name()
        ));

        $relatedIds = $this->findIds(repository: $repository, where: $relatedWhere, key: $relation->relatedKey());

        if (empty($relatedIds)) {
            return null;
        }

        $pivotStorage = $relation->storage() ?? throw new LogicException(sprintf(
            'The relation [%s] has no storage. Pass a storage or set the relation on a repository.',
            $relation->name()
        ));

        // pivot table in the same storage: subquery, the owner ids stay in the database
        if ($this->isSameStorage($pivotStorage)) {
            $this->registerPivotTable($relation);

            $subquery = function (StorageInterface $query) use ($relation, $relatedIds): StorageInterface {
                $query->table($relation->table())->select($relation->ownerIdColumn());

                if (!is_null($ownerType = $relation->ownerType())) {
                    $query->where($relation->ownerTypeColumn(), '=', $ownerType);
                }

                if (!is_null($relatedType = $relation->relatedType())) {
                    $query->where($relation->relatedTypeColumn(), '=', $relatedType);
                }

                return $query->whereIn($relation->relatedIdColumn(), $relatedIds);
            };

            return [$relation->ownerKey(), $subquery];
        }

        // pivot table in another storage: the owner ids as list
        $ownerIds = $relation->findOwnerIdsForRelatedIds(relatedIds: $relatedIds);

        if (empty($ownerIds)) {
            return null;
        }

        return [$relation->ownerKey(), $ownerIds];
    }

    /**
     * Returns the condition [column, values] of a many to one relation,
     * or null if no related entity matches.
     *
     * @param ManyToOne $relation
     * @param array $relatedWhere
     * @return null|array{0: string, 1: array}
     */
    protected function manyToOneCondition(ManyToOne $relation, array $relatedWhere): null|array
    {
        $relatedIds = $this->findIds(
            repository: $relation->repository(),
            where: $relatedWhere,
            key: $relation->relatedKey(),
        );

        if (empty($relatedIds)) {
            return null;
        }

        return [$relation->foreignKey(), $relatedIds];
    }

    /**
     * Returns the condition [column, values] of a one to many relation,
     * or null if no related entity matches.
     *
     * @param OneToMany $relation
     * @param array $relatedWhere
     * @return null|array{0: string, 1: array}
     */
    protected function oneToManyCondition(OneToMany $relation, array $relatedWhere): null|array
    {
        // the owner ids are the foreign keys of the matching related entities:
        $ownerIds = $this->findIds(
            repository: $relation->repository(),
            where: array_merge($relation->where(), $relatedWhere),
            key: $relation->foreignKey(),
        );

        if (empty($ownerIds)) {
            return null;
        }

        return [$relation->ownerKey(), $ownerIds];
    }

    /**
     * Returns the unique values of the given key of the entities found by the given repository.
     *
     * @param ReadRepositoryInterface $repository
     * @param array $where
     * @param string $key The key of the values, e.g. 'id' or 'author_id'.
     * @return array<int, int|string>
     */
    protected function findIds(ReadRepositoryInterface $repository, array $where, string $key): array
    {
        // storage repositories: only the column is read
        if ($repository instanceof StorageRepository) {
            $values = $repository->findColumn(column: $key, where: $where);
        } else {
            $values = [];

            foreach($repository->findAll(where: $where) as $entity) {
                if ($entity instanceof ArrayAccess) {
                    $values[] = $entity[$key] ?? null;
                } elseif (method_exists($entity, $key)) {
                    $values[] = $entity->{$key}();
                }
            }
        }

        $ids = [];

        foreach($values as $value) {
            if (is_int($value) || is_string($value)) {
                $ids[$value] = $value;
            }
        }

        return array_values($ids);
    }

    /**
     * Returns true if the given storage is the storage of the repository, or uses the same PDO connection.
     *
     * @param StorageInterface $storage
     * @return bool
     */
    protected function isSameStorage(StorageInterface $storage): bool
    {
        $repository = $this->repository();

        if (! $repository instanceof StorageRepository) {
            return false;
        }

        $repositoryStorage = $repository->storage();

        if ($repositoryStorage === $storage) {
            return true;
        }

        return $repositoryStorage instanceof PdoAwareInterface
            && $storage instanceof PdoAwareInterface
            && $repositoryStorage->pdo() === $storage->pdo();
    }

    /**
     * Registers the pivot table of the given relation on the storage of the repository if unknown,
     * as the storage only queries known tables. Needed if the pivot storage is another instance
     * using the same PDO connection.
     *
     * @param ManyToMany $relation
     * @return void
     */
    protected function registerPivotTable(ManyToMany $relation): void
    {
        $repository = $this->repository();

        if (! $repository instanceof StorageRepository) {
            return;
        }

        $tables = $repository->storage()->tables();

        if (is_null($tables->getTable($relation->table()))) {
            $tables->add($relation->table(), $relation->columns());
        }
    }
}