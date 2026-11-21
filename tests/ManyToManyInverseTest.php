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
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToManyInverse;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\ResolvableRelationInterface;
use Tobento\Service\Repository\Relation\StorageAwareRelationInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\StorageInterface;

class ManyToManyInverseTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'articles' => [
                1 => ['id' => 1, 'title' => 'Hello'],
                2 => ['id' => 2, 'title' => 'World'],
                3 => ['id' => 3, 'title' => 'Again'],
            ],
        ]);
    }

    protected function createArticleRepository(StorageInterface $storage): ReadRepositoryInterface
    {
        return new class(
            storage: $storage,
            table: 'articles',
            columns: [new Column\Id(), new Column\Text(name: 'title')],
        ) extends StorageRepository {};
    }

    // the relation on the article repository, writing the pivot rows:
    protected function createTags(StorageInterface $storage): ManyToMany
    {
        return new ManyToMany(
            name: 'tags',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
            storage: $storage,
        );
    }

    // the inverse on the tag repository, with the same parameters:
    protected function createArticles(
        null|StorageInterface $storage = null,
        null|ReadRepositoryInterface $repository = null,
    ): ManyToManyInverse {
        return new ManyToManyInverse(
            name: 'articles',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
            storage: $storage,
            repository: $repository,
        );
    }

    /**
     * @param iterable<object> $entities
     * @return array<int, string>
     */
    protected function titles(iterable $entities): array
    {
        $titles = [];

        foreach ($entities as $entity) {
            $titles[] = $entity['title'];
        }

        return $titles;
    }

    public function testImplementsInterfaces(): void
    {
        $relation = $this->createArticles();

        $this->assertInstanceOf(ManyToMany::class, $relation);
        $this->assertInstanceOf(RelationInterface::class, $relation);
        $this->assertInstanceOf(ResolvableRelationInterface::class, $relation);
        $this->assertInstanceOf(StorageAwareRelationInterface::class, $relation);
        $this->assertInstanceOf(DeletesWithOwnerInterface::class, $relation);
    }

    public function testOwnerAndRelatedAreSwapped(): void
    {
        $relation = $this->createArticles();

        $this->assertSame('articles', $relation->name());
        $this->assertSame('resource_relations', $relation->table());
        $this->assertSame('tags', $relation->ownerType());
        $this->assertSame('articles', $relation->relatedType());
        $this->assertSame('related_type', $relation->ownerTypeColumn());
        $this->assertSame('related_id', $relation->ownerIdColumn());
        $this->assertSame('owner_type', $relation->relatedTypeColumn());
        $this->assertSame('owner_id', $relation->relatedIdColumn());
    }

    public function testReadsThePivotRowsWrittenByTheOtherSide(): void
    {
        $storage = $this->createStorage();
        $tags = $this->createTags($storage);
        $articles = $this->createArticles(storage: $storage);

        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [1]);

        // the articles of the tag 1 and 2:
        $this->assertSame([1, 2], $articles->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([1], $articles->findRelatedIdsForOwner(ownerId: 2));
        $this->assertSame([1 => [1, 2], 2 => [1]], $articles->findRelatedIdsGroupedByOwner(ownerIds: [1, 2, 3]));

        // the tags having the article 2:
        $this->assertSame([1], $articles->findOwnerIdsForRelated(relatedId: 2));
    }

    public function testReadFindRelatedForOwnerMethod(): void
    {
        $storage = $this->createStorage();
        $this->createTags($storage)->updateRelatedForOwner(ownerId: 1, relatedIds: [1]);
        $this->createTags($storage)->updateRelatedForOwner(ownerId: 3, relatedIds: [1]);

        $articles = $this->createArticles(storage: $storage, repository: $this->createArticleRepository($storage));

        $this->assertSame(['Hello', 'Again'], $this->titles($articles->findRelatedForOwner(ownerId: 1)));
        $this->assertSame([], $articles->findRelatedForOwner(ownerId: 2));
    }

    public function testReadResolveForItemsMethod(): void
    {
        $storage = $this->createStorage();
        $tags = $this->createTags($storage);
        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [1]);

        $articles = $this->createArticles(storage: $storage, repository: $this->createArticleRepository($storage));

        // the tags with their articles:
        $items = $articles->resolveForItems([
            ['id' => 1, 'name' => 'php'],
            ['id' => 2, 'name' => 'cms'],
            ['id' => 3, 'name' => 'js'],
        ]);

        $this->assertSame(['Hello', 'World'], $this->titles($items[0]['articles']));
        $this->assertSame(['Hello'], $this->titles($items[1]['articles']));
        $this->assertSame([], $items[2]['articles']);
    }

    public function testWriteFromThisSide(): void
    {
        $storage = $this->createStorage();
        $tags = $this->createTags($storage);
        $articles = $this->createArticles(storage: $storage);

        // the articles of the tag 2:
        $articles->updateRelatedForOwner(ownerId: 2, relatedIds: [1, 3]);

        $this->assertSame([2], $tags->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([], $tags->findRelatedIdsForOwner(ownerId: 2));
        $this->assertSame([2], $tags->findRelatedIdsForOwner(ownerId: 3));
    }

    public function testDeleteForOwnerMethodDeletesTheRowsOfTheTag(): void
    {
        $storage = $this->createStorage();
        $tags = $this->createTags($storage);
        $articles = $this->createArticles(storage: $storage);

        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [1, 2]);

        // e.g. when the tag 1 gets deleted:
        $articles->deleteForOwner(ownerId: 1);

        $this->assertSame([2], $tags->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([2], $tags->findRelatedIdsForOwner(ownerId: 2));
    }

    public function testTypesSeparateTheRelations(): void
    {
        $storage = $this->createStorage();
        $this->createTags($storage)->updateRelatedForOwner(ownerId: 1, relatedIds: [1]);

        $categoryArticles = new ManyToManyInverse(
            name: 'articles',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'categories',
            storage: $storage,
        );

        $this->assertSame([], $categoryArticles->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([1], $this->createArticles(storage: $storage)->findRelatedIdsForOwner(ownerId: 1));
    }

    public function testWithCustomColumnsAndWithoutTypes(): void
    {
        $storage = $this->createStorage();

        $tags = new ManyToMany(
            name: 'tags',
            table: 'article_tag',
            storage: $storage,
            ownerIdColumn: 'article_id',
            relatedIdColumn: 'tag_id',
        );

        $articles = new ManyToManyInverse(
            name: 'articles',
            table: 'article_tag',
            storage: $storage,
            ownerIdColumn: 'article_id',
            relatedIdColumn: 'tag_id',
        );

        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [5, 6]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [5]);

        $this->assertSame('tag_id', $articles->ownerIdColumn());
        $this->assertSame([1, 2], $articles->findRelatedIdsForOwner(ownerId: 5));
        $this->assertSame([1], $articles->findRelatedIdsForOwner(ownerId: 6));
    }
}