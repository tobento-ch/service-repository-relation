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
 * ManyToOne: e.g. many articles have one author, the foreign key is in the owner row (articles.author_id).
 */
class ManyToOne implements ResolvableRelationInterface
{
    use LoadsRelatedEntities;

    /**
     * Create a new ManyToOne.
     *
     * @param string $name The relation name, e.g. 'author'.
     * @param string $foreignKey The owner column holding the related id, e.g. 'author_id'.
     * @param ReadRepositoryInterface|Closure(): ReadRepositoryInterface $repository The related repository,
     *        or a closure returning it, resolved on first use.
     * @param string $relatedKey The key of the related entities, e.g. 'id'.
     * @param null|Closure(object): mixed $keyResolver Resolves the key of a related entity.
     * @param null|Closure(mixed): mixed $foreignKeyResolver Resolves the foreign key of an owner item,
     *        e.g. fn (Article $article) => $article->authorId()
     * @param positive-int $chunkSize The max number of ids per query.
     */
    public function __construct(
        protected string $name,
        protected string $foreignKey,
        protected ReadRepositoryInterface|Closure $repository,
        protected string $relatedKey = 'id',
        protected null|Closure $keyResolver = null,
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
     * Returns the key of the related entities, e.g. 'id'.
     *
     * @return string
     */
    public function relatedKey(): string
    {
        return $this->relatedKey;
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
     * Returns the related entity of the given owner item or null if none.
     *
     * @param array|object $item The owner item holding the foreign key, e.g. a storage item (array),
     *        an ArrayAccess object or any object if a foreign key resolver is set.
     * @return null|object
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedForItem(array|object $item): null|object
    {
        try {
            $id = $this->resolveForeignKey($item);

            if (is_null($id)) {
                return null;
            }

            return $this->repository()->findById(id: $id);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related entities of the given owner items, keyed by the related id.
     * Loads all related entities at once, in chunks.
     *
     * @param iterable<array|object> $items The owner items holding the foreign key, e.g. storage items (arrays),
     *        ArrayAccess objects or any objects if a foreign key resolver is set.
     *        Items whose foreign key cannot be resolved are skipped.
     * @return array<int|string, object> e.g. [3 => Author, 7 => Author]
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedForItems(iterable $items): array
    {
        try {
            $ids = [];

            foreach($items as $item) {
                $id = $this->resolveForeignKey($item);

                if (!is_null($id)) {
                    $ids[$id] = $id;
                }
            }

            return $this->findByIds(ids: array_values($ids));
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the items with the related entity assigned under the relation name, or null if none.
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
            $related = $this->findRelatedForItems($items);
            $name = $this->name();

            foreach($items as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $this->resolveForeignKey($item);
                $item[$name] = is_null($id) ? null : ($related[$id] ?? null);
                $items[$key] = $item;
            }

            return $items;
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related entities of the given ids, keyed by the related id.
     *
     * @param array<array-key, int|string> $ids
     * @return array<int|string, object>
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findByIds(array $ids): array
    {
        try {
            return $this->loadRelatedByIds(ids: $ids);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the foreign key of the given owner item or null if not resolvable.
     *
     * @param mixed $item
     * @return null|int|string
     */
    protected function resolveForeignKey(mixed $item): null|int|string
    {
        if (!is_null($this->foreignKeyResolver)) {
            $id = ($this->foreignKeyResolver)($item);
        } elseif (is_array($item) || $item instanceof ArrayAccess) {
            $id = $item[$this->foreignKey()] ?? null;
        } else {
            $id = null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

}