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
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Relation\ResolvableRelationInterface;
use Tobento\Service\Repository\Relation\StorageAwareRelationInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\EntityFactory;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\Item;
use Tobento\Service\Storage\StorageInterface;

class ManyToOneTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'authors' => [
                1 => ['id' => 1, 'name' => 'John'],
                2 => ['id' => 2, 'name' => 'Mia'],
            ],
            'articles' => [
                1 => ['id' => 1, 'title' => 'Hello', 'author_id' => 1],
                2 => ['id' => 2, 'title' => 'World', 'author_id' => 2],
                3 => ['id' => 3, 'title' => 'Deleted author', 'author_id' => 99],
                4 => ['id' => 4, 'title' => 'No author', 'author_id' => null],
            ],
        ]);
    }

    protected function createAuthorRepository(StorageInterface $storage): StorageRepository
    {
        return new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name')],
        ) extends StorageRepository {};
    }

    protected function createArticleRepository(
        StorageInterface $storage,
        ReadRepositoryInterface $authorRepository,
    ): RelationStorageRepository {
        return new class(
            storage: $storage,
            table: 'articles',
            columns: [
                new Column\Id(),
                new Column\Text(name: 'title'),
                new Column\Integer(name: 'author_id')->type(nullable: true),
            ],
            entityFactory: new RelationEntityFactory(),
            relations: [
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository),
            ],
        ) extends RelationStorageRepository {};
    }

    /**
     * @param iterable<object> $articles
     * @return array<int, string> The titles with the author name, e.g. 'Hello: John'.
     */
    protected function titlesWithAuthor(iterable $articles): array
    {
        $lines = [];

        foreach ($articles as $article) {
            $lines[] = $article['title'].': '.($article['author']['name'] ?? 'none');
        }

        return $lines;
    }

    public function testImplementsInterfaces(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => null);

        $this->assertInstanceOf(RelationInterface::class, $relation);
        $this->assertInstanceOf(ResolvableRelationInterface::class, $relation);
        $this->assertFalse($relation instanceof StorageAwareRelationInterface);
    }

    public function testGetterMethods(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => null);

        $this->assertSame('author', $relation->name());
        $this->assertSame('author_id', $relation->foreignKey());
        $this->assertSame('id', $relation->relatedKey());

        $relation = new ManyToOne(name: 'author', foreignKey: 'author_uuid', repository: fn () => null, relatedKey: 'uuid');

        $this->assertSame('uuid', $relation->relatedKey());
    }

    public function testRepositoryMethod(): void
    {
        $authorRepository = $this->createAuthorRepository($this->createStorage());
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository);

        $this->assertSame($authorRepository, $relation->repository());
    }

    public function testRepositoryMethodResolvesClosureOnce(): void
    {
        $authorRepository = $this->createAuthorRepository($this->createStorage());
        $calls = 0;

        $relation = new ManyToOne(
            name: 'author',
            foreignKey: 'author_id',
            repository: function () use ($authorRepository, &$calls): ReadRepositoryInterface {
                $calls++;
                return $authorRepository;
            },
        );

        $this->assertSame($authorRepository, $relation->repository());
        $this->assertSame($authorRepository, $relation->repository());
        $this->assertSame(1, $calls);
    }

    public function testRepositoryMethodThrowsInvalidArgumentExceptionIfClosureReturnsNoRepository(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => 'foo');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The repository closure of the relation [author] must return a');

        $relation->repository();
    }

    public function testReadResolving(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createArticleRepository($storage, $this->createAuthorRepository($storage));

        $this->assertSame(
            ['Hello: John', 'World: Mia', 'Deleted author: none', 'No author: none'],
            $this->titlesWithAuthor($articleRepository->withResolvedRelations('author')->findAll())
        );
    }

    public function testReadResolvingIsNotDoneByDefault(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createArticleRepository($storage, $this->createAuthorRepository($storage));

        $this->assertFalse(isset($articleRepository->findById(1)['author']));
    }

    public function testReadFindRelatedForItemMethod(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($this->createStorage()));

        $this->assertSame('Mia', $relation->findRelatedForItem(['author_id' => 2])['name']);
        $this->assertSame('John', $relation->findRelatedForItem(new Item(['author_id' => 1]))['name']);
        $this->assertNull($relation->findRelatedForItem(['author_id' => 99]));
        $this->assertNull($relation->findRelatedForItem(['author_id' => null]));
        $this->assertNull($relation->findRelatedForItem(['author_id' => 1.5]));
        $this->assertNull($relation->findRelatedForItem(['title' => 'No foreign key']));
    }

    public function testReadFindRelatedForItemsMethod(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($this->createStorage()));

        $authors = $relation->findRelatedForItems([
            ['author_id' => 2],
            ['author_id' => 1],
            ['author_id' => 2],
            ['author_id' => 99],
            ['author_id' => null],
        ]);

        // keyed by the author id, missing authors are skipped:
        $this->assertSame([1, 2], $this->sortedKeys($authors));
        $this->assertSame('John', $authors[1]['name']);
        $this->assertSame('Mia', $authors[2]['name']);
        $this->assertSame([], $relation->findRelatedForItems([]));
    }

    public function testReadFindByIdsMethod(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($this->createStorage()));

        $this->assertSame([1, 2], $this->sortedKeys($relation->findByIds([1, 2, 99])));
        $this->assertSame([], $relation->findByIds([]));
    }

    public function testReadInChunksReturnsTheSameResults(): void
    {
        $relation = new ManyToOne(
            name: 'author',
            foreignKey: 'author_id',
            repository: $this->createAuthorRepository($this->createStorage()),
            chunkSize: 1,
        );

        $this->assertSame([1, 2], $this->sortedKeys($relation->findByIds([1, 2, 99])));
    }

    public function testReadResolveForItemsMethod(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($this->createStorage()));

        $items = $relation->resolveForItems([
            'a' => ['title' => 'Hello', 'author_id' => 1],
            'b' => ['title' => 'Deleted author', 'author_id' => 99, 'author' => 'replaced'],
            'c' => ['title' => 'No author'],
        ]);

        // the keys are kept:
        $this->assertSame(['a', 'b', 'c'], array_keys($items));
        $this->assertSame('John', $items['a']['author']['name']);
        $this->assertNull($items['b']['author']);
        $this->assertNull($items['c']['author']);
        $this->assertSame('Hello', $items['a']['title']);
    }

    public function testReadOwnObjectsAsItemsUsingForeignKeyResolver(): void
    {
        $relation = new ManyToOne(
            name: 'author',
            foreignKey: 'author_id',
            repository: $this->createAuthorRepository($this->createStorage()),
            foreignKeyResolver: fn (object $article): mixed => $article->authorId(),
        );

        $article = new class(2) {
            public function __construct(private null|int $authorId) {}

            public function authorId(): null|int
            {
                return $this->authorId;
            }
        };

        $this->assertSame('Mia', $relation->findRelatedForItem($article)['name']);
        $this->assertSame([2], array_keys($relation->findRelatedForItems([$article])));
    }

    public function testReadOwnObjectsAsRelatedEntities(): void
    {
        $storage = $this->createStorage();

        // a repository creating own author objects without ArrayAccess:
        $authorRepository = new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name')],
            entityFactory: new class() extends EntityFactory {
                public function createEntityFromArray(array $attributes): object
                {
                    return new class($attributes['id'], $attributes['name']) {
                        public function __construct(private int $id, private string $name) {}

                        public function id(): int
                        {
                            return $this->id;
                        }

                        public function name(): string
                        {
                            return $this->name;
                        }
                    };
                }
            },
        ) extends StorageRepository {};

        // the key by the method named like the related key:
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository);

        $this->assertSame('Mia', $relation->findByIds([2])[2]->name());

        // the key by the key resolver:
        $relation = new ManyToOne(
            name: 'author',
            foreignKey: 'author_id',
            repository: $authorRepository,
            relatedKey: 'unknown',
            keyResolver: fn (object $author): mixed => $author->id(),
        );

        $this->assertSame('John', $relation->findRelatedForItems([['author_id' => 1]])[1]->name());
    }

    public function testWrite(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createArticleRepository($storage, $this->createAuthorRepository($storage));

        $articleRepository->create(['title' => 'New', 'author_id' => 2]);
        $articleRepository->updateById(4, ['author_id' => 1]);

        $articles = $articleRepository->withResolvedRelations('author');

        $this->assertSame('Mia', $articles->findOne(where: ['title' => 'New'])['author']['name']);
        $this->assertSame('John', $articles->findById(4)['author']['name']);

        $articleRepository->updateById(4, ['author_id' => null]);

        $this->assertNull($articles->findById(4)['author']);
    }

    public function testDeleteDoesNotChangeTheOtherSide(): void
    {
        $storage = $this->createStorage();
        $authorRepository = $this->createAuthorRepository($storage);
        $articleRepository = $this->createArticleRepository($storage, $authorRepository);

        // deleting an article keeps its author:
        $articleRepository->deleteById(2);

        $this->assertSame('Mia', $authorRepository->findById(2)['name']);

        // deleting an author keeps its articles with its id, resolving null:
        $authorRepository->deleteById(1);

        $article = $articleRepository->withResolvedRelations('author')->findById(1);

        $this->assertSame(1, $article['author_id']);
        $this->assertNull($article['author']);
    }

    public function testThrowsRelationExceptionIfClosureReturnsNoRepository(): void
    {
        $relation = new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => 'foo');

        try {
            $relation->findByIds([1]);
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            $this->assertSame(
                'Relation [author] failed: The repository closure of the relation [author] must return a '.ReadRepositoryInterface::class,
                $e->getMessage()
            );
        }
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<int, array-key>
     */
    protected function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);
        return $keys;
    }
}