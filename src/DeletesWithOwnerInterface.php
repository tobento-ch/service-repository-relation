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

/**
 * DeletesWithOwnerInterface: for relations whose data is deleted with the owner,
 * e.g. the pivot rows of a many to many relation.
 */
interface DeletesWithOwnerInterface extends RelationInterface
{
    /**
     * Deletes the relation data of the given owners.
     *
     * @param array<array-key, int|string> $ownerIds
     * @return void
     */
    public function deleteForOwners(array $ownerIds): void;
}