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
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Relation\ResolvableRelationInterface;
use Tobento\Service\Repository\Relation\StorageAwareRelationInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\EntityFactory;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\StorageInterface;

class OneToManyTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'authors' => [
                1 => ['id' => 1, 'name' => 'John'],
                2 => ['id' => 2, 'name' => 'Mia'],
                3 => ['id' => 3, 'name' => 'Without articles'],
            ],
            'articles' => [
                1 => ['id' => 1, 'title' => 'Hello', 'status' => 'published', 'position' => 2, 'author_id' => 1],
                2 => ['id' => 2, 'title' => 'World', 'status' => 'published', 'position' => 1, 'author_id' => 2],
                3 => ['id' => 3, 'title' => 'Again', 'status' => 'published', 'position' => 1, 'author_id' => 1],
                4 => ['id' => 4, 'title' => 'Draft', 'status' => 'draft', 'position' => 3, 'author_id' => 1],
                5 => ['id' => 5, 'title' => 'No author', 'status' => 'published', 'position' => 1, 'author_id' => null],
            ],
        ]);
    }

    /**
     * @return array<int, Column\ColumnInterface>
     */
    protected function articleColumns(): array
    {
        return [
            new Column\Id(),
            new Column\Text(name: 'title'),
            new Column\Text(name: 'status'),
            new Column\Integer(name: 'position'),
            new Column\Integer(name: 'author_id')->type(nullable: true),
        ];
    }

    protected function createArticleRepository(StorageInterface $storage): StorageRepository
    {
        return new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
        ) extends StorageRepository {};
    }

    protected function createAuthorRepository(
        StorageInterface $storage,
        ReadRepositoryInterface $articleRepository,
    ): RelationStorageRepository {
        return new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name')],
            entityFactory: new RelationEntityFactory(),
            relations: [
                new OneToMany(
                    name: 'articles',
                    foreignKey: 'author_id',
                    repository: $articleRepository,
                    orderBy: ['position' => 'asc'],
                ),
            ],
        ) extends RelationStorageRepository {};
    }

    protected function createRelation(
        StorageInterface $storage,
        array $where = [],
        array $orderBy = ['position' => 'asc'],
        int $chunkSize = 1000,
    ): OneToMany {
        return new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: $this->createArticleRepository($storage),
            where: $where,
            orderBy: $orderBy,
            chunkSize: $chunkSize,
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

    /**
     * @param array<array-key, array<int, object>> $grouped
     * @return array<array-key, array<int, string>>
     */
    protected function groupedTitles(array $grouped): array
    {
        ksort($grouped);
        return array_map(fn (array $entities): array => $this->titles($entities), $grouped);
    }

    public function testImplementsInterfaces(): void
    {
        $relation = new OneToMany(name: 'articles', foreignKey: 'author_id', repository: fn () => null);

        $this->assertInstanceOf(RelationInterface::class, $relation);
        $this->assertInstanceOf(ResolvableRelationInterface::class, $relation);
        $this->assertFalse($relation instanceof StorageAwareRelationInterface);
    }

    public function testGetterMethods(): void
    {
        $relation = new OneToMany(name: 'articles', foreignKey: 'author_id', repository: fn () => null);

        $this->assertSame('articles', $relation->name());
        $this->assertSame('author_id', $relation->foreignKey());
        $this->assertSame([], $relation->where());
        $this->assertSame('id', $relation->ownerKey());

        $relation = new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: fn () => null,
            where: ['status' => 'published'],
            ownerKey: 'author_id',
        );

        $this->assertSame(['status' => 'published'], $relation->where());
        $this->assertSame('author_id', $relation->ownerKey());
    }

    public function testRepositoryMethod(): void
    {
        $articleRepository = $this->createArticleRepository($this->createStorage());
        $relation = new OneToMany(name: 'articles', foreignKey: 'author_id', repository: $articleRepository);

        $this->assertSame($articleRepository, $relation->repository());
    }

    public function testRepositoryMethodResolvesClosureOnce(): void
    {
        $articleRepository = $this->createArticleRepository($this->createStorage());
        $calls = 0;

        $relation = new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: function () use ($articleRepository, &$calls): ReadRepositoryInterface {
                $calls++;
                return $articleRepository;
            },
        );

        $this->assertSame($articleRepository, $relation->repository());
        $this->assertSame($articleRepository, $relation->repository());
        $this->assertSame(1, $calls);
    }

    public function testRepositoryMethodThrowsInvalidArgumentExceptionIfClosureReturnsNoRepository(): void
    {
        $relation = new OneToMany(name: 'articles', foreignKey: 'author_id', repository: fn () => 'foo');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The repository closure of the relation [articles] must return a');

        $relation->repository();
    }

    public function testReadResolving(): void
    {
        $storage = $this->createStorage();
        $authorRepository = $this->createAuthorRepository($storage, $this->createArticleRepository($storage));
        $authors = $authorRepository->withResolvedRelations('articles');

        $this->assertSame(['Again', 'Hello', 'Draft'], $this->titles($authors->findById(1)['articles']));
        $this->assertSame(['World'], $this->titles($authors->findById(2)['articles']));
        $this->assertSame([], $authors->findById(3)['articles']);
    }

    public function testReadResolvingIsNotDoneByDefault(): void
    {
        $storage = $this->createStorage();
        $authorRepository = $this->createAuthorRepository($storage, $this->createArticleRepository($storage));

        $this->assertFalse(isset($authorRepository->findById(1)['articles']));
    }

    public function testReadFindRelatedForOwnerMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $this->assertSame(['Again', 'Hello', 'Draft'], $this->titles($relation->findRelatedForOwner(ownerId: 1)));
        $this->assertSame([0], array_keys($relation->findRelatedForOwner(ownerId: 2)));
        $this->assertSame([], $relation->findRelatedForOwner(ownerId: 3));
        $this->assertSame([], $relation->findRelatedForOwner(ownerId: 99));
    }

    public function testReadWithWhereAndOrderBy(): void
    {
        $relation = $this->createRelation(
            $this->createStorage(),
            where: ['status' => 'published'],
            orderBy: ['position' => 'desc'],
        );

        $this->assertSame(['Hello', 'Again'], $this->titles($relation->findRelatedForOwner(ownerId: 1)));
        $this->assertSame([1 => ['Hello', 'Again'], 2 => ['World']], $this->groupedTitles($relation->findRelatedGroupedByOwner(ownerIds: [1, 2])));
    }

    public function testReadWhereCannotOverwriteTheForeignKey(): void
    {
        $relation = $this->createRelation($this->createStorage(), where: ['author_id' => 2]);

        $this->assertSame(['Again', 'Hello', 'Draft'], $this->titles($relation->findRelatedForOwner(ownerId: 1)));
        $this->assertSame([1 => ['Again', 'Hello', 'Draft']], $this->groupedTitles($relation->findRelatedGroupedByOwner(ownerIds: [1])));
    }

    public function testReadFindRelatedGroupedByOwnerMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        // owners without related entities are not included, invalid ids are ignored:
        $this->assertSame(
            [1 => ['Again', 'Hello', 'Draft'], 2 => ['World']],
            $this->groupedTitles($relation->findRelatedGroupedByOwner(ownerIds: [1, 2, 3, 99, null, 1.5]))
        );

        $this->assertSame([], $relation->findRelatedGroupedByOwner(ownerIds: []));
        $this->assertSame([], $relation->findRelatedGroupedByOwner(ownerIds: [null, 1.5]));
    }

    public function testReadInChunksReturnsTheSameResults(): void
    {
        $relation = $this->createRelation($this->createStorage(), chunkSize: 1);

        $this->assertSame(
            [1 => ['Again', 'Hello', 'Draft'], 2 => ['World']],
            $this->groupedTitles($relation->findRelatedGroupedByOwner(ownerIds: [2, 1, 3]))
        );
    }

    public function testReadQueriesEachOwnerIdOnce(): void
    {
        $storage = $this->createStorage();
        $queries = [];

        // a repository recording the queried owner ids:
        $articleRepository = new class($storage, $this->articleColumns(), $queries) extends StorageRepository {
            public function __construct(StorageInterface $storage, array $columns, private array &$queries)
            {
                parent::__construct(storage: $storage, table: 'articles', columns: $columns);
            }

            public function findAll(array $where = [], array $orderBy = [], null|int|array $limit = null): iterable
            {
                $this->queries[] = $where['author_id']['in'];
                return parent::findAll(where: $where, orderBy: $orderBy, limit: $limit);
            }
        };

        $relation = new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: $articleRepository,
            chunkSize: 2,
        );

        $relation->resolveForItems([['id' => 1], ['id' => 2], ['id' => 1], ['id' => 3], ['id' => null], ['name' => 'No id']]);

        $this->assertSame([[1, 2], [3]], $queries);
    }

    public function testReadResolveForItemsMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $items = $relation->resolveForItems([
            'a' => ['id' => 1, 'name' => 'John'],
            'b' => ['id' => 2, 'name' => 'Mia', 'articles' => 'replaced'],
            'c' => ['id' => 3, 'name' => 'Without articles'],
            'd' => ['id' => null, 'name' => 'Without id'],
            'e' => ['name' => 'No id'],
        ]);

        // the keys are kept:
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_keys($items));
        $this->assertSame(['Again', 'Hello', 'Draft'], $this->titles($items['a']['articles']));
        $this->assertSame(['World'], $this->titles($items['b']['articles']));
        $this->assertSame([], $items['c']['articles']);
        $this->assertSame([], $items['d']['articles']);
        $this->assertSame([], $items['e']['articles']);
        $this->assertSame('John', $items['a']['name']);
    }

    public function testReadWithCustomOwnerKey(): void
    {
        $relation = new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: $this->createArticleRepository($this->createStorage()),
            orderBy: ['position' => 'asc'],
            ownerKey: 'author_id',
        );

        // e.g. profile rows holding the author id:
        $items = $relation->resolveForItems([
            ['id' => 7, 'author_id' => 2],
            ['id' => 8, 'author_id' => 1],
        ]);

        $this->assertSame(['World'], $this->titles($items[0]['articles']));
        $this->assertSame(['Again', 'Hello', 'Draft'], $this->titles($items[1]['articles']));
    }

    public function testReadOwnObjectsAsRelatedEntitiesUsingForeignKeyResolver(): void
    {
        $storage = $this->createStorage();

        // a repository creating own article objects without ArrayAccess:
        $articleRepository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            entityFactory: new class() extends EntityFactory {
                public function createEntityFromArray(array $attributes): object
                {
                    return new class($attributes['title'], $attributes['author_id']) {
                        public function __construct(private string $title, private null|int $authorId) {}

                        public function title(): string
                        {
                            return $this->title;
                        }

                        public function authorId(): null|int
                        {
                            return $this->authorId;
                        }
                    };
                }
            },
        ) extends StorageRepository {};

        $relation = new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: $articleRepository,
            orderBy: ['position' => 'asc'],
            foreignKeyResolver: fn (object $article): mixed => $article->authorId(),
        );

        $grouped = $relation->findRelatedGroupedByOwner(ownerIds: [1, 2]);

        $this->assertSame('World', $grouped[2][0]->title());
        $this->assertSame(['Again', 'Hello', 'Draft'], array_map(fn (object $article): string => $article->title(), $grouped[1]));

        // without the resolver the foreign key cannot be resolved and the entities are skipped:
        $relation = new OneToMany(name: 'articles', foreignKey: 'author_id', repository: $articleRepository);

        $this->assertSame([], $relation->findRelatedGroupedByOwner(ownerIds: [1, 2]));
    }

    public function testWrite(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createArticleRepository($storage);
        $authors = $this->createAuthorRepository($storage, $articleRepository)->withResolvedRelations('articles');

        // the foreign key is set on the related side:
        $articleRepository->create(['title' => 'New', 'status' => 'published', 'position' => 1, 'author_id' => 3]);
        $articleRepository->updateById(2, ['author_id' => 3]);

        $this->assertSame(['World', 'New'], $this->titles($authors->findById(3)['articles']));
        $this->assertSame([], $authors->findById(2)['articles']);
    }

    public function testDeleteDoesNotChangeTheOtherSide(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createArticleRepository($storage);
        $authorRepository = $this->createAuthorRepository($storage, $articleRepository);

        // deleting an author keeps its articles with its id:
        $authorRepository->deleteById(1);

        $this->assertSame(1, $articleRepository->findById(1)['author_id']);
        $this->assertSame(['Again', 'Hello', 'Draft'], $this->titles($this->createRelation($storage)->findRelatedForOwner(ownerId: 1)));

        // deleting an article removes it from its author:
        $articleRepository->deleteById(2);

        $this->assertSame([], $authorRepository->withResolvedRelations('articles')->findById(2)['articles']);
    }

    public function testThrowsRelationExceptionIfClosureReturnsNoRepository(): void
    {
        $relation = new OneToMany(name: 'articles', foreignKey: 'author_id', repository: fn () => 'foo');

        try {
            $relation->findRelatedForOwner(ownerId: 1);
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            $this->assertSame(
                'Relation [articles] failed: The repository closure of the relation [articles] must return a '.ReadRepositoryInterface::class,
                $e->getMessage()
            );
        }
    }
}