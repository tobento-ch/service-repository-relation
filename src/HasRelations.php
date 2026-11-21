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
 * HasRelations: provides the relations to a repository.
 */
trait HasRelations
{
    /**
     * @var RelationsInterface
     */
    protected RelationsInterface $relations;
    
    /**
     * Returns the relations.
     *
     * @return RelationsInterface
     */
    public function relations(): RelationsInterface
    {
        return $this->relations;
    }
    
    /**
     * Process relations.
     *
     * @param null|iterable<RelationInterface>|RelationsInterface $relations
     * @return RelationsInterface
     */
    protected function processRelations(null|iterable|RelationsInterface $relations): RelationsInterface
    {
        if (is_null($relations)) {
            $relations = $this->configureRelations();
        }
        
        if ($relations instanceof RelationsInterface) {
            return $relations;
        }
        
        $items = [];
        
        foreach($relations as $relation) {
            $items[] = $relation;
        }
        
        return new Relations(...$items);
    }
    
    /**
     * Returns the configured relations.
     *
     * @return iterable<RelationInterface>|RelationsInterface
     */
    protected function configureRelations(): iterable|RelationsInterface
    {
        return [];
    }
}