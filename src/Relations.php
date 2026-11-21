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

use ArrayIterator;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;
use Traversable;

/**
 * Relations
 */
class Relations implements RelationsInterface
{
    /**
     * @var array<string, RelationInterface>
     */
    protected array $relations = [];

    /**
     * Create a new Relations.
     *
     * @param RelationInterface ...$relations
     */
    public function __construct(
        RelationInterface ...$relations,
    ) {
        foreach($relations as $relation) {
            $this->relations[$relation->name()] = $relation;
        }
    }

    /**
     * Returns a new instance with the added relations.
     * A relation with the same name replaces the existing one.
     *
     * @param RelationInterface ...$relations
     * @return static
     */
    public function withAdded(RelationInterface ...$relations): static
    {
        $new = clone $this;

        foreach($relations as $relation) {
            $new->relations[$relation->name()] = $relation;
        }

        return $new;
    }

    /**
     * Returns true if a relation with the given name exists, otherwise false.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    /**
     * Returns the relation by name, optionally of the given type.
     *
     * @template T of RelationInterface
     * @param string $name
     * @param class-string<T> $type The expected relation type, e.g. ManyToOne::class.
     * @return T
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function get(string $name, string $type = RelationInterface::class): RelationInterface
    {
        $relation = $this->all()[$name] ?? throw new RelationNotFoundException(
            sprintf('Relation [%s] not found', $name)
        );
        
        if (! $relation instanceof $type) {
            throw new RelationNotFoundException(sprintf(
                'Relation [%s] must be a %s, %s given',
                $name,
                $type,
                $relation::class
            ));
        }
        
        return $relation;
    }
    
    /**
     * Returns the many to one relation by name.
     *
     * @param string $name
     * @return ManyToOne
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getManyToOne(string $name): ManyToOne
    {
        return $this->get($name, ManyToOne::class);
    }
    
    /**
     * Returns the many to one by type relation by name.
     *
     * @param string $name
     * @return ManyToOneByType
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getManyToOneByType(string $name): ManyToOneByType
    {
        return $this->get($name, ManyToOneByType::class);
    }
    
    /**
     * Returns the many to many relation by name.
     *
     * @param string $name
     * @return ManyToMany
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getManyToMany(string $name): ManyToMany
    {
        return $this->get($name, ManyToMany::class);
    }
    
    /**
     * Returns the one to many relation by name.
     *
     * @param string $name
     * @return OneToMany
     * @throws RelationNotFoundException If no relation with the given name and type exists.
     */
    public function getOneToMany(string $name): OneToMany
    {
        return $this->get($name, OneToMany::class);
    }
    
    /**
     * Returns a new instance with only the relations of the given names.
     * Unknown names are ignored.
     *
     * @param string ...$names
     * @return static
     */
    public function only(string ...$names): static
    {
        $new = clone $this;
        $new->relations = array_intersect_key($this->all(), array_flip($names));
        return $new;
    }
    
    /**
     * Returns a new instance without the relations of the given names.
     * Unknown names are ignored.
     *
     * @param string ...$names
     * @return static
     */
    public function except(string ...$names): static
    {
        $new = clone $this;
        $new->relations = array_diff_key($this->all(), array_flip($names));
        return $new;
    }

    /**
     * Returns a new instance with the filtered relations.
     *
     * @param callable(RelationInterface): bool $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $new = clone $this;
        $new->relations = array_filter($this->all(), $callback);
        return $new;
    }

    /**
     * Returns all relations keyed by name.
     *
     * @return array<string, RelationInterface>
     */
    public function all(): array
    {
        return $this->relations;
    }

    /**
     * Returns the number of relations.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Returns an iterator for the relations.
     *
     * @return Traversable<string, RelationInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }
}