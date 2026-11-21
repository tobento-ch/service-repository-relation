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
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationEntityResolver;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\Relations;
use Tobento\Service\Repository\Relation\ResolvableRelationInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;
use Tobento\Service\Storage\Item;
use Tobento\Service\Storage\Items;
use Tobento\Service\Storage\StorageInterface;

class RelationEntityResolverTest extends TestCase
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
            'comments' => [
                1 => ['id' => 1, 'text' => 'Nice', 'article_id' => 1],
                2 => ['id' => 2, 'text' => 'Great', 'article_id' => 1],
            ],
        ]);
    }

    /**
     * Creates a repository recording its queries.
     *
     * @param StorageInterface $storage
     * @param string $table
     * @param array<int, string> $columns
     * @param array<int, string> $queries
     * @return StorageRepository
     */
    protected function createRepository(StorageInterface $storage, string $table, array $columns, array &$queries): StorageRepository
    {
        return new class($storage, $table, $columns, $queries) extends StorageRepository {
            public function __construct(StorageInterface $storage, string $table, array $columns, private array &$queries)
            {
                parent::__construct(
                    storage: $storage,
                    table: $table,
                    columns: [new Column\Id(), ...array_map(fn (string $name) => new Column\Text(name: $name), $columns)],
                );
            }

            public function findByIds(int|string ...$ids): iterable
            {
                $this->queries[] = $this->table;
                return parent::findByIds(...$ids);
            }

            public function findAll(array $where = [], array $orderBy = [], null|int|array $limit = null): iterable
            {
                $this->queries[] = $this->table;
                return parent::findAll(where: $where, orderBy: $orderBy, limit: $limit);
            }
        };
    }

    /**
     * Creates the relations of the articles: the author, the tags and the comments.
     *
     * @param array<int, string> $queries
     * @return Relations
     */
    protected function createRelations(array &$queries = []): Relations
    {
        $storage = $this->createStorage();

        $tags = new ManyToMany(
            name: 'tags',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
            repository: $this->createRepository($storage, 'tags', ['slug'], $queries),
            storage: $storage,
        );

        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [2]);

        return new Relations(
            new ManyToOne(
                name: 'author',
                foreignKey: 'author_id',
                repository: $this->createRepository($storage, 'authors', ['name'], $queries),
            ),
            $tags,
            new OneToMany(
                name: 'comments',
                foreignKey: 'article_id',
                repository: $this->createRepository($storage, 'comments', ['text', 'article_id'], $queries),
            ),
        );
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

    public function testRelationsMethod(): void
    {
        $relations = $this->createRelations();

        $this->assertSame($relations, new RelationEntityResolver($relations)->relations());
    }

    public function testResolveForItemsMethod(): void
    {
        $queries = [];
        $resolver = new RelationEntityResolver($this->createRelations($queries));

        $items = $resolver->resolveForItems(new Items(
            items: [
                'a' => ['id' => 1, 'title' => 'Hello', 'author_id' => 1],
                'b' => ['id' => 2, 'title' => 'World', 'author_id' => 2],
                'c' => ['id' => 3, 'title' => 'Again', 'author_id' => null],
            ],
            itemsCount: 10,
            action: 'get',
        ));

        $all = $items->all();

        // the keys, the count and the action are kept:
        $this->assertSame(['a', 'b', 'c'], array_keys($all));
        $this->assertSame(10, $items->count());
        $this->assertSame('get', $items->action());

        $this->assertSame('John', $all['a']['author']['name']);
        $this->assertSame('Mia', $all['b']['author']['name']);
        $this->assertNull($all['c']['author']);

        $this->assertSame(['php', 'cms'], $this->values($all['a']['tags'], 'slug'));
        $this->assertSame(['cms'], $this->values($all['b']['tags'], 'slug'));
        $this->assertSame([], $all['c']['tags']);

        $this->assertSame(['Nice', 'Great'], $this->values($all['a']['comments'], 'text'));
        $this->assertSame([], $all['b']['comments']);

        // each relation loads its related entities for all items at once:
        $this->assertSame(['authors', 'tags', 'comments'], $queries);
    }

    public function testResolveForItemsMethodWithSelectedRelations(): void
    {
        $queries = [];
        $resolver = new RelationEntityResolver($this->createRelations($queries)->only('author'));

        $all = $resolver->resolveForItems(new Items([['id' => 1, 'author_id' => 1]]))->all();

        $this->assertSame(['id', 'author_id', 'author'], array_keys($all[0]));
        $this->assertSame(['authors'], $queries);
    }

    public function testResolveForItemsMethodWithoutRelations(): void
    {
        $items = new Items([['id' => 1, 'author_id' => 1]]);

        $this->assertSame($items, new RelationEntityResolver(new Relations())->resolveForItems($items));
    }

    public function testResolveForItemsMethodWithoutItems(): void
    {
        $queries = [];
        $resolver = new RelationEntityResolver($this->createRelations($queries));

        $this->assertSame([], $resolver->resolveForItems(new Items([]))->all());
        $this->assertSame([], $queries);
    }

    public function testRelationsNotResolvableAreSkipped(): void
    {
        $notResolvable = new class() implements RelationInterface {
            public function name(): string
            {
                return 'notes';
            }
        };

        $items = new Items([['id' => 1, 'author_id' => 1]]);

        // only not resolvable relations, the items are returned as they are:
        $this->assertSame($items, new RelationEntityResolver(new Relations($notResolvable))->resolveForItems($items));

        $relations = $this->createRelations()->only('author')->withAdded($notResolvable);
        $all = new RelationEntityResolver($relations)->resolveForItems($items)->all();

        $this->assertSame(['id', 'author_id', 'author'], array_keys($all[0]));
    }

    public function testCustomResolvableRelation(): void
    {
        $relation = new class() implements ResolvableRelationInterface {
            public function name(): string
            {
                return 'slug';
            }

            public function resolveForItems(iterable $items): array
            {
                $resolved = [];

                foreach ($items as $key => $item) {
                    $item['slug'] = strtolower($item['title']);
                    $resolved[$key] = $item;
                }

                return $resolved;
            }
        };

        $all = new RelationEntityResolver(new Relations($relation))->resolveForItems(new Items([['title' => 'Hello']]))->all();

        $this->assertSame([['title' => 'Hello', 'slug' => 'hello']], $all);
    }

    public function testResolveForItemMethod(): void
    {
        $resolver = new RelationEntityResolver($this->createRelations());

        $attributes = $resolver->resolveForItem(new Item(['id' => 1, 'title' => 'Hello', 'author_id' => 2]));

        $this->assertSame(['id', 'title', 'author_id', 'author', 'tags', 'comments'], array_keys($attributes));
        $this->assertSame('Mia', $attributes['author']['name']);
        $this->assertSame(['php', 'cms'], $this->values($attributes['tags'], 'slug'));
        $this->assertSame(['Nice', 'Great'], $this->values($attributes['comments'], 'text'));

        // or an array:
        $this->assertSame('John', $resolver->resolveForItem(['id' => 2, 'author_id' => 1])['author']['name']);
    }

    public function testResolveForItemMethodWithoutRelations(): void
    {
        $resolver = new RelationEntityResolver(new Relations());

        $this->assertSame(['id' => 1, 'author_id' => 1], $resolver->resolveForItem(new Item(['id' => 1, 'author_id' => 1])));
        $this->assertSame(['id' => 1], $resolver->resolveForItem(['id' => 1]));
    }

    public function testThrowsRelationExceptionNamingTheFailingRelation(): void
    {
        $relation = new class() implements ResolvableRelationInterface {
            public function name(): string
            {
                return 'notes';
            }

            public function resolveForItems(iterable $items): array
            {
                throw new RuntimeException('Notes failed');
            }
        };

        $resolver = new RelationEntityResolver($this->createRelations()->only('author')->withAdded($relation));

        foreach ([
            fn () => $resolver->resolveForItems(new Items([['id' => 1, 'author_id' => 1]])),
            fn () => $resolver->resolveForItem(['id' => 1, 'author_id' => 1]),
        ] as $method) {
            try {
                $method();
                $this->fail('No exception thrown');
            } catch (RelationException $e) {
                $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
                $this->assertSame('Relation [notes] failed: Notes failed', $e->getMessage());
            }
        }
    }

    public function testThrowsTheRelationExceptionOfTheRelationUnchanged(): void
    {
        $resolver = new RelationEntityResolver(new Relations(
            new ManyToOne(name: 'author', foreignKey: 'author_id', repository: fn () => 'foo'),
        ));

        try {
            $resolver->resolveForItems(new Items([['id' => 1, 'author_id' => 1]]));
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            // not wrapped twice:
            $this->assertStringStartsWith('Relation [author] failed: The repository closure', $e->getMessage());
            $this->assertFalse($e->getPrevious() instanceof RelationException);
        }
    }
}