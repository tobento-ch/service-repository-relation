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
use Tobento\Service\Iterable\Iter;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\Exception\RelationException;

/**
 * OneToMany: e.g. an author having many articles, the foreign key is in the related rows (articles.author_id).
 *
 * The other side of the ManyToOne. It only reads: deleting the owner (e.g. the author)
 * does not delete or change the related entities (e.g. the articles).
 */
class OneToMany implements ResolvableRelationInterface
{
    use ResolvesRelatedRepository;

    /**
     * Create a new OneToMany.
     *
     * @param string $name The relation name, e.g. 'articles'.
     * @param string $foreignKey The column of the related entities holding the owner id, e.g. 'author_id'.
     * @param ReadRepositoryInterface|Closure(): ReadRepositoryInterface $repository The related repository,
     *        or a closure returning it, resolved on first use.
     * @param array $where Additional where parameters for the related entities, e.g. ['status' => 'published'].
     * @param array $orderBy The order of the related entities per owner, e.g. ['date_created' => 'desc'].
     * @param string $ownerKey The key of the owner id in the owner items, e.g. 'id'.
     * @param null|Closure(object): mixed $foreignKeyResolver Resolves the foreign key of a related entity,
     *        e.g. fn (Article $article) => $article->authorId()
     * @param positive-int $chunkSize The max number of owner ids per query.
     */
    public function __construct(
        protected string $name,
        protected string $foreignKey,
        protected ReadRepositoryInterface|Closure $repository,
        protected array $where = [],
        protected array $orderBy = [],
        protected string $ownerKey = 'id',
        protected null|Closure $foreignKeyResolver = null,
        protected int $chunkSize = 1000,
    ) {}

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
     * Returns the foreign key.
     *
     * @return string
     */
    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Returns the additional where parameters for the related entities.
     *
     * @return array
     */
    public function where(): array
    {
        return $this->where;
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
     * Returns the related repository.
     *
     * @return ReadRepositoryInterface
     */
    public function repository(): ReadRepositoryInterface
    {
        return $this->requireRepository();
    }

    /**
     * Returns the related entities of the given owner, e.g. the articles of an author.
     *
     * @param int|string $ownerId
     * @return array<int, object>
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedForOwner(int|string $ownerId): array
    {
        try {
            $where = array_merge($this->where, [$this->foreignKey() => $ownerId]);

            return array_values(Iter::toArray(
                $this->repository()->findAll(where: $where, orderBy: $this->orderBy)
            ));
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related entities grouped by the given owners, loaded at once (in chunks).
     * Owners without related entities are not included.
     *
     * @param array<array-key, int|string> $ownerIds
     * @return array<int|string, array<int, object>> e.g. [7 => [Article 1, Article 3], 9 => [Article 2]]
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedGroupedByOwner(array $ownerIds): array
    {
        try {
            $grouped = [];

            foreach($this->chunkIds($ownerIds) as $chunk) {
                $where = array_merge($this->where, [$this->foreignKey() => ['in' => $chunk]]);

                foreach($this->repository()->findAll(where: $where, orderBy: $this->orderBy) as $entity) {
                    $ownerId = $this->resolveForeignKey($entity);

                    if (!is_null($ownerId)) {
                        $grouped[$ownerId][] = $entity;
                    }
                }
            }

            return $grouped;
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the items with the related entities assigned under the relation name, or [] if none.
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

            // owner ids of all items, invalid and duplicate ids are filtered by chunkIds():
            $grouped = $this->findRelatedGroupedByOwner(ownerIds: array_column($items, $this->ownerKey));

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
     * Returns the foreign key (the owner id) of the given related entity or null if not resolvable.
     *
     * @param object $entity
     * @return null|int|string
     */
    protected function resolveForeignKey(object $entity): null|int|string
    {
        if (!is_null($this->foreignKeyResolver)) {
            $id = ($this->foreignKeyResolver)($entity);
        } elseif ($entity instanceof ArrayAccess) {
            $id = $entity[$this->foreignKey()] ?? null;
        } else {
            $id = null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Returns the unique valid ids in chunks.
     *
     * @param array $ids
     * @return array<int, array<int, int|string>>
     */
    protected function chunkIds(array $ids): array
    {
        $filtered = [];

        foreach($ids as $id) {
            if (is_int($id) || is_string($id)) {
                $filtered[$id] = $id;
            }
        }

        if (empty($filtered)) {
            return [];
        }

        return array_chunk(array_values($filtered), max(1, $this->chunkSize));
    }
}