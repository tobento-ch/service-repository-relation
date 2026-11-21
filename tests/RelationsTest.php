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

namespace Tobento\Service\Repository\Relation\Test;

use Countable;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToManyInverse;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\ManyToOneByType;
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\Relations;
use Tobento\Service\Repository\Relation\RelationsInterface;

class RelationsTest extends TestCase
{
    protected function createRelations(): Relations
    {
        return new Relations(
            new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => null),
            new ManyToMany(name: 'tags', table: 'resource_relations'),
            new OneToMany(name: 'comments', foreignKey: 'article_id', repository: fn () => null),
            new ManyToOneByType(name: 'owner', typeColumn: 'owner_type', foreignKey: 'owner_id', repositories: []),
        );
    }

    public function testImplementsInterfaces(): void
    {
        $relations = new Relations();

        $this->assertInstanceOf(RelationsInterface::class, $relations);
        $this->assertInstanceOf(IteratorAggregate::class, $relations);
        $this->assertInstanceOf(Countable::class, $relations);
    }

    public function testAllMethod(): void
    {
        $this->assertSame([], new Relations()->all());
        $this->assertSame(['author', 'tags', 'comments', 'owner'], array_keys($this->createRelations()->all()));
    }

    public function testConstructorKeepsTheLastRelationWithTheSameName(): void
    {
        $first = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => null);
        $second = new ManyToOne(name: 'author', foreignKey: 'writer_id', repository: fn () => null);

        $this->assertSame(['author' => $second], new Relations($first, $second)->all());
    }

    public function testWithAddedMethod(): void
    {
        $relations = new Relations(new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => null));
        $tags = new ManyToMany(name: 'tags', table: 'resource_relations');
        $author = new ManyToOne(name: 'author', foreignKey: 'writer_id', repository: fn () => null);

        $newRelations = $relations->withAdded($tags, $author);

        // a new instance, the relation with the same name is replaced:
        $this->assertNotSame($relations, $newRelations);
        $this->assertSame(['author'], array_keys($relations->all()));
        $this->assertSame(['author' => $author, 'tags' => $tags], $newRelations->all());
        $this->assertSame('author_id', $relations->getManyToOne('author')->foreignKey());
    }

    public function testHasMethod(): void
    {
        $relations = $this->createRelations();

        $this->assertTrue($relations->has('author'));
        $this->assertTrue($relations->has('owner'));
        $this->assertFalse($relations->has('unknown'));
        $this->assertFalse($relations->has('Author'));
        $this->assertFalse(new Relations()->has('author'));
    }

    public function testGetMethod(): void
    {
        $relations = $this->createRelations();

        $this->assertSame('author', $relations->get('author')->name());
        $this->assertSame($relations->all()['tags'], $relations->get('tags'));
        $this->assertSame($relations->all()['tags'], $relations->get('tags', ManyToMany::class));
        $this->assertSame($relations->all()['tags'], $relations->get('tags', RelationInterface::class));
    }

    public function testGetMethodThrowsRelationNotFoundExceptionIfNotFound(): void
    {
        $this->expectException(RelationNotFoundException::class);
        $this->expectExceptionMessage('Relation [unknown] not found');

        $this->createRelations()->get('unknown');
    }

    public function testGetMethodThrowsRelationNotFoundExceptionIfWrongType(): void
    {
        $this->expectException(RelationNotFoundException::class);
        $this->expectExceptionMessage(sprintf('Relation [author] must be a %s, %s given', ManyToMany::class, ManyToOne::class));

        $this->createRelations()->get('author', ManyToMany::class);
    }

    public function testTypedGetMethods(): void
    {
        $relations = $this->createRelations();

        $this->assertInstanceOf(ManyToOne::class, $relations->getManyToOne('author'));
        $this->assertInstanceOf(ManyToMany::class, $relations->getManyToMany('tags'));
        $this->assertInstanceOf(OneToMany::class, $relations->getOneToMany('comments'));
        $this->assertInstanceOf(ManyToOneByType::class, $relations->getManyToOneByType('owner'));
    }

    public function testTypedGetMethodsThrowRelationNotFoundExceptionIfWrongType(): void
    {
        $relations = $this->createRelations();

        foreach ([
            fn () => $relations->getManyToOne('tags'),
            fn () => $relations->getManyToMany('author'),
            fn () => $relations->getOneToMany('owner'),
            fn () => $relations->getManyToOneByType('comments'),
            fn () => $relations->getManyToOne('unknown'),
        ] as $method) {
            try {
                $method();
                $this->fail('No exception thrown');
            } catch (RelationNotFoundException $e) {
                $this->assertStringStartsWith('Relation [', $e->getMessage());
            }
        }
    }

    public function testGetManyToManyMethodReturnsTheInverse(): void
    {
        $relations = new Relations(new ManyToManyInverse(name: 'articles', table: 'resource_relations'));

        $this->assertInstanceOf(ManyToManyInverse::class, $relations->getManyToMany('articles'));
    }

    public function testOnlyMethod(): void
    {
        $relations = $this->createRelations();
        $newRelations = $relations->only('tags', 'author', 'unknown');

        // a new instance, the order of the relations is kept:
        $this->assertNotSame($relations, $newRelations);
        $this->assertSame(['author', 'tags'], array_keys($newRelations->all()));
        $this->assertSame(4, $relations->count());
        $this->assertSame([], $relations->only()->all());
    }

    public function testExceptMethod(): void
    {
        $relations = $this->createRelations();
        $newRelations = $relations->except('tags', 'unknown');

        $this->assertNotSame($relations, $newRelations);
        $this->assertSame(['author', 'comments', 'owner'], array_keys($newRelations->all()));
        $this->assertSame(4, $relations->count());
        $this->assertSame(4, $relations->except()->count());
    }

    public function testFilterMethod(): void
    {
        $relations = $this->createRelations();
        $newRelations = $relations->filter(fn (RelationInterface $relation): bool => $relation instanceof ManyToOne);

        $this->assertNotSame($relations, $newRelations);
        $this->assertSame(['author'], array_keys($newRelations->all()));
        $this->assertSame(4, $relations->count());
    }

    public function testCountMethod(): void
    {
        $this->assertSame(0, new Relations()->count());
        $this->assertSame(4, $this->createRelations()->count());
        $this->assertCount(4, $this->createRelations());
    }

    public function testIteration(): void
    {
        $names = [];

        foreach ($this->createRelations() as $name => $relation) {
            $names[$name] = $relation->name();
        }

        $this->assertSame(['author' => 'author', 'tags' => 'tags', 'comments' => 'comments', 'owner' => 'owner'], $names);
    }
}