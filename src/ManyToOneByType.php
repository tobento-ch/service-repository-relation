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
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationException;

/**
 * ManyToOneByType: a many to one relation whose related repository is picked by a type column,
 * e.g. a comment belonging to an article or a page (comments.commentable_type, comments.commentable_id).
 *
 * Also known as polymorphic relation (Laravel: morphTo, Cycle: BelongsToMorphed).
 * Items with an unknown type or without related entity get null.
 */
class ManyToOneByType implements ResolvableRelationInterface
{
    /**
     * The related repositories by type, closures are resolved on first use.
     *
     * @var array<string, ReadRepositoryInterface|Closure(): ReadRepositoryInterface>
     */
    protected array $repositories = [];

    /**
     * Create a new ManyToOneByType.
     *
     * @param string $name The relation name, e.g. 'commentable'.
     * @param string $typeColumn The owner column holding the type, e.g. 'commentable_type'.
     * @param string $foreignKey The owner column holding the related id, e.g. 'commentable_id'.
     * @param array<string, ReadRepositoryInterface|Closure(): ReadRepositoryInterface> $repositories
     *        The related repositories by type, or closures returning them, resolved on first use,
     *        e.g. ['articles' => fn () => $articleRepository, 'pages' => fn () => $pageRepository].
     *        The types must stay stable, as they are stored in the type column.
     * @param string $relatedKey The key of the related entities, e.g. 'id'.
     * @param null|Closure(object): mixed $keyResolver Resolves the key of a related entity.
     * @param null|Closure(mixed): mixed $typeResolver Resolves the type of an owner item,
     *        e.g. fn (Comment $comment) => $comment->commentableType()
     * @param null|Closure(mixed): mixed $foreignKeyResolver Resolves the foreign key of an owner item,
     *        e.g. fn (Comment $comment) => $comment->commentableId()
     * @param positive-int $chunkSize The max number of ids per query.
     * @throws InvalidArgumentException If a type is no non-numeric string,
     *         or a repository is no ReadRepositoryInterface or Closure.
     */
    public function __construct(
        protected string $name,
        protected string $typeColumn,
        protected string $foreignKey,
        array $repositories,
        protected string $relatedKey = 'id',
        protected null|Closure $keyResolver = null,
        protected null|Closure $typeResolver = null,
        protected null|Closure $foreignKeyResolver = null,
        protected int $chunkSize = 1000,
    ) {
        foreach($repositories as $type => $repository) {
            // numeric string keys become int keys in PHP arrays:
            if (!is_string($type) || $type === '') {
                throw new InvalidArgumentException(sprintf(
                    'The types of the relation [%s] must be non-empty, non-numeric strings',
                    $name
                ));
            }

            if (
                ! $repository instanceof ReadRepositoryInterface
                && ! $repository instanceof Closure
            ) {
                throw new InvalidArgumentException(sprintf(
                    'The repository of the type [%s] of the relation [%s] must be a %s or Closure',
                    $type,
                    $name,
                    ReadRepositoryInterface::class
                ));
            }

            $this->repositories[$type] = $repository;
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
     * Returns the type column.
     *
     * @return string
     */
    public function typeColumn(): string
    {
        return $this->typeColumn;
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
     * Returns the types, e.g. ['articles', 'pages'].
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->repositories);
    }

    /**
     * Returns true if the given type has a repository, otherwise false.
     *
     * @param string $type
     * @return bool
     */
    public function hasType(string $type): bool
    {
        return array_key_exists($type, $this->repositories);
    }

    /**
     * Returns the related repository of the given type.
     *
     * @param string $type
     * @return ReadRepositoryInterface
     */
    public function repository(string $type): ReadRepositoryInterface
    {
        if (!$this->hasType($type)) {
            throw new LogicException(sprintf(
                'The relation [%s] has no repository for the type [%s]',
                $this->name(),
                $type
            ));
        }

        $repository = $this->repositories[$type];

        if ($repository instanceof Closure) {
            $repository = $repository();

            if (! $repository instanceof ReadRepositoryInterface) {
                throw new InvalidArgumentException(sprintf(
                    'The repository closure of the type [%s] of the relation [%s] must return a %s',
                    $type,
                    $this->name(),
                    ReadRepositoryInterface::class
                ));
            }

            $this->repositories[$type] = $repository;
        }

        return $repository;
    }

    /**
     * Returns the related entity of the given owner item or null if none or the type is unknown.
     *
     * @param array|object $item The owner item holding the type and the foreign key, e.g. a storage item (array),
     *        an ArrayAccess object or any object if the type and foreign key resolvers are set.
     * @return null|object
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedForItem(array|object $item): null|object
    {
        try {
            $type = $this->resolveType($item);
            $id = $this->resolveForeignKey($item);

            if (is_null($type) || is_null($id) || !$this->hasType($type)) {
                return null;
            }

            return $this->repository($type)->findById(id: $id);
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related entities of the given owner items, grouped by type and keyed by the related id.
     * Loads the related entities at once, one query per type (and chunk).
     *
     * @param iterable<array|object> $items The owner items holding the type and the foreign key.
     *        Items whose type or foreign key cannot be resolved, or with an unknown type, are skipped.
     * @return array<string, array<int|string, object>> e.g. ['articles' => [3 => Article], 'pages' => [7 => Page]]
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findRelatedForItems(iterable $items): array
    {
        try {
            $idsByType = [];

            foreach($items as $item) {
                $type = $this->resolveType($item);
                $id = $this->resolveForeignKey($item);

                if (!is_null($type) && !is_null($id) && $this->hasType($type)) {
                    $idsByType[$type][$id] = $id;
                }
            }

            $related = [];

            foreach($idsByType as $type => $ids) {
                $related[$type] = $this->findByIds(type: $type, ids: array_values($ids));
            }

            return $related;
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

                $type = $this->resolveType($item);
                $id = $this->resolveForeignKey($item);
                $item[$name] = is_null($type) || is_null($id) ? null : ($related[$type][$id] ?? null);
                $items[$key] = $item;
            }

            return $items;
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the related entities of the given type and ids, keyed by the related id.
     * Loads them in chunks, ids without entity are skipped.
     *
     * @param string $type
     * @param array<array-key, int|string> $ids
     * @return array<int|string, object>
     * @throws RelationException If reading the related entities fails, e.g. a database error or a misconfigured relation.
     */
    public function findByIds(string $type, array $ids): array
    {
        try {
            if (empty($ids)) {
                return [];
            }

            $repository = $this->repository($type);
            $entities = [];

            foreach(array_chunk($ids, max(1, $this->chunkSize)) as $chunk) {
                foreach($repository->findByIds(...$chunk) as $entity) {
                    $key = $this->resolveRelatedKey($entity);

                    if (!is_null($key)) {
                        $entities[$key] = $entity;
                    }
                }
            }

            return $entities;
        } catch (Exception $e) {
            throw RelationException::wrap($this->name(), $e);
        }
    }

    /**
     * Returns the type of the given owner item or null if not resolvable.
     *
     * @param mixed $item
     * @return null|string
     */
    protected function resolveType(mixed $item): null|string
    {
        if (!is_null($this->typeResolver)) {
            $type = ($this->typeResolver)($item);
        } elseif (is_array($item) || $item instanceof ArrayAccess) {
            $type = $item[$this->typeColumn()] ?? null;
        } else {
            $type = null;
        }

        return is_string($type) && $type !== '' ? $type : null;
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

    /**
     * Returns the key of the given related entity or null if not resolvable.
     *
     * @param object $entity
     * @return null|int|string
     */
    protected function resolveRelatedKey(object $entity): null|int|string
    {
        if (!is_null($this->keyResolver)) {
            $key = ($this->keyResolver)($entity);
        } elseif ($entity instanceof ArrayAccess) {
            $key = $entity[$this->relatedKey] ?? null;
        } elseif (method_exists($entity, $this->relatedKey)) {
            $key = $entity->{$this->relatedKey}();
        } else {
            $key = null;
        }

        return is_int($key) || is_string($key) ? $key : null;
    }
}