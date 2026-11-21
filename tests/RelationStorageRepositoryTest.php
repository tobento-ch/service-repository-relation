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
use RuntimeException;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\DeletesWithOwnerInterface;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\Relations;
use Tobento\Service\Repository\Relation\RelationsAwareInterface;
use Tobento\Service\Repository\Relation\RelationsInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\RepositoryDeleteException;
use Tobento\Service\Repository\RepositoryInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\StorageInterface;

class RelationStorageRepositoryTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'authors' => [
                1 => ['id' => 1, 'name' => 'John'],
                2 => ['id' => 2, 'name' => 'Mia'],
            ],
            'tags' => [
                1 => ['id' => 1, 'slug' => 'php'],
                2 => ['id' => 2, 'slug' => 'cms'],
            ],
            'articles' => [
                1 => ['id' => 1, 'title' => 'Hello', 'status' => 'published', 'author_id' => 1],
                2 => ['id' => 2, 'title' => 'World', 'status' => 'draft', 'author_id' => 2],
                3 => ['id' => 3, 'title' => 'Again', 'status' => 'draft', 'author_id' => 1],
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
            new Column\Integer(name: 'author_id'),
        ];
    }

    protected function createAuthorRepository(StorageInterface $storage): StorageRepository
    {
        return new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name')],
        ) extends StorageRepository {};
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
     * Creates the article repository with the author and the tags (1: php, cms and 2: cms).
     *
     * @param StorageInterface $storage
     * @param null|StorageInterface $pivotStorage
     * @param array<int, RelationInterface> $relations Additional relations.
     * @return RelationStorageRepository
     */
    protected function createArticleRepository(
        StorageInterface $storage,
        null|StorageInterface $pivotStorage = null,
        array $relations = [],
    ): RelationStorageRepository {
        $articleRepository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            entityFactory: new RelationEntityFactory(),
            relations: [
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage)),
                new ManyToMany(
                    name: 'tags',
                    table: 'resource_relations',
                    ownerType: 'articles',
                    relatedType: 'tags',
                    repository: $this->createTagRepository($storage),
                    storage: $pivotStorage,
                ),
                ...$relations,
            ],
        ) extends RelationStorageRepository {};

        $tags = $articleRepository->relations()->getManyToMany('tags');
        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [2]);

        return $articleRepository;
    }

    /**
     * @param iterable<object> $entities
     * @param string $key
     * @return array<int, mixed>
     */
    protected function values(iterable $entities, string $key): array
    {
        $values = [];

        foreach ($entities as $entity) {
            $values[] = $entity[$key];
        }

        return $values;
    }

    protected function createFailingRelation(): RelationInterface
    {
        return new class() implements RelationInterface, DeletesWithOwnerInterface {
            public function name(): string
            {
                return 'notes';
            }

            public function deleteForOwners(array $ownerIds): void
            {
                throw new RuntimeException('Deleting notes failed');
            }
        };
    }

    /**
     * @param iterable<array-key, object> $entities
     * @return array<array-key, object>
     */
    protected function toArray(iterable $entities): array
    {
        $array = [];

        foreach ($entities as $key => $entity) {
            $array[$key] = $entity;
        }

        return $array;
    }

    public function testImplementsInterfaces(): void
    {
        $repository = $this->createArticleRepository($this->createStorage());

        $this->assertInstanceOf(StorageRepository::class, $repository);
        $this->assertInstanceOf(RepositoryInterface::class, $repository);
        $this->assertInstanceOf(RelationsAwareInterface::class, $repository);
    }

    public function testRelationsMethod(): void
    {
        $repository = $this->createArticleRepository($this->createStorage());

        $this->assertInstanceOf(RelationsInterface::class, $repository->relations());
        $this->assertSame(['author', 'tags'], array_keys($repository->relations()->all()));
    }

    public function testRelationsAsRelationsObject(): void
    {
        $storage = $this->createStorage();
        $relations = new Relations(
            new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage)),
        );

        $repository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            relations: $relations,
        ) extends RelationStorageRepository {};

        $this->assertSame($relations, $repository->relations());
    }

    public function testRelationsAsIterable(): void
    {
        $storage = $this->createStorage();
        $author = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage));

        $repository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            relations: (function () use ($author) {
                yield $author;
            })(),
        ) extends RelationStorageRepository {};

        $this->assertSame(['author' => $author], $repository->relations()->all());
    }

    public function testWithoutRelations(): void
    {
        $repository = new class(
            storage: $this->createStorage(),
            table: 'articles',
            columns: $this->articleColumns(),
        ) extends RelationStorageRepository {};

        $this->assertSame([], $repository->relations()->all());
        $this->assertSame(3, $repository->count());
    }

    public function testConfigureRelationsMethod(): void
    {
        $storage = $this->createStorage();

        $repository = new class($storage, $this->createAuthorRepository($storage)) extends RelationStorageRepository {
            public function __construct(StorageInterface $storage, private ReadRepositoryInterface $authorRepository)
            {
                parent::__construct(
                    storage: $storage,
                    table: 'articles',
                    columns: [new Column\Id(), new Column\Text(name: 'title'), new Column\Integer(name: 'author_id')],
                    entityFactory: new RelationEntityFactory(),
                );
            }

            protected function configureRelations(): iterable|RelationsInterface
            {
                return [new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->authorRepository)];
            }
        };

        $this->assertSame(['author'], array_keys($repository->relations()->all()));
        $this->assertSame('Mia', $repository->withResolvedRelations('author')->findById(2)['author']['name']);
    }

    public function testConfigureRelationsMethodReturningRelations(): void
    {
        $storage = $this->createStorage();

        $repository = new class($storage, $this->createAuthorRepository($storage)) extends RelationStorageRepository {
            public function __construct(StorageInterface $storage, private ReadRepositoryInterface $authorRepository)
            {
                parent::__construct(
                    storage: $storage,
                    table: 'articles',
                    columns: [new Column\Id(), new Column\Text(name: 'title'), new Column\Integer(name: 'author_id')],
                    entityFactory: new RelationEntityFactory(),
                );
            }

            protected function configureRelations(): iterable|RelationsInterface
            {
                return new Relations(
                    new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->authorRepository),
                    new ManyToMany(name: 'tags', table: 'resource_relations'),
                );
            }
        };

        $this->assertSame(['author', 'tags'], array_keys($repository->relations()->all()));

        // the storage is assigned to the relations without storage:
        $this->assertSame($storage, $repository->relations()->getManyToMany('tags')->storage());

        $repository->relations()->getManyToMany('tags')->updateRelatedForOwner(ownerId: 2, relatedIds: [1]);

        $article = $repository->withAllResolvedRelations()->findById(2);

        $this->assertSame('Mia', $article['author']['name']);
        // without repository the tags resolve to their ids:
        $this->assertSame([1], $article['tags']);
    }

    public function testRelationsGivenAreUsedInsteadOfConfiguredRelations(): void
    {
        $storage = $this->createStorage();

        $repository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            relations: [new ManyToMany(name: 'tags', table: 'resource_relations')],
        ) extends RelationStorageRepository {
            protected function configureRelations(): iterable|RelationsInterface
            {
                return [new ManyToMany(name: 'categories', table: 'resource_relations')];
            }
        };

        $this->assertSame(['tags'], array_keys($repository->relations()->all()));
    }

    public function testStorageIsAssignedToRelationsWithoutStorage(): void
    {
        $storage = $this->createStorage();
        $pivotStorage = new InMemoryStorage(items: []);

        $repository = $this->createArticleRepository($storage, relations: [
            new ManyToMany(name: 'categories', table: 'resource_relations', storage: $pivotStorage),
        ]);

        $this->assertSame($storage, $repository->relations()->getManyToMany('tags')->storage());
        $this->assertSame($pivotStorage, $repository->relations()->getManyToMany('categories')->storage());
    }

    public function testCircularRelationsUsingClosures(): void
    {
        $storage = $this->createStorage();
        $articleRepository = null;

        $authorRepository = new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name')],
            entityFactory: new RelationEntityFactory(),
            relations: [
                new OneToMany(
                    name: 'articles',
                    foreignKey: 'author_id',
                    repository: function () use (&$articleRepository): ReadRepositoryInterface {
                        return $articleRepository;
                    },
                ),
            ],
        ) extends RelationStorageRepository {};

        $articleRepository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            entityFactory: new RelationEntityFactory(),
            relations: [
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => $authorRepository),
            ],
        ) extends RelationStorageRepository {};

        $this->assertSame(['Hello', 'Again'], $this->values($authorRepository->withResolvedRelations('articles')->findById(1)['articles'], 'title'));
        $this->assertSame('Mia', $articleRepository->withResolvedRelations('author')->findById(2)['author']['name']);
    }

    public function testWithResolvedRelationsMethod(): void
    {
        $repository = $this->createArticleRepository($this->createStorage());
        $resolving = $repository->withResolvedRelations('author', 'tags');

        // a new instance, the repository itself does not resolve:
        $this->assertNotSame($repository, $resolving);
        $this->assertSame([], $repository->resolvedRelations());
        $this->assertSame(['author', 'tags'], $resolving->resolvedRelations());

        $article = $resolving->findById(1);

        $this->assertSame('John', $article['author']['name']);
        $this->assertSame(['php', 'cms'], $this->values($article['tags'], 'slug'));
        $this->assertFalse(isset($repository->findById(1)['author']));

        // all find methods resolve:
        $this->assertSame(['John', 'Mia', 'John'], array_map(fn (object $article) => $article['author']['name'], array_values($this->toArray($resolving->findAll()))));
        $this->assertSame('Mia', $resolving->findOne(where: ['title' => 'World'])['author']['name']);
        $this->assertSame(['cms'], $this->values($this->toArray($resolving->findByIds(2))[2]['tags'], 'slug'));
    }

    public function testWithResolvedRelationsMethodWithoutNamesResolvesNone(): void
    {
        $repository = $this->createArticleRepository($this->createStorage())->withResolvedRelations('author');

        $this->assertSame([], $repository->withResolvedRelations()->resolvedRelations());
    }

    public function testWithAllResolvedRelationsMethod(): void
    {
        $repository = $this->createArticleRepository($this->createStorage());
        $article = $repository->withAllResolvedRelations()->findById(2);

        $this->assertSame(['author', 'tags'], $repository->withAllResolvedRelations()->resolvedRelations());
        $this->assertSame('Mia', $article['author']['name']);
        $this->assertSame(['cms'], $this->values($article['tags'], 'slug'));
    }

    public function testWithoutResolvedRelationsMethod(): void
    {
        $repository = $this->createArticleRepository($this->createStorage())->withAllResolvedRelations();
        $withoutTags = $repository->withoutResolvedRelations('tags');

        $this->assertNotSame($repository, $withoutTags);
        $this->assertSame(['author', 'tags'], $repository->resolvedRelations());
        $this->assertSame(['author'], $withoutTags->resolvedRelations());
        $this->assertSame(['author', 'tags'], $repository->withoutResolvedRelations()->resolvedRelations());

        $article = $withoutTags->findById(1);

        $this->assertSame('John', $article['author']['name']);
        $this->assertFalse(isset($article['tags']));
    }

    public function testResolvedRelationsByEntityFactoryDefault(): void
    {
        $storage = $this->createStorage();

        $repository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            entityFactory: new RelationEntityFactory(resolveRelations: ['author']),
            relations: [
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage)),
            ],
        ) extends RelationStorageRepository {};

        $this->assertSame(['author'], $repository->resolvedRelations());
        $this->assertSame('Mia', $repository->findById(2)['author']['name']);
        $this->assertFalse(isset($repository->withoutResolvedRelations('author')->findById(2)['author']));
    }

    public function testClonedEntityFactoryKeepsTheRelations(): void
    {
        $repository = $this->createArticleRepository($this->createStorage());
        $resolving = $repository->withResolvedRelations('author');

        // the entity factory is cloned with the repository:
        $this->assertNotSame($repository->entityFactory(), $resolving->entityFactory());
        $this->assertSame($repository->relations(), $resolving->entityFactory()->relations());
        $this->assertSame([], $repository->entityFactory()->resolvedRelations());
    }

    public function testWithResolvedRelationsMethodThrowsRelationNotFoundException(): void
    {
        $this->expectException(RelationNotFoundException::class);
        $this->expectExceptionMessage('Relation [unknown] not found');

        $this->createArticleRepository($this->createStorage())->withResolvedRelations('author', 'unknown');
    }

    public function testWithoutResolvedRelationsMethodThrowsRelationNotFoundException(): void
    {
        $this->expectException(RelationNotFoundException::class);
        $this->expectExceptionMessage('Relation [unknown] not found');

        $this->createArticleRepository($this->createStorage())->withoutResolvedRelations('unknown');
    }

    public function testWithResolvedRelationsMethodThrowsLogicExceptionIfEntityFactoryIsNotRelationsAware(): void
    {
        $storage = $this->createStorage();

        // the default entity factory of the storage repository:
        $repository = new class(
            storage: $storage,
            table: 'articles',
            columns: $this->articleColumns(),
            relations: [
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage)),
            ],
        ) extends RelationStorageRepository {};

        $this->assertSame([], $repository->resolvedRelations());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not support resolving relations, use the '.RelationEntityFactory::class);

        $repository->withResolvedRelations('author');
    }

    public function testDeleteByIdMethodDeletesThePivotRows(): void
    {
        $storage = $this->createStorage();
        $repository = $this->createArticleRepository($storage);
        $tags = $repository->relations()->getManyToMany('tags');

        $deleted = $repository->deleteById(1);

        $this->assertSame('Hello', $deleted['title']);
        $this->assertNull($repository->findById(1));
        $this->assertSame([], $tags->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([2], $tags->findRelatedIdsForOwner(ownerId: 2));

        // the related entities are kept:
        $this->assertSame(2, $this->createTagRepository($storage)->count());
        $this->assertSame('John', $this->createAuthorRepository($storage)->findById(1)['name']);
    }

    public function testDeleteMethodDeletesThePivotRows(): void
    {
        $storage = $this->createStorage();
        $repository = $this->createArticleRepository($storage);
        $tags = $repository->relations()->getManyToMany('tags');
        $tags->updateRelatedForOwner(ownerId: 3, relatedIds: [1]);

        $deleted = $repository->delete(where: ['status' => 'draft']);

        $this->assertSame(['World', 'Again'], $this->values($deleted, 'title'));
        $this->assertSame([1], $this->values($repository->findAll(), 'id'));
        $this->assertSame([], $tags->findRelatedIdsForOwner(ownerId: 2));
        $this->assertSame([], $tags->findRelatedIdsForOwner(ownerId: 3));
        $this->assertSame([1, 2], $tags->findRelatedIdsForOwner(ownerId: 1));
    }

    public function testDeleteMethodWithoutMatches(): void
    {
        $repository = $this->createArticleRepository($this->createStorage());
        $tags = $repository->relations()->getManyToMany('tags');

        $this->assertSame([], $this->values($repository->delete(where: ['status' => 'archived']), 'id'));
        $this->assertSame([1, 2], $tags->findRelatedIdsForOwner(ownerId: 1));
    }

    public function testDeleteWithPivotInOtherStorage(): void
    {
        $repository = $this->createArticleRepository($this->createStorage(), pivotStorage: new InMemoryStorage(items: []));
        $tags = $repository->relations()->getManyToMany('tags');

        $repository->deleteById(1);
        $repository->delete(where: ['id' => 2]);

        $this->assertSame([], $tags->findRelatedIdsForOwner(ownerId: 1));
        $this->assertSame([], $tags->findRelatedIdsForOwner(ownerId: 2));
    }

    public function testDeleteMethodsCallOwnerDeletingRelations(): void
    {
        $deletedOwnerIds = [];

        $relation = new class($deletedOwnerIds) implements RelationInterface, DeletesWithOwnerInterface {
            public function __construct(private array &$deletedOwnerIds) {}

            public function name(): string
            {
                return 'notes';
            }

            public function deleteForOwners(array $ownerIds): void
            {
                $this->deletedOwnerIds[] = $ownerIds;
            }
        };

        $repository = $this->createArticleRepository($this->createStorage(), relations: [$relation]);

        $repository->deleteById(1);
        $repository->delete(where: ['status' => 'draft']);
        $repository->delete(where: ['status' => 'archived']);

        // no call without deleted entities:
        $this->assertSame([[1], [2, 3]], $deletedOwnerIds);
    }

    public function testDeleteByIdMethodThrowsRepositoryDeleteExceptionIfDeletingRelationDataFails(): void
    {
        $repository = $this->createArticleRepository($this->createStorage(), relations: [$this->createFailingRelation()]);

        try {
            $repository->deleteById(1);
            $this->fail('No exception thrown');
        } catch (RepositoryDeleteException $e) {
            $this->assertSame(1, $e->id());
            $this->assertSame('Deleting notes failed', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        // the entity is deleted already:
        $this->assertNull($repository->findById(1));
    }

    public function testDeleteMethodThrowsRepositoryDeleteExceptionIfDeletingRelationDataFails(): void
    {
        $repository = $this->createArticleRepository($this->createStorage(), relations: [$this->createFailingRelation()]);

        try {
            $repository->delete(where: ['status' => 'draft']);
            $this->fail('No exception thrown');
        } catch (RepositoryDeleteException $e) {
            $this->assertSame('Deleting notes failed', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }
}