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
 * RelationsAwareEntityFactoryInterface: for entity factories using the relations of the repository,
 * e.g. to load related entities in batch.
 */
interface RelationsAwareEntityFactoryInterface
{
    /**
     * Sets the relations.
     *
     * @param RelationsInterface $relations
     * @return void
     */
    public function setRelations(RelationsInterface $relations): void;
}