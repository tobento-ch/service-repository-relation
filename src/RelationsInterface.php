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

use Countable;
use IteratorAggregate;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;

/**
 * RelationsInterface
 *
 * @extends IteratorAggregate<string, RelationInterface>
 */
interface RelationsInterface extends IteratorAggregate, Countable
{
    /**
     * Returns a new instance with the added relations.
     * A relation with the same name replaces the existing one.
     *
     * @param RelationInterface ...$relations
     * @return static
     */
    public function withAdded(RelationInterface ...$relations): static;
    
    /**
     * Returns true if a relation with the given name exists, otherwise false.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;
    
    /**
     * Returns the relation by name, optionally of the given type.
     *
     * @template T of RelationInterface
     * @param string $name
     * @param class-string<T> $type The expected relation type, e.g. ManyToOne::class.
     * @return T
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function get(string $name, string $type = RelationInterface::class): RelationInterface;
    
    /**
     * Returns the many to one relation by name.
     *
     * @param string $name
     * @return ManyToOne
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getManyToOne(string $name): ManyToOne;
    
    /**
     * Returns the many to one by type relation by name.
     *
     * @param string $name
     * @return ManyToOneByType
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getManyToOneByType(string $name): ManyToOneByType;
    
    /**
     * Returns the many to many relation by name.
     *
     * @param string $name
     * @return ManyToMany
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getManyToMany(string $name): ManyToMany;
    
    /**
     * Returns the one to many relation by name.
     *
     * @param string $name
     * @return OneToMany
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getOneToMany(string $name): OneToMany;
    
    /**
     * Returns a new instance with only the relations of the given names.
     * Unknown names are ignored.
     *
     * @param string ...$names
     * @return static
     */
    public function only(string ...$names): static;
    
    /**
     * Returns a new instance without the relations of the given names.
     * Unknown names are ignored.
     *
     * @param string ...$names
     * @return static
     */
    public function except(string ...$names): static;
    
    /**
     * Returns a new instance with the filtered relations.
     *
     * @param callable(RelationInterface): bool $callback
     * @return static
     */
    public function filter(callable $callback): static;
    
    /**
     * Returns all relations keyed by name.
     *
     * @return array<string, RelationInterface>
     */
    public function all(): array;
}