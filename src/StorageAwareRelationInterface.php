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

use Tobento\Service\Storage\StorageInterface;

/**
 * StorageAwareRelationInterface: for relations storing data, e.g. a pivot table.
 * If the relation has no storage, the repository passes its own storage.
 */
interface StorageAwareRelationInterface extends RelationInterface
{
    /**
     * Returns the storage or null if none.
     *
     * @return null|StorageInterface
     */
    public function storage(): null|StorageInterface;
    
    /**
     * Returns a new instance with the given storage.
     *
     * @param StorageInterface $storage
     * @return static
     */
    public function withStorage(StorageInterface $storage): static;
}