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
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\ManyToOneByType;
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

class ManyToOneByTypeTest extends TestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage(items: [
            'articles' => [
                1 => ['id' => 1, 'title' => 'Article One'],
                2 => ['id' => 2, 'title' => 'Article Two'],
            ],
            'pages' => [
                1 => ['id' => 1, 'title' => 'Page One'],
            ],
            'comments' => [
                1 => ['id' => 1, 'text' => 'Nice', 'commentable_type' => 'articles', 'commentable_id' => 1],
                2 => ['id' => 2, 'text' => 'Great', 'commentable_type' => 'pages', 'commentable_id' => 1],
                3 => ['id' => 3, 'text' => 'Again', 'commentable_type' => 'articles', 'commentable_id' => 1],
                4 => ['id' => 4, 'text' => 'Deleted', 'commentable_type' => 'articles', 'commentable_id' => 99],
                5 => ['id' => 5, 'text' => 'Unknown type', 'commentable_type' => 'videos', 'commentable_id' => 1],
                6 => ['id' => 6, 'text' => 'None', 'commentable_type' => null, 'commentable_id' => null],
            ],
        ]);
    }

    protected function createTitleRepository(StorageInterface $storage, string $table): StorageRepository
    {
        return new class(
            storage: $storage,
            table: $table,
            columns: [new Column\Id(), new Column\Text(name: 'title')],
        ) extends StorageRepository {};
    }

    protected function createRelation(StorageInterface $storage, int $chunkSize = 1000): ManyToOneByType
    {
        return new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: [
                'articles' => $this->createTitleRepository($storage, 'articles'),
                'pages' => fn () => $this->createTitleRepository($storage, 'pages'),
            ],
            chunkSize: $chunkSize,
        );
    }

    protected function createCommentRepository(StorageInterface $storage): RelationStorageRepository
    {
        return new class(
            storage: $storage,
            table: 'comments',
            columns: [
                new Column\Id(),
                new Column\Text(name: 'text'),
                new Column\Text(name: 'commentable_type')->type(nullable: true),
                new Column\Integer(name: 'commentable_id')->type(nullable: true),
            ],
            entityFactory: new RelationEntityFactory(),
            relations: [$this->createRelation($storage)],
        ) extends RelationStorageRepository {};
    }

    /**
     * @param iterable<object> $comments
     * @return array<int, string> The texts with the commentable title, e.g. 'Nice: Article One'.
     */
    protected function textsWithCommentable(iterable $comments): array
    {
        $lines = [];

        foreach ($comments as $comment) {
            $lines[] = $comment['text'].': '.($comment['commentable']['title'] ?? 'none');
        }

        return $lines;
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

    public function testImplementsInterfaces(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $this->assertInstanceOf(RelationInterface::class, $relation);
        $this->assertInstanceOf(ResolvableRelationInterface::class, $relation);
        $this->assertFalse($relation instanceof StorageAwareRelationInterface);
    }

    public function testGetterMethods(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $this->assertSame('commentable', $relation->name());
        $this->assertSame('commentable_type', $relation->typeColumn());
        $this->assertSame('commentable_id', $relation->foreignKey());
        $this->assertSame(['articles', 'pages'], $relation->types());
        $this->assertTrue($relation->hasType('pages'));
        $this->assertFalse($relation->hasType('videos'));
    }

    public function testTypesFromConfiguration(): void
    {
        $storage = $this->createStorage();
        $repositories = [];

        // e.g. from a config file:
        foreach (['articles', 'pages'] as $table) {
            $repositories[$table] = fn () => $this->createTitleRepository($storage, $table);
        }

        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: $repositories,
        );

        $this->assertSame(['articles', 'pages'], $relation->types());
        $this->assertSame('Page One', $relation->findRelatedForItem(['commentable_type' => 'pages', 'commentable_id' => 1])['title']);
    }

    public function testConstructorThrowsInvalidArgumentExceptionIfTypeIsNumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The types of the relation [commentable] must be non-empty, non-numeric strings');

        new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['1' => fn () => null],
        );
    }

    public function testConstructorThrowsInvalidArgumentExceptionIfTypeIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The types of the relation [commentable] must be non-empty, non-numeric strings');

        new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['' => fn () => null],
        );
    }

    public function testConstructorThrowsInvalidArgumentExceptionIfRepositoryIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The repository of the type [articles] of the relation [commentable] must be a');

        new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['articles' => 'foo'],
        );
    }

    public function testRepositoryMethod(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createTitleRepository($storage, 'articles');
        $pageRepository = $this->createTitleRepository($storage, 'pages');
        $calls = 0;

        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: [
                'articles' => $articleRepository,
                'pages' => function () use ($pageRepository, &$calls): ReadRepositoryInterface {
                    $calls++;
                    return $pageRepository;
                },
            ],
        );

        $this->assertSame($articleRepository, $relation->repository('articles'));
        $this->assertSame($pageRepository, $relation->repository('pages'));
        $this->assertSame($pageRepository, $relation->repository('pages'));
        $this->assertSame(1, $calls);
    }

    public function testRepositoryMethodThrowsLogicExceptionIfTypeIsUnknown(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The relation [commentable] has no repository for the type [videos]');

        $this->createRelation($this->createStorage())->repository('videos');
    }

    public function testRepositoryMethodThrowsInvalidArgumentExceptionIfClosureReturnsNoRepository(): void
    {
        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['articles' => fn () => 'foo'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The repository closure of the type [articles] of the relation [commentable] must return a');

        $relation->repository('articles');
    }

    public function testReadResolving(): void
    {
        $commentRepository = $this->createCommentRepository($this->createStorage());

        $this->assertSame(
            [
                'Nice: Article One',
                'Great: Page One',
                'Again: Article One',
                'Deleted: none',
                'Unknown type: none',
                'None: none',
            ],
            $this->textsWithCommentable($commentRepository->withResolvedRelations('commentable')->findAll())
        );
    }

    public function testReadFindRelatedForItemMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $this->assertSame('Article Two', $relation->findRelatedForItem(['commentable_type' => 'articles', 'commentable_id' => 2])['title']);
        $this->assertSame('Page One', $relation->findRelatedForItem(new Item(['commentable_type' => 'pages', 'commentable_id' => 1]))['title']);
        $this->assertNull($relation->findRelatedForItem(['commentable_type' => 'pages', 'commentable_id' => 2]));
        $this->assertNull($relation->findRelatedForItem(['commentable_type' => 'videos', 'commentable_id' => 1]));
        $this->assertNull($relation->findRelatedForItem(['commentable_type' => '', 'commentable_id' => 1]));
        $this->assertNull($relation->findRelatedForItem(['commentable_type' => 'articles', 'commentable_id' => null]));
        $this->assertNull($relation->findRelatedForItem(['commentable_type' => null, 'commentable_id' => 1]));
        $this->assertNull($relation->findRelatedForItem(['commentable_id' => 1]));
    }

    public function testReadFindRelatedForItemsMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $related = $relation->findRelatedForItems([
            ['commentable_type' => 'articles', 'commentable_id' => 2],
            ['commentable_type' => 'pages', 'commentable_id' => 1],
            ['commentable_type' => 'articles', 'commentable_id' => 1],
            ['commentable_type' => 'articles', 'commentable_id' => 2],
            ['commentable_type' => 'articles', 'commentable_id' => 99],
            ['commentable_type' => 'videos', 'commentable_id' => 1],
            ['commentable_type' => null, 'commentable_id' => null],
        ]);

        // grouped by type and keyed by the related id, missing ones are skipped:
        $this->assertSame(['articles', 'pages'], $this->sortedKeys($related));
        $this->assertSame([1, 2], $this->sortedKeys($related['articles']));
        $this->assertSame([1], array_keys($related['pages']));
        $this->assertSame('Article Two', $related['articles'][2]['title']);
        $this->assertSame('Page One', $related['pages'][1]['title']);
        $this->assertSame([], $relation->findRelatedForItems([]));
    }

    public function testReadLoadsOnceForEachType(): void
    {
        $storage = $this->createStorage();
        $queries = [];

        $createRepository = function (string $table) use ($storage, &$queries): ReadRepositoryInterface {
            return new class($storage, $table, $queries) extends StorageRepository {
                public function __construct(StorageInterface $storage, string $table, private array &$queries)
                {
                    parent::__construct(
                        storage: $storage,
                        table: $table,
                        columns: [new Column\Id(), new Column\Text(name: 'title')],
                    );
                }

                public function findByIds(int|string ...$ids): iterable
                {
                    $this->queries[] = $this->table.': '.implode(',', $ids);
                    return parent::findByIds(...$ids);
                }
            };
        };

        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: [
                'articles' => $createRepository('articles'),
                'pages' => $createRepository('pages'),
            ],
        );

        $relation->resolveForItems([
            ['commentable_type' => 'articles', 'commentable_id' => 1],
            ['commentable_type' => 'pages', 'commentable_id' => 1],
            ['commentable_type' => 'articles', 'commentable_id' => 2],
            ['commentable_type' => 'articles', 'commentable_id' => 1],
            ['commentable_type' => 'videos', 'commentable_id' => 1],
        ]);

        $this->assertSame(['articles: 1,2', 'pages: 1'], $queries);
    }

    public function testReadFindByIdsMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $this->assertSame([1, 2], $this->sortedKeys($relation->findByIds(type: 'articles', ids: [1, 2, 99])));
        $this->assertSame([1], array_keys($relation->findByIds(type: 'pages', ids: [1, 2])));
        $this->assertSame([], $relation->findByIds(type: 'articles', ids: []));
    }

    public function testReadInChunksReturnsTheSameResults(): void
    {
        $relation = $this->createRelation($this->createStorage(), chunkSize: 1);

        $this->assertSame([1, 2], $this->sortedKeys($relation->findByIds(type: 'articles', ids: [1, 2, 99])));
    }

    public function testReadResolveForItemsMethod(): void
    {
        $relation = $this->createRelation($this->createStorage());

        $items = $relation->resolveForItems([
            'a' => ['text' => 'Nice', 'commentable_type' => 'articles', 'commentable_id' => 1],
            'b' => ['text' => 'Great', 'commentable_type' => 'pages', 'commentable_id' => 1],
            'c' => ['text' => 'Deleted', 'commentable_type' => 'pages', 'commentable_id' => 99, 'commentable' => 'replaced'],
            'd' => ['text' => 'Unknown type', 'commentable_type' => 'videos', 'commentable_id' => 1],
            'e' => ['text' => 'None'],
        ]);

        // the keys are kept:
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_keys($items));
        $this->assertSame('Article One', $items['a']['commentable']['title']);
        $this->assertSame('Page One', $items['b']['commentable']['title']);
        $this->assertNull($items['c']['commentable']);
        $this->assertNull($items['d']['commentable']);
        $this->assertNull($items['e']['commentable']);
        $this->assertSame('Nice', $items['a']['text']);
    }

    public function testReadOwnObjectsAsItemsUsingResolvers(): void
    {
        $storage = $this->createStorage();

        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: [
                'articles' => $this->createTitleRepository($storage, 'articles'),
                'pages' => $this->createTitleRepository($storage, 'pages'),
            ],
            typeResolver: fn (object $comment): mixed => $comment->commentableType(),
            foreignKeyResolver: fn (object $comment): mixed => $comment->commentableId(),
        );

        $comment = new class('pages', 1) {
            public function __construct(private string $type, private int $id) {}

            public function commentableType(): string
            {
                return $this->type;
            }

            public function commentableId(): int
            {
                return $this->id;
            }
        };

        $this->assertSame('Page One', $relation->findRelatedForItem($comment)['title']);
        $this->assertSame(['pages'], array_keys($relation->findRelatedForItems([$comment])));
    }

    public function testReadOwnObjectsAsRelatedEntities(): void
    {
        $storage = $this->createStorage();

        // a repository creating own article objects without ArrayAccess:
        $articleRepository = new class(
            storage: $storage,
            table: 'articles',
            columns: [new Column\Id(), new Column\Text(name: 'title')],
            entityFactory: new class() extends EntityFactory {
                public function createEntityFromArray(array $attributes): object
                {
                    return new class($attributes['id'], $attributes['title']) {
                        public function __construct(private int $id, private string $title) {}

                        public function id(): int
                        {
                            return $this->id;
                        }

                        public function title(): string
                        {
                            return $this->title;
                        }
                    };
                }
            },
        ) extends StorageRepository {};

        // the key by the method named like the related key:
        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['articles' => $articleRepository],
        );

        $this->assertSame('Article Two', $relation->findByIds(type: 'articles', ids: [2])[2]->title());

        // the key by the key resolver:
        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['articles' => $articleRepository],
            relatedKey: 'unknown',
            keyResolver: fn (object $article): mixed => $article->id(),
        );

        $related = $relation->findRelatedForItems([['commentable_type' => 'articles', 'commentable_id' => 1]]);

        $this->assertSame('Article One', $related['articles'][1]->title());
    }

    public function testWrite(): void
    {
        $commentRepository = $this->createCommentRepository($this->createStorage());

        $commentRepository->create(['text' => 'New', 'commentable_type' => 'pages', 'commentable_id' => 1]);
        $commentRepository->updateById(6, ['commentable_type' => 'articles', 'commentable_id' => 2]);

        $comments = $commentRepository->withResolvedRelations('commentable');

        $this->assertSame('Page One', $comments->findOne(where: ['text' => 'New'])['commentable']['title']);
        $this->assertSame('Article Two', $comments->findById(6)['commentable']['title']);

        $commentRepository->updateById(6, ['commentable_type' => null, 'commentable_id' => null]);

        $this->assertNull($comments->findById(6)['commentable']);
    }

    public function testDeleteDoesNotChangeTheOtherSide(): void
    {
        $storage = $this->createStorage();
        $articleRepository = $this->createTitleRepository($storage, 'articles');
        $commentRepository = $this->createCommentRepository($storage);

        // deleting a comment keeps its article:
        $commentRepository->deleteById(1);

        $this->assertSame('Article One', $articleRepository->findById(1)['title']);

        // deleting an article keeps its comments with type and id, resolving null:
        $articleRepository->deleteById(1);

        $comment = $commentRepository->withResolvedRelations('commentable')->findById(3);

        $this->assertSame('articles', $comment['commentable_type']);
        $this->assertSame(1, $comment['commentable_id']);
        $this->assertNull($comment['commentable']);
    }

    public function testThrowsRelationExceptionIfClosureReturnsNoRepository(): void
    {
        $relation = new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: ['articles' => fn () => 'foo'],
        );

        try {
            $relation->findRelatedForItems([['commentable_type' => 'articles', 'commentable_id' => 1]]);
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            $this->assertSame(
                'Relation [commentable] failed: The repository closure of the type [articles] of the relation [commentable] must return a '.ReadRepositoryInterface::class,
                $e->getMessage()
            );
        }
    }

    public function testFindByIdsMethodThrowsRelationExceptionIfTypeIsUnknown(): void
    {
        try {
            $this->createRelation($this->createStorage())->findByIds(type: 'videos', ids: [1]);
            $this->fail('No exception thrown');
        } catch (RelationException $e) {
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
            $this->assertSame(
                'Relation [commentable] failed: The relation [commentable] has no repository for the type [videos]',
                $e->getMessage()
            );
        }
    }
}