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

use PHPUnit\Framework\TestCase;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\DeletesWithOwnerInterface;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\ResolvableRelationInterface;
use Tobento\Service\Repository\Relation\StorageAwareRelationInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\StorageInterface;

class ManyToManyTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'tags' => [
                1 => ['id' => 1, 'name' => 'php'],
                2 => ['id' => 2, 'name' => 'cms'],
                3 => ['id' => 3, 'name' => 'js'],
            ],
        ]);
    }

    protected function createTagRepository(StorageInterface $storage): ReadRepositoryInterface
    {
        return new class(
            storage: $storage,
            table: 'tags',
            columns: [new Column\Id(), new Column\Text(name: 'name')],
        ) extends StorageRepository {};
    }

    protected function createRelation(
        null|StorageInterface $storage = null,
        null|ReadRepositoryInterface $repository = null,
        int $chunkSize = 1000,
    ): ManyToMany {
        return new ManyToMany(
            name: 'tags',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
            storage: $storage,
            repository: $repository,
            chunkSize: $chunkSize,
        );
    }

    /**
     * @param iterable<object> $entities
     * @return array<int, string>
     */
    protected function names(iterable $entities): array
    {
        $names = [];

        foreach ($entities as $entity) {
            $names[] = $entity['name'];
        }

        return $names;
    }

    public function testImplementsInterfaces(): void
    {
        $relation = $this->createRelation();

        $this->assertInstanceOf(RelationInterface::class, $relation);
        $this->assertInstanceOf(ResolvableRelationInterface::class, $relation);
        $this->assertInstanceOf(StorageAwareRelationInterface::class, $relation);
        $this->assertInstanceOf(DeletesWithOwnerInterface::class, $relation);
    }

    public function testGetterMethods(): void
    {
        $relation = $this->createRelation();

        $this->assertSame('tags', $relation->name());
        $this->assertSame('resource_relations', $relation->table());
        $this->assertSame('articles', $relation->ownerType());
        $this->assertSame('tags', $relation->relatedType());
        $this->assertSame('owner_type', $relation->ownerTypeColumn());
        $this->assertSame('owner_id', $relation->ownerIdColumn());
        $this->assertSame('related_type', $relation->relatedTypeColumn());
        $this->assertSame('related_id', $relation->relatedIdColumn());
        $this->assertSame('id', $relation->ownerKey());
        $this->assertSame('id', $relation->relatedKey());
    }

    public function testColumnsMethod(): void
    {
        $this->assertSame(
            ['owner_type', 'owner_id', 'related_type', 'related_id'],
            $this->createRelation()->columns()
        );

        $relation = new ManyToMany(
            name: 'tags',
            table: 'article_tag',
            ownerIdColumn: 'article_id',
            relatedIdColumn: 'tag_id',
        );

        $this->assertSame(['article_id', 'tag_id'], $relation->columns());
    }

    public function testStorageMethod(): void
    {
        $this->assertNull($this->createRelation()->storage());

        $storage = $this->createStorage();

        $this->assertSame($storage, $this->createRelation(storage: $storage)->storage());
    }

    public function testWithStorageMethodReturnsNewInstance(): void
    {
        $relation = $this->createRelation();
        $storage = $this->createStorage();
        $newRelation = $relation->withStorage($storage);

        $this->assertNotSame($relation, $newRelation);
        $this->assertNull($relation->storage());
        $this->assertSame($storage, $newRelation->storage());
    }

    public function testRepositoryMethod(): void
    {
        $this->assertNull($this->createRelation()->repository());

        $tagRepository = $this->createTagRepository($this->createStorage());

        $this->assertSame($tagRepository, $this->createRelation(repository: $tagRepository)->repository());
    }

    public function testRepositoryMethodResolvesClosureOnce(): void
    {
        $tagRepository = $this->createTagRepository($this->createStorage());
        $calls = 0;

        $relation = new ManyToMany(
            name: 'tags',
            table: 'resource_relations',
            repository: function () use ($tagRepository, &$calls): ReadRepositoryInterface {
                $calls++;
                return $tagRepository;
            },
        );

        $this->assertSame($tagRepository, $relation->repository());
        $this->assertSame($tagRepository, $relation->repository());
        $this->assertSame(1, $calls);
    }

    public function testWriteUpdateRelatedForOwnerMethod(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());

        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [3]);

        $this->assertSame([1, 2], $relation->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([3], $relation->findRelatedIdsForOwner(ownerId: 2));
    }

    public function testWriteUpdateRelatedForOwnerMethodUpdatesByDifference(): void
    {
        $storage = $this->createStorage();
        $relation = $this->createRelation(storage: $storage);

        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        // another owner, so new rows get new keys:
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [3]);
        $rowsBefore = $storage->table('resource_relations')->get()->all();

        // tag 1 stays, tag 2 is removed, tag 3 is added:
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 3]);
        $rowsAfter = $storage->table('resource_relations')->get()->all();

        $this->assertSame([1, 3], $relation->findRelatedIdsForOwner(ownerId: 1));

        // the row of tag 1 is unchanged, keeping its key, only the row of tag 2 is replaced:
        $this->assertSame(
            array_search(1, array_column($rowsBefore, 'related_id', 'id'), true),
            array_search(1, array_column($rowsAfter, 'related_id', 'id'), true)
        );
        $this->assertCount(3, $rowsAfter);
    }

    public function testWriteUpdateRelatedForOwnerMethodWithEmptyIdsRemovesAll(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());

        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: []);

        $this->assertSame([], $relation->findRelatedIdsForOwner(ownerId: 1));
    }

    public function testWriteUpdateRelatedForOwnerMethodIgnoresDuplicateAndInvalidIds(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());

        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 1, 2, null, 2.5, [], 2]);

        $this->assertSame([1, 2], $relation->findRelatedIdsForOwner(ownerId: 1));
    }

    public function testWriteTypesSeparateRelationsSharingTheTable(): void
    {
        $storage = $this->createStorage();
        $tags = $this->createRelation(storage: $storage);

        $categories = new ManyToMany(
            name: 'categories',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'categories',
            storage: $storage,
        );

        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $categories->updateRelatedForOwner(ownerId: 1, relatedIds: [5]);

        $this->assertSame([1, 2], $tags->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([5], $categories->findRelatedIdsForOwner(ownerId: 1));

        $categories->deleteForOwner(ownerId: 1);

        $this->assertSame([1, 2], $tags->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([], $categories->findRelatedIdsForOwner(ownerId: 1));
    }

    public function testWriteWithoutTypesAndCustomColumns(): void
    {
        $storage = $this->createStorage();

        $relation = new ManyToMany(
            name: 'tags',
            table: 'article_tag',
            storage: $storage,
            ownerIdColumn: 'article_id',
            relatedIdColumn: 'tag_id',
        );

        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);

        $this->assertSame([1, 2], $relation->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame(
            [['article_id' => 1, 'tag_id' => 1], ['article_id' => 1, 'tag_id' => 2]],
            array_values(array_map(
                fn (array $row): array => array_intersect_key($row, ['article_id' => 0, 'tag_id' => 0]),
                $storage->table('article_tag')->get()->all()
            ))
        );
    }

    public function testReadFindRelatedIdsGroupedByOwnerMethod(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [2, 3]);

        // owners without related ids are not included:
        $this->assertSame(
            [1 => [1, 2], 2 => [2, 3]],
            $relation->findRelatedIdsGroupedByOwner(ownerIds: [1, 2, 3])
        );
    }

    public function testReadFindOwnerIdsForRelatedMethods(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [2, 3]);

        $this->assertSame([1, 2], $relation->findOwnerIdsForRelated(relatedId: 2));
        $this->assertSame([], $relation->findOwnerIdsForRelated(relatedId: 9));

        // unique owner ids:
        $this->assertSame([1, 2], $relation->findOwnerIdsForRelatedIds(relatedIds: [1, 2, 3]));
        $this->assertSame([], $relation->findOwnerIdsForRelatedIds(relatedIds: []));
    }

    public function testReadFindRelatedForOwnerMethod(): void
    {
        $storage = $this->createStorage();
        $relation = $this->createRelation(storage: $storage, repository: $this->createTagRepository($storage));

        // tag 9 does not exist and is skipped:
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [2, 9, 1]);

        $this->assertSame(['cms', 'php'], $this->names($relation->findRelatedForOwner(ownerId: 1)));
        $this->assertSame([], $relation->findRelatedForOwner(ownerId: 2));
    }

    public function testReadFindRelatedGroupedByOwnerMethod(): void
    {
        $storage = $this->createStorage();
        $relation = $this->createRelation(storage: $storage, repository: $this->createTagRepository($storage));
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [3]);

        $grouped = $relation->findRelatedGroupedByOwner(ownerIds: [1, 2, 3]);

        $this->assertSame([1, 2], array_keys($grouped));
        $this->assertSame(['php', 'cms'], $this->names($grouped[1]));
        $this->assertSame(['js'], $this->names($grouped[2]));
    }

    public function testReadInChunksReturnsTheSameResults(): void
    {
        $storage = $this->createStorage();
        $relation = $this->createRelation(storage: $storage, repository: $this->createTagRepository($storage), chunkSize: 1);
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2, 3]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [3]);

        $this->assertSame([1 => [1, 2, 3], 2 => [3]], $relation->findRelatedIdsGroupedByOwner(ownerIds: [1, 2]));
        $this->assertSame([1, 2], $relation->findOwnerIdsForRelatedIds(relatedIds: [1, 3]));
        $this->assertSame(['php', 'cms', 'js'], $this->names($relation->findRelatedForOwner(ownerId: 1)));
    }

    public function testReadResolveForItemsMethodWithRepository(): void
    {
        $storage = $this->createStorage();
        $relation = $this->createRelation(storage: $storage, repository: $this->createTagRepository($storage));
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);

        $items = $relation->resolveForItems([
            'a' => ['id' => 1, 'title' => 'Hello'],
            'b' => ['id' => 2, 'title' => 'World', 'tags' => 'replaced'],
        ]);

        // the keys are kept:
        $this->assertSame(['a', 'b'], array_keys($items));
        $this->assertSame(['php', 'cms'], $this->names($items['a']['tags']));
        $this->assertSame([], $items['b']['tags']);
        $this->assertSame('Hello', $items['a']['title']);
    }

    public function testReadResolveForItemsMethodWithoutRepositoryAssignsIds(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);

        $items = $relation->resolveForItems([['id' => 1], ['id' => 2], ['title' => 'No id']]);

        $this->assertSame([1, 2], $items[0]['tags']);
        $this->assertSame([], $items[1]['tags']);
        $this->assertSame([], $items[2]['tags']);
    }

    public function testDeleteForOwnerMethods(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [2]);
        $relation->updateRelatedForOwner(ownerId: 3, relatedIds: [3]);

        $relation->deleteForOwner(ownerId: 1);

        $this->assertSame([2 => [2], 3 => [3]], $relation->findRelatedIdsGroupedByOwner(ownerIds: [1, 2, 3]));

        $relation->deleteForOwners(ownerIds: [2, 3]);

        $this->assertSame([], $relation->findRelatedIdsGroupedByOwner(ownerIds: [1, 2, 3]));
    }

    public function testDeleteForRelatedMethods(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2, 3]);
        $relation->updateRelatedForOwner(ownerId: 2, relatedIds: [2, 3]);

        $relation->deleteForRelated(relatedId: 3);

        $this->assertSame([1 => [1, 2], 2 => [2]], $relation->findRelatedIdsGroupedByOwner(ownerIds: [1, 2]));

        $relation->deleteForRelatedIds(relatedIds: [1, 2]);

        $this->assertSame([], $relation->findRelatedIdsGroupedByOwner(ownerIds: [1, 2]));
    }

    public function testThrowsRelationExceptionWithoutStorage(): void
    {
        $relation = $this->createRelation();

        try {
            $relation->findRelatedIdsForOwner(ownerId: 1);
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
            $this->assertSame(
                'Relation [tags] failed: The relation [tags] has no storage. Pass a storage or set the relation on a repository.',
                $e->getMessage()
            );
        }
    }

    public function testThrowsRelationExceptionFindingEntitiesWithoutRepository(): void
    {
        $relation = $this->createRelation(storage: $this->createStorage());
        $relation->updateRelatedForOwner(ownerId: 1, relatedIds: [1]);

        $this->expectException(RelationException::class);
        $this->expectExceptionMessage('has no repository');

        $relation->findRelatedForOwner(ownerId: 1);
    }
}