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
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Storage\StorageInterface;

/**
 * ManyToManyInverse: the inverse side of a many to many relation, read from the related side of the pivot rows,
 * e.g. a tag having many articles, while the pivot rows are stored from the articles side.
 *
 * The parameters are the same as of the ManyToMany on the other side (describing the pivot rows),
 * owner and related are swapped internally. So the methods work from this side,
 * e.g. findRelatedIdsForOwner(tagId) returns the article ids, and the repository having
 * this relation deletes the pivot rows of its deleted entities (e.g. tags).
 */
class ManyToManyInverse extends ManyToMany
{
    /**
     * Create a new ManyToManyInverse.
     *
     * @param string $name The relation name, e.g. 'articles'.
     * @param string $table The pivot table, e.g. 'resource_relations'.
     * @param null|string $ownerType The owner type stored in the pivot table, e.g. 'articles'.
     *        The same as of the ManyToMany on the other side.
     * @param null|string $relatedType The related type stored in the pivot table, e.g. 'tags'.
     *        The same as of the ManyToMany on the other side.
     * @param null|StorageInterface $storage The storage of the pivot table.
     *        If null, the repository passes its own storage.
     * @param null|ReadRepositoryInterface|Closure(): ReadRepositoryInterface $repository The repository
     *        of the pivot owners (e.g. the articles), or a closure returning it, resolved on first use.
     *        Only needed to find the entities.
     * @param string $ownerTypeColumn The same as of the ManyToMany on the other side.
     * @param string $ownerIdColumn The same as of the ManyToMany on the other side.
     * @param string $relatedTypeColumn The same as of the ManyToMany on the other side.
     * @param string $relatedIdColumn The same as of the ManyToMany on the other side.
     * @param string $ownerKey The key of the id in the items of this side, e.g. 'id' of the tags.
     * @param string $relatedKey The key of the entities of the repository, e.g. 'id' of the articles.
     * @param null|Closure(object): mixed $keyResolver Resolves the key of an entity of the repository.
     * @param positive-int $chunkSize The max number of ids per query.
     */
    public function __construct(
        string $name,
        string $table,
        null|string $ownerType = null,
        null|string $relatedType = null,
        null|StorageInterface $storage = null,
        null|ReadRepositoryInterface|Closure $repository = null,
        string $ownerTypeColumn = 'owner_type',
        string $ownerIdColumn = 'owner_id',
        string $relatedTypeColumn = 'related_type',
        string $relatedIdColumn = 'related_id',
        string $ownerKey = 'id',
        string $relatedKey = 'id',
        null|Closure $keyResolver = null,
        int $chunkSize = 1000,
    ) {
        // read the pivot rows from the related side:
        parent::__construct(
            name: $name,
            table: $table,
            ownerType: $relatedType,
            relatedType: $ownerType,
            storage: $storage,
            repository: $repository,
            ownerTypeColumn: $relatedTypeColumn,
            ownerIdColumn: $relatedIdColumn,
            relatedTypeColumn: $ownerTypeColumn,
            relatedIdColumn: $ownerIdColumn,
            ownerKey: $ownerKey,
            relatedKey: $relatedKey,
            keyResolver: $keyResolver,
            chunkSize: $chunkSize,
        );
    }
}