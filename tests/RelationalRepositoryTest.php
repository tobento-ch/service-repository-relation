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

use Closure;
use PHPUnit\Framework\TestCase;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToManyInverse;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\ManyToOneByType;
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationalRepository;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\StorageInterface;

class RelationalRepositoryTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'authors' => [
                1 => ['id' => 1, 'name' => 'John', 'country_id' => 5],
                2 => ['id' => 2, 'name' => 'Mia', 'country_id' => 6],
                3 => ['id' => 3, 'name' => 'Lea', 'country_id' => 5],
                4 => ['id' => 4, 'name' => 'John', 'country_id' => 7],
            ],
            'tags' => [
                1 => ['id' => 1, 'slug' => 'php'],
                2 => ['id' => 2, 'slug' => 'cms'],
                3 => ['id' => 3, 'slug' => 'unused'],
            ],
            'articles' => [
                1 => ['id' => 1, 'title' => 'One', 'status' => 'published', 'position' => 3, 'author_id' => 1],
                2 => ['id' => 2, 'title' => 'Two', 'status' => 'published', 'position' => 1, 'author_id' => 2],
                3 => ['id' => 3, 'title' => 'Three', 'status' => 'draft', 'position' => 2, 'author_id' => 1],
                4 => ['id' => 4, 'title' => 'Four', 'status' => 'published', 'position' => 4, 'author_id' => 3],
                5 => ['id' => 5, 'title' => 'Five', 'status' => 'published', 'position' => 5, 'author_id' => null],
            ],
            'comments' => [
                1 => ['id' => 1, 'text' => 'Nice', 'approved' => 1, 'article_id' => 1],
                2 => ['id' => 2, 'text' => 'Nice', 'approved' => 0, 'article_id' => 2],
                3 => ['id' => 3, 'text' => 'Great', 'approved' => 1, 'article_id' => 4],
                4 => ['id' => 4, 'text' => 'Nice', 'approved' => 1, 'article_id' => 1],
            ],
        ]);
    }

    protected function createTagRepository(StorageInterface $storage): StorageRepository
    {
        return new class(
            storage: $storage,
            table: 'tags',
            columns: [new Column\Id(), new Column\Text(name: 'slug')],
        ) extends StorageRepository {};
    }

    /**
     * Creates the article repository with its tags (php: 1, 2, 3, 5 and cms: 2, 4).
     *
     * @param StorageInterface $storage
     * @param null|StorageInterface $pivotStorage The storage of the tag pivot rows, null the article storage.
     * @param array<int, RelationInterface> $relations Additional relations.
     * @return RelationStorageRepository
     */
    protected function createArticleRepository(
        StorageInterface $storage,
        null|StorageInterface $pivotStorage = null,
        array $relations = [],
    ): RelationStorageRepository {
        $authorRepository = new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name'), new Column\Integer(name: 'country_id')],
        ) extends StorageRepository {};

        $commentRepository = new class(
            storage: $storage,
            table: 'comments',
            columns: [
                new Column\Id(),
                new Column\Text(name: 'text'),
                new Column\Integer(name: 'approved'),
                new Column\Integer(name: 'article_id'),
            ],
        ) extends StorageRepository {};

        $articleRepository = new class(
            storage: $storage,
            table: 'articles',
            columns: [
                new Column\Id(),
                new Column\Text(name: 'title'),
                new Column\Text(name: 'status'),
                new Column\Integer(name: 'position'),
                new Column\Integer(name: 'author_id')->type(nullable: true),
            ],
            entityFactory: new RelationEntityFactory(),
            relations: [
                new ManyToMany(
                    name: 'tags',
                    table: 'resource_relations',
                    ownerType: 'articles',
                    relatedType: 'tags',
                    repository: $this->createTagRepository($storage),
                    storage: $pivotStorage,
                ),
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository),
                new OneToMany(
                    name: 'comments',
                    foreignKey: 'article_id',
                    repository: $commentRepository,
                    where: ['approved' => 1],
                ),
                ...$relations,
            ],
        ) extends RelationStorageRepository {};

        $tags = $articleRepository->relations()->getManyToMany('tags');
        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 3, relatedIds: [1]);
        $tags->updateRelatedForOwner(ownerId: 4, relatedIds: [2]);
        $tags->updateRelatedForOwner(ownerId: 5, relatedIds: [1]);

        return $articleRepository;
    }

    protected function createRelationalRepository(
        null|StorageInterface $pivotStorage = null,
        array $relations = [],
    ): RelationalRepository {
        return new RelationalRepository(
            repository: $this->createArticleRepository($this->createStorage(), $pivotStorage, $relations),
        );
    }

    /**
     * @param iterable<object> $entities
     * @return array<int, int>
     */
    protected function ids(iterable $entities): array
    {
        $ids = [];

        foreach ($entities as $entity) {
            $ids[] = $entity['id'];
        }

        sort($ids);
        return $ids;
    }

    public function testRepositoryMethod(): void
    {
        $articleRepository = $this->createArticleRepository($this->createStorage());
        $relational = new RelationalRepository(repository: $articleRepository);

        $this->assertSame($articleRepository, $relational->repository());
    }

    public function testFindAllByManyToMany(): void
    {
        $articles = $this->createRelationalRepository();

        $this->assertSame([1, 2, 3, 5], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'php']])));
        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'cms']])));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => ['in' => ['php', 'cms']]]])));
    }

    public function testFindAllByManyToManyWithPivotInSameStorageUsesSubquery(): void
    {
        $articles = $this->createRelationalRepository();

        $where = $articles->buildWhere(relatedWhere: ['tags' => ['slug' => 'cms']]);

        $this->assertInstanceOf(Closure::class, $where[0]['id']['in']);
    }

    public function testFindAllByManyToManyWithPivotInOtherStorageUsesIds(): void
    {
        $articles = $this->createRelationalRepository(pivotStorage: new InMemoryStorage(items: []));

        $this->assertSame([['id' => ['in' => [2, 4]]]], $articles->buildWhere(relatedWhere: ['tags' => ['slug' => 'cms']]));
        $this->assertSame([1, 2, 3, 5], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'php']])));
    }

    public function testFindAllByManyToManyOnlyMatchesItsTypes(): void
    {
        $storage = $this->createStorage();

        // the categories share the pivot table and their ids with the tags:
        $articleRepository = $this->createArticleRepository($storage, relations: [
            new ManyToMany(
                name: 'categories',
                table: 'resource_relations',
                ownerType: 'articles',
                relatedType: 'categories',
                repository: $this->createTagRepository($storage),
            ),
        ]);

        $articleRepository->relations()->getManyToMany('categories')->updateRelatedForOwner(ownerId: 4, relatedIds: [1]);

        $articles = new RelationalRepository(repository: $articleRepository);

        $this->assertSame([4], $this->ids($articles->findAll(relatedWhere: ['categories' => ['slug' => 'php']])));
        $this->assertSame([1, 2, 3, 5], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'php']])));
    }

    public function testFindAllByManyToManyInverse(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createArticleRepository($storage);

        $tagRepository = new class(
            storage: $storage,
            table: 'tags',
            columns: [new Column\Id(), new Column\Text(name: 'slug')],
            relations: [
                new ManyToManyInverse(
                    name: 'articles',
                    table: 'resource_relations',
                    ownerType: 'articles',
                    relatedType: 'tags',
                    repository: $articleRepository,
                ),
            ],
        ) extends RelationStorageRepository {};

        $tags = new RelationalRepository(repository: $tagRepository);

        // the tags of the draft articles and of the article 4:
        $this->assertSame([1], $this->ids($tags->findAll(relatedWhere: ['articles' => ['status' => 'draft']])));
        $this->assertSame([2], $this->ids($tags->findAll(relatedWhere: ['articles' => ['id' => 4]])));
    }

    public function testFindAllByManyToOne(): void
    {
        $articles = $this->createRelationalRepository();

        $this->assertSame([1, 3, 4], $this->ids($articles->findAll(relatedWhere: ['author' => ['country_id' => 5]])));
        $this->assertSame([2], $this->ids($articles->findAll(relatedWhere: ['author' => ['name' => 'Mia']])));
    }

    public function testFindAllByOneToManyAppliesTheRelationWhere(): void
    {
        $articles = $this->createRelationalRepository();

        // the comment of the article 2 is not approved:
        $this->assertSame([1], $this->ids($articles->findAll(relatedWhere: ['comments' => ['text' => 'Nice']])));
        $this->assertSame([1, 4], $this->ids($articles->findAll(relatedWhere: ['comments' => []])));
    }

    public function testFindAllWithSeveralRelationsAllMustMatch(): void
    {
        $storage = $this->createStorage();

        $articleRepository = $this->createArticleRepository($storage, relations: [
            new ManyToMany(
                name: 'categories',
                table: 'resource_relations',
                ownerType: 'articles',
                relatedType: 'categories',
                repository: $this->createTagRepository($storage),
            ),
        ]);

        $articleRepository->relations()->getManyToMany('categories')->updateRelatedForOwner(ownerId: 1, relatedIds: [2]);
        $articleRepository->relations()->getManyToMany('categories')->updateRelatedForOwner(ownerId: 3, relatedIds: [2]);

        $articles = new RelationalRepository(repository: $articleRepository);

        $this->assertSame([1, 3], $this->ids($articles->findAll(relatedWhere: [
            'tags' => ['slug' => 'php'],
            'categories' => ['slug' => 'cms'],
            'author' => ['country_id' => 5],
        ])));

        $this->assertSame([1], $this->ids($articles->findAll(relatedWhere: [
            'tags' => ['slug' => 'php'],
            'categories' => ['slug' => 'cms'],
            'comments' => ['text' => 'Nice'],
        ])));
    }

    public function testFindAllWithWhereOrderByAndLimit(): void
    {
        $articles = $this->createRelationalRepository();

        $found = $articles->findAll(
            where: ['status' => 'published'],
            relatedWhere: ['tags' => ['slug' => 'php']],
            orderBy: ['position' => 'desc'],
            limit: 2,
        );

        $titles = [];

        foreach ($found as $article) {
            $titles[] = $article['title'];
        }

        // the sorting and the limit apply to the whole result:
        $this->assertSame(['Five', 'One'], $titles);
    }

    public function testFindAllReturnsNoEntitiesIfNoRelatedMatches(): void
    {
        $articles = $this->createRelationalRepository();

        // no tag found:
        $this->assertSame([], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'js']])));
        // a tag found, but no article has it:
        $this->assertSame([], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'unused']])));
        // one of several does not match:
        $this->assertSame([], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'php'], 'author' => ['name' => 'Unknown']])));
    }

    public function testFindAllReturnsNoEntitiesIfNoRelatedMatchesWithPivotInOtherStorage(): void
    {
        $articles = $this->createRelationalRepository(pivotStorage: new InMemoryStorage(items: []));

        $this->assertNull($articles->buildWhere(relatedWhere: ['tags' => ['slug' => 'unused']]));
        $this->assertSame([], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'unused']])));
    }

    public function testFindAllWithoutRelatedWhere(): void
    {
        $articles = $this->createRelationalRepository();

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($articles->findAll()));
        $this->assertSame([3], $this->ids($articles->findAll(where: ['status' => 'draft'])));
    }

    public function testFindAllIgnoresInvalidRelatedWhere(): void
    {
        $articles = $this->createRelationalRepository(relations: [
            new ManyToOneByType(
                name: 'owner',
                typeColumn: 'owner_type',
                foreignKey: 'owner_id',
                repositories: ['tags' => $this->createTagRepository($this->createStorage())],
            ),
        ]);

        $relatedWhere = [
            // unknown relation name:
            'unknown' => ['slug' => 'php'],
            // value not being an array:
            'author' => 'Mia',
            // no relation name:
            0 => ['slug' => 'php'],
            // relation not supported:
            'owner' => ['slug' => 'php'],
        ];

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($articles->findAll(relatedWhere: $relatedWhere)));
        $this->assertSame(['status' => 'draft'], $articles->buildWhere(where: ['status' => 'draft'], relatedWhere: $relatedWhere));

        // the valid ones still apply:
        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: [...$relatedWhere, 'tags' => ['slug' => 'cms']])));
    }

    public function testFindAllWithRelatedRepositoryNotBeingStorageRepository(): void
    {
        $storage = $this->createStorage();
        $tagRepository = $this->createTagRepository($storage);

        // a repository returning own tag objects with an id method:
        $ownTagRepository = new class($tagRepository) implements ReadRepositoryInterface {
            public function __construct(private ReadRepositoryInterface $repository) {}

            public function findById(int|string $id): null|object
            {
                return $this->repository->findById($id);
            }

            public function findByIds(int|string ...$ids): iterable
            {
                return $this->repository->findByIds(...$ids);
            }

            public function findOne(array $where = []): null|object
            {
                return $this->repository->findOne($where);
            }

            public function findAll(array $where = [], array $orderBy = [], null|int|array $limit = null): iterable
            {
                foreach ($this->repository->findAll(where: $where, orderBy: $orderBy, limit: $limit) as $tag) {
                    yield new class($tag['id']) {
                        public function __construct(private int $id) {}

                        public function id(): int
                        {
                            return $this->id;
                        }
                    };
                }
            }

            public function findColumn(
                string $column,
                null|string $key = null,
                array $where = [],
                array $orderBy = [],
                null|int|array $limit = null
            ): array {
                return $this->repository->findColumn($column, $key, $where, $orderBy, $limit);
            }

            public function count(array $where = []): int
            {
                return $this->repository->count($where);
            }
        };

        $articleRepository = $this->createArticleRepository($storage, relations: [
            new ManyToMany(
                name: 'labels',
                table: 'resource_relations',
                ownerType: 'articles',
                relatedType: 'tags',
                repository: $ownTagRepository,
            ),
        ]);

        $articles = new RelationalRepository(repository: $articleRepository);

        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: ['labels' => ['slug' => 'cms']])));
    }

    public function testFindOneMethod(): void
    {
        $articles = $this->createRelationalRepository();

        $this->assertSame(4, $articles->findOne(where: ['status' => 'published'], relatedWhere: ['tags' => ['slug' => 'cms'], 'author' => ['country_id' => 5]])['id']);
        $this->assertNull($articles->findOne(relatedWhere: ['tags' => ['slug' => 'js']]));
        $this->assertNull($articles->findOne(where: ['status' => 'draft'], relatedWhere: ['tags' => ['slug' => 'cms']]));
    }

    public function testCountMethod(): void
    {
        $articles = $this->createRelationalRepository();

        $this->assertSame(3, $articles->count(where: ['status' => 'published'], relatedWhere: ['tags' => ['slug' => 'php']]));
        $this->assertSame(2, $articles->count(relatedWhere: ['author' => ['country_id' => 5], 'tags' => ['slug' => 'php']]));
        $this->assertSame(0, $articles->count(relatedWhere: ['tags' => ['slug' => 'js']]));
        $this->assertSame(5, $articles->count());
    }

    public function testBuildWhereMethod(): void
    {
        $articles = $this->createRelationalRepository();

        // the where is kept and a group added per relation:
        $this->assertSame(
            ['status' => 'published', ['author_id' => ['in' => [1, 3]]], ['id' => ['in' => [1, 4]]]],
            $articles->buildWhere(
                where: ['status' => 'published'],
                relatedWhere: ['author' => ['country_id' => 5], 'comments' => []],
            )
        );

        $this->assertSame(['status' => 'published'], $articles->buildWhere(where: ['status' => 'published']));
        $this->assertNull($articles->buildWhere(where: ['status' => 'published'], relatedWhere: ['author' => ['name' => 'Unknown']]));
    }

    public function testBuildWhereCanBeUsedForOtherRepositoryMethods(): void
    {
        $articles = $this->createRelationalRepository();

        $where = $articles->buildWhere(relatedWhere: ['author' => ['country_id' => 5]]);

        $this->assertSame(['One', 'Three', 'Four'], $articles->repository()->findColumn(column: 'title', where: $where, orderBy: ['id' => 'asc']));
    }

    public function testRelatedIdsAreUnique(): void
    {
        $articles = $this->createRelationalRepository();

        // the authors 1 and 4 share the name, the approved comments 1 and 4 the article:
        $this->assertSame([['author_id' => ['in' => [1, 4]]]], $articles->buildWhere(relatedWhere: ['author' => ['name' => 'John']]));
        $this->assertSame([['id' => ['in' => [1]]]], $articles->buildWhere(relatedWhere: ['comments' => ['text' => 'Nice']]));
    }

    public function testThrowsRelationExceptionIfManyToManyHasNoRepository(): void
    {
        $articles = $this->createRelationalRepository(relations: [
            new ManyToMany(name: 'labels', table: 'resource_relations'),
        ]);

        foreach ([
            fn () => $articles->findAll(relatedWhere: ['labels' => ['slug' => 'php']]),
            fn () => $articles->findOne(relatedWhere: ['labels' => ['slug' => 'php']]),
            fn () => $articles->count(relatedWhere: ['labels' => ['slug' => 'php']]),
            fn () => $articles->buildWhere(relatedWhere: ['labels' => ['slug' => 'php']]),
        ] as $method) {
            try {
                $method();
                $this->fail('No exception thrown');
            } catch (RelationException $e) {
                $this->assertInstanceOf(LogicException::class, $e->getPrevious());
                $this->assertSame(
                    'Relation [labels] failed: The relation [labels] has no repository to query the related entities.',
                    $e->getMessage()
                );
            }
        }
    }

    public function testThrowsRelationExceptionIfRelatedRepositoryFails(): void
    {
        $articles = $this->createRelationalRepository(relations: [
            new ManyToOne(name: 'editor', foreignKey: 'author_id', repository: fn () => 'foo'),
        ]);

        try {
            $articles->findAll(relatedWhere: ['tags' => ['slug' => 'php'], 'editor' => ['name' => 'Mia']]);
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            // only the failing relation is named:
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            $this->assertSame(
                'Relation [editor] failed: The repository closure of the relation [editor] must return a '.ReadRepositoryInterface::class,
                $e->getMessage()
            );
        }
    }
}