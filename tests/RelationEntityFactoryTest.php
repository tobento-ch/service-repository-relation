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
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;
use Tobento\Service\Repository\Relation\HasEntityFactoryRelations;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\Relations;
use Tobento\Service\Repository\Relation\RelationsAwareEntityFactoryInterface;
use Tobento\Service\Repository\Relation\RelationsAwareInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\EntityFactory;
use Tobento\Service\Repository\Storage\StorageEntityFactoryInterface;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\Item;
use Tobento\Service\Storage\Items;
use Tobento\Service\Storage\ItemInterface;
use Tobento\Service\Storage\ItemsInterface;
use Tobento\Service\Storage\StorageInterface;

class RelationEntityFactoryTest extends TestCase
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
                3 => ['id' => 3, 'title' => 'Again', 'author_id' => 1],
            ],
        ]);
    }

    /**
     * Creates the author repository, recording its queries.
     *
     * @param StorageInterface $storage
     * @param array<int, string> $queries
     * @return StorageRepository
     */
    protected function createAuthorRepository(StorageInterface $storage, array &$queries = []): StorageRepository
    {
        return new class($storage, $queries) extends StorageRepository {
            public function __construct(StorageInterface $storage, private array &$queries)
            {
                parent::__construct(
                    storage: $storage,
                    table: 'authors',
                    columns: [new Column\Id(), new Column\Text(name: 'name')],
                );
            }

            public function findByIds(int|string ...$ids): iterable
            {
                $this->queries[] = 'authors: '.implode(',', $ids);
                return parent::findByIds(...$ids);
            }
        };
    }

    protected function createRelations(StorageInterface $storage, array &$queries = []): Relations
    {
        return new Relations(
            new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage, $queries)),
            new ManyToOne(name: 'editor', foreignKey: 'editor_id', repository: $this->createAuthorRepository($storage, $queries)),
        );
    }

    protected function createArticleRepository(
        StorageInterface $storage,
        StorageEntityFactoryInterface $entityFactory,
    ): RelationStorageRepository {
        return new class(
            storage: $storage,
            table: 'articles',
            columns: [new Column\Id(), new Column\Text(name: 'title'), new Column\Integer(name: 'author_id')],
            entityFactory: $entityFactory,
            relations: [
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($storage)),
            ],
        ) extends RelationStorageRepository {};
    }

    /**
     * @return ItemsInterface
     */
    protected function createArticleItems(): ItemsInterface
    {
        return new Items([
            1 => ['id' => 1, 'title' => 'Hello', 'author_id' => 1],
            2 => ['id' => 2, 'title' => 'World', 'author_id' => 2],
            3 => ['id' => 3, 'title' => 'Again', 'author_id' => 1],
        ]);
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
        $factory = new RelationEntityFactory();

        $this->assertInstanceOf(EntityFactory::class, $factory);
        $this->assertInstanceOf(StorageEntityFactoryInterface::class, $factory);
        $this->assertInstanceOf(RelationsAwareEntityFactoryInterface::class, $factory);
        $this->assertInstanceOf(RelationsAwareInterface::class, $factory);
    }

    public function testRelationsMethod(): void
    {
        $factory = new RelationEntityFactory();

        // empty until the relations are set:
        $this->assertSame([], $factory->relations()->all());

        $relations = $this->createRelations($this->createStorage());
        $factory->setRelations($relations);

        $this->assertSame($relations, $factory->relations());
    }

    public function testRepositoryPassesItsRelations(): void
    {
        $factory = new RelationEntityFactory();
        $repository = $this->createArticleRepository($this->createStorage(), $factory);

        $this->assertSame($repository->relations(), $factory->relations());
        $this->assertTrue($factory->relations()->has('author'));
    }

    public function testConstructorResolveRelations(): void
    {
        $this->assertSame([], new RelationEntityFactory()->resolvedRelations());
        $this->assertSame(['author', 'editor'], new RelationEntityFactory(resolveRelations: ['author', 'editor', 'author'])->resolvedRelations());
        $this->assertSame([0, 1], array_keys(new RelationEntityFactory(resolveRelations: [3 => 'author', 7 => 'editor'])->resolvedRelations()));
    }

    public function testConstructorThrowsInvalidArgumentExceptionIfNameIsNoString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The names of the relations to resolve must be strings');

        new RelationEntityFactory(resolveRelations: ['author', 5]);
    }

    public function testWithResolvedRelationsMethod(): void
    {
        $factory = new RelationEntityFactory(resolveRelations: ['editor']);
        $newFactory = $factory->withResolvedRelations('author', 'author');

        // a new instance with exactly the given names:
        $this->assertNotSame($factory, $newFactory);
        $this->assertSame(['editor'], $factory->resolvedRelations());
        $this->assertSame(['author'], $newFactory->resolvedRelations());
        $this->assertSame([], $factory->withResolvedRelations()->resolvedRelations());
    }

    public function testWithAllResolvedRelationsMethod(): void
    {
        $factory = new RelationEntityFactory();

        $this->assertSame([], $factory->withAllResolvedRelations()->resolvedRelations());

        $factory->setRelations($this->createRelations($this->createStorage()));
        $newFactory = $factory->withAllResolvedRelations();

        $this->assertNotSame($factory, $newFactory);
        $this->assertSame([], $factory->resolvedRelations());
        $this->assertSame(['author', 'editor'], $newFactory->resolvedRelations());
    }

    public function testWithoutResolvedRelationsMethod(): void
    {
        $factory = new RelationEntityFactory(resolveRelations: ['author', 'editor']);
        $newFactory = $factory->withoutResolvedRelations('author', 'unknown');

        $this->assertNotSame($factory, $newFactory);
        $this->assertSame(['author', 'editor'], $factory->resolvedRelations());
        $this->assertSame(['editor'], $newFactory->resolvedRelations());
        $this->assertSame(['author', 'editor'], $factory->withoutResolvedRelations()->resolvedRelations());
    }

    public function testCreatedInstancesKeepTheRelations(): void
    {
        $factory = new RelationEntityFactory();
        $factory->setRelations($this->createRelations($this->createStorage()));

        $this->assertSame($factory->relations(), $factory->withResolvedRelations('author')->relations());
        $this->assertSame($factory->relations(), $factory->withoutResolvedRelations('author')->relations());
    }

    public function testCreateEntityFromStorageItemMethod(): void
    {
        $factory = new RelationEntityFactory(resolveRelations: ['author']);
        $factory->setRelations($this->createRelations($this->createStorage()));

        $article = $factory->createEntityFromStorageItem(new Item(['id' => 2, 'title' => 'World', 'author_id' => 2]));

        $this->assertSame('Mia', $article['author']['name']);
        $this->assertSame('World', $article['title']);
    }

    public function testCreateEntityFromStorageItemMethodWithoutResolvedRelations(): void
    {
        $factory = new RelationEntityFactory();
        $factory->setRelations($this->createRelations($this->createStorage()));

        $article = $factory->createEntityFromStorageItem(new Item(['id' => 2, 'title' => 'World', 'author_id' => 2]));

        $this->assertSame(['id' => 2, 'title' => 'World', 'author_id' => 2], $article->all());
    }

    public function testCreateEntitiesFromStorageItemsMethod(): void
    {
        $queries = [];
        $factory = new RelationEntityFactory(resolveRelations: ['author']);
        $factory->setRelations($this->createRelations($this->createStorage(), $queries));

        $articles = $factory->createEntitiesFromStorageItems($this->createArticleItems());

        $this->assertSame(['Hello: John', 'World: Mia', 'Again: John'], $this->titlesWithAuthor($articles));

        // in batch, one query for all authors:
        $this->assertSame(['authors: 1,2'], $queries);
    }

    public function testCreateEntitiesFromStorageItemsMethodWithoutResolvedRelations(): void
    {
        $queries = [];
        $factory = new RelationEntityFactory();
        $factory->setRelations($this->createRelations($this->createStorage(), $queries));

        $articles = $factory->createEntitiesFromStorageItems($this->createArticleItems());

        $this->assertSame(['Hello: none', 'World: none', 'Again: none'], $this->titlesWithAuthor($articles));
        $this->assertSame([], $queries);
    }

    public function testColumnsAreProcessedAfterResolving(): void
    {
        $factory = new RelationEntityFactory(
            columns: [new Column\Text(name: 'title')->read(fn (string $value): string => strtoupper($value))],
            resolveRelations: ['author'],
        );

        $factory->setRelations($this->createRelations($this->createStorage()));

        $this->assertSame(
            ['HELLO: John', 'WORLD: Mia', 'AGAIN: John'],
            $this->titlesWithAuthor($factory->createEntitiesFromStorageItems($this->createArticleItems()))
        );
    }

    public function testOwnEntitiesBySubclass(): void
    {
        // e.g. an own article entity holding its author:
        $factory = new class(resolveRelations: ['author']) extends RelationEntityFactory {
            public function createEntityFromArray(array $attributes): object
            {
                return new class($attributes['title'], $attributes['author']['name'] ?? null) {
                    public function __construct(public readonly string $title, public readonly null|string $authorName) {}
                };
            }
        };

        $repository = $this->createArticleRepository($this->createStorage(), $factory);

        $this->assertSame('Mia', $repository->findById(2)->authorName);
        $this->assertSame(['John', 'Mia', 'John'], array_map(
            fn (object $article): null|string => $article->authorName,
            array_values(iterator_to_array((function () use ($repository) { yield from $repository->findAll(); })()))
        ));
    }

    public function testOwnFactoryUsingTheTrait(): void
    {
        // a factory not able to extend the RelationEntityFactory:
        $factory = new class() extends EntityFactory implements RelationsAwareEntityFactoryInterface, RelationsAwareInterface {
            use HasEntityFactoryRelations;

            public function createEntityFromStorageItem(ItemInterface $item): object
            {
                return $this->createEntityFromArray($this->resolveRelationsForItem($item));
            }

            public function createEntitiesFromStorageItems(ItemsInterface $items): iterable
            {
                return parent::createEntitiesFromStorageItems($this->resolveRelationsForItems($items));
            }
        };

        $repository = $this->createArticleRepository($this->createStorage(), $factory);

        $this->assertSame('Mia', $repository->withResolvedRelations('author')->findById(2)['author']['name']);
        $this->assertSame(['Hello: John', 'World: Mia', 'Again: John'], $this->titlesWithAuthor($repository->withResolvedRelations('author')->findAll()));
        $this->assertSame(['Hello: none', 'World: none', 'Again: none'], $this->titlesWithAuthor($repository->findAll()));
    }

    public function testThrowsRelationExceptionIfRelationToResolveIsNotFound(): void
    {
        $factory = new RelationEntityFactory(resolveRelations: ['author', 'unknown']);
        $factory->setRelations($this->createRelations($this->createStorage()));

        foreach ([
            fn () => $factory->createEntityFromStorageItem(new Item(['id' => 1, 'author_id' => 1])),
            fn () => $factory->createEntitiesFromStorageItems($this->createArticleItems()),
        ] as $method) {
            try {
                $method();
                $this->fail('No exception thrown');
            } catch (RelationException $e) {
                $this->assertInstanceOf(RelationNotFoundException::class, $e->getPrevious());
                $this->assertSame('Relation [author, unknown] failed: Relation [unknown] to resolve not found', $e->getMessage());
            }
        }
    }

    public function testThrowsRelationExceptionIfRelationFails(): void
    {
        $factory = new RelationEntityFactory(resolveRelations: ['author', 'editor']);
        $factory->setRelations(new Relations(
            new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->createAuthorRepository($this->createStorage())),
            new OneToMany(name: 'editor', foreignKey: 'article_id', repository: fn () => 'foo'),
        ));

        try {
            $factory->createEntitiesFromStorageItems($this->createArticleItems());
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            // the exception of the failing relation is kept:
            $this->assertStringStartsWith('Relation [editor] failed: ', $e->getMessage());
        }
    }
}