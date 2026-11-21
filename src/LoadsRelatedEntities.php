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

/**
 * LoadsRelatedEntities: loads the related entities by ids using the related repository.
 *
 * Uses the ResolvesRelatedRepository trait for the repository.
 *
 * The using class must provide the properties:
 * - null|ReadRepositoryInterface|Closure $repository The related repository or a closure returning it.
 * - string $relatedKey The key of the related entities, e.g. 'id'.
 * - null|Closure $keyResolver Resolves the key of a related entity.
 * - int $chunkSize The max number of ids per query.
 */
trait LoadsRelatedEntities
{
    use ResolvesRelatedRepository;

    /**
     * Returns the related entities of the given ids, keyed by the related id.
     * Loads them in chunks, ids without entity are skipped.
     *
     * @param array<array-key, int|string> $ids
     * @return array<int|string, object>
     */
    protected function loadRelatedByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $repository = $this->requireRepository();
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