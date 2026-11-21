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

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationalRepository;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\PdoSqliteStorage;
use Tobento\Service\Storage\StorageInterface;

#[RequiresPhpExtension('pdo_sqlite')]
class RelationalRepositorySqliteTest extends TestCase
{
    /**
     * Creates a SQLite database in memory with the tables, logging the prepared queries.
     */
    protected function createPdo(): PDO
    {
        $pdo = new class('sqlite::memory:') extends PDO {
            public array $queries = [];

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->queries[] = $query;
                return parent::prepare($query, $options);
            }
        };

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT, country_id INTEGER)');
        $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, slug TEXT)');
        $pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT, status TEXT, position INTEGER, author_id INTEGER)');
        $pdo->exec('CREATE TABLE resource_relations (id INTEGER PRIMARY KEY, owner_type TEXT, owner_id INTEGER, related_type TEXT, related_id INTEGER)');

        $pdo->exec("INSERT INTO authors (id, name, country_id) VALUES (1, 'John', 5), (2, 'Mia', 6), (3, 'Lea', 5)");
        $pdo->exec("INSERT INTO tags (id, slug) VALUES (1, 'php'), (2, 'cms'), (3, 'unused')");
        $pdo->exec("INSERT INTO articles (id, title, status, position, author_id) VALUES
            (1, 'One', 'published', 3, 1),
            (2, 'Two', 'published', 1, 2),
            (3, 'Three', 'draft', 2, 1),
            (4, 'Four', 'published', 4, 3),
            (5, 'Five', 'published', 5, NULL)");

        return $pdo;
    }

    /**
     * Creates the article repository with its tags (php: 1, 2, 3, 5 and cms: 2, 4)
     * and its categories (cms: 1, 3) sharing the pivot table and the ids with the tags.
     *
     * @param StorageInterface $storage
     * @param null|StorageInterface $pivotStorage The storage of the pivot rows, null the article storage.
     * @return RelationStorageRepository
     */
    protected function createArticleRepository(
        StorageInterface $storage,
        null|StorageInterface $pivotStorage = null,
    ): RelationStorageRepository {
        $tagRepository = new class(
            storage: $storage,
            table: 'tags',
            columns: [new Column\Id(), new Column\Text(name: 'slug')],
        ) extends StorageRepository {};

        $authorRepository = new class(
            storage: $storage,
            table: 'authors',
            columns: [new Column\Id(), new Column\Text(name: 'name'), new Column\Integer(name: 'country_id')],
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
                    repository: $tagRepository,
                    storage: $pivotStorage,
                ),
                new ManyToMany(
                    name: 'categories',
                    table: 'resource_relations',
                    ownerType: 'articles',
                    relatedType: 'categories',
                    repository: $tagRepository,
                    storage: $pivotStorage,
                ),
                new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository),
            ],
        ) extends RelationStorageRepository {};

        $tags = $articleRepository->relations()->getManyToMany('tags');
        $tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1]);
        $tags->updateRelatedForOwner(ownerId: 2, relatedIds: [1, 2]);
        $tags->updateRelatedForOwner(ownerId: 3, relatedIds: [1]);
        $tags->updateRelatedForOwner(ownerId: 4, relatedIds: [2]);
        $tags->updateRelatedForOwner(ownerId: 5, relatedIds: [1]);

        $categories = $articleRepository->relations()->getManyToMany('categories');
        $categories->updateRelatedForOwner(ownerId: 1, relatedIds: [2]);
        $categories->updateRelatedForOwner(ownerId: 3, relatedIds: [2]);

        return $articleRepository;
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

    /**
     * Returns the logged queries reading the articles.
     *
     * @return array<int, string>
     */
    protected function articleQueries(PDO $pdo): array
    {
        return array_values(array_filter(
            $pdo->queries,
            fn (string $query): bool => str_starts_with($query, 'SELECT') && str_contains($query, 'FROM `articles`')
        ));
    }

    public function testFindAllWithPivotInSameStorageUsesSubquery(): void
    {
        $pdo = $this->createPdo();
        $articles = new RelationalRepository(repository: $this->createArticleRepository(new PdoSqliteStorage($pdo)));

        $pdo->queries = [];

        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'cms']])));

        // one query for the tags, one for the articles with the pivot as subquery:
        $this->assertCount(2, $pdo->queries);
        $this->assertCount(1, $this->articleQueries($pdo));
        $this->assertStringContainsString('resource_relations', $this->articleQueries($pdo)[0]);
    }

    public function testFindAllWithPivotStorageUsingSamePdoUsesSubquery(): void
    {
        $pdo = $this->createPdo();
        $storage = new PdoSqliteStorage($pdo);
        $pivotStorage = new PdoSqliteStorage($pdo);
        $articles = new RelationalRepository(repository: $this->createArticleRepository($storage, $pivotStorage));

        $this->assertNull($storage->tables()->getTable('resource_relations'));

        $pdo->queries = [];

        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'cms']])));
        $this->assertStringContainsString('resource_relations', $this->articleQueries($pdo)[0]);

        // the pivot table got registered on the storage of the articles:
        $this->assertNotNull($storage->tables()->getTable('resource_relations'));
    }

    public function testFindAllWithPivotInOtherDatabaseUsesIds(): void
    {
        $pdo = $this->createPdo();
        $pivotPdo = $this->createPdo();
        $articles = new RelationalRepository(
            repository: $this->createArticleRepository(new PdoSqliteStorage($pdo), new PdoSqliteStorage($pivotPdo))
        );

        $pdo->queries = [];

        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'cms']])));
        $this->assertStringNotContainsString('resource_relations', $this->articleQueries($pdo)[0]);
        $this->assertSame([['id' => ['in' => [2, 4]]]], $articles->buildWhere(relatedWhere: ['tags' => ['slug' => 'cms']]));
    }

    public function testSubqueryOnlyMatchesItsTypes(): void
    {
        $articles = new RelationalRepository(repository: $this->createArticleRepository(new PdoSqliteStorage($this->createPdo())));

        $this->assertSame([2, 4], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'cms']])));
        $this->assertSame([1, 3], $this->ids($articles->findAll(relatedWhere: ['categories' => ['slug' => 'cms']])));
        $this->assertSame([], $this->ids($articles->findAll(relatedWhere: ['categories' => ['slug' => 'php']])));
    }

    public function testSubqueryWithoutMatchingPivotRows(): void
    {
        $articles = new RelationalRepository(repository: $this->createArticleRepository(new PdoSqliteStorage($this->createPdo())));

        $this->assertSame([], $this->ids($articles->findAll(relatedWhere: ['tags' => ['slug' => 'unused']])));
        $this->assertSame(0, $articles->count(relatedWhere: ['tags' => ['slug' => 'unused']]));
        $this->assertNull($articles->findOne(relatedWhere: ['tags' => ['slug' => 'unused']]));
    }

    public function testSeveralRelationsWithOrderByLimitAndCount(): void
    {
        $articles = new RelationalRepository(repository: $this->createArticleRepository(new PdoSqliteStorage($this->createPdo())));

        $relatedWhere = [
            'tags' => ['slug' => 'php'],
            'categories' => ['slug' => 'cms'],
            'author' => ['country_id' => 5],
        ];

        $this->assertSame([1, 3], $this->ids($articles->findAll(relatedWhere: $relatedWhere)));
        $this->assertSame(2, $articles->count(relatedWhere: $relatedWhere));
        $this->assertSame(1, $articles->count(where: ['status' => 'published'], relatedWhere: $relatedWhere));

        $titles = [];

        foreach ($articles->findAll(
            where: ['status' => 'published'],
            relatedWhere: ['tags' => ['slug' => 'php']],
            orderBy: ['position' => 'desc'],
            limit: 2,
        ) as $article) {
            $titles[] = $article['title'];
        }

        $this->assertSame(['Five', 'One'], $titles);
        $this->assertSame(3, $articles->findOne(where: ['status' => 'draft'], relatedWhere: $relatedWhere)['id']);
    }

    public function testThrowsRelationExceptionIfTheMainQueryWithSubqueryFails(): void
    {
        foreach (['findAll', 'findOne', 'count'] as $method) {
            $pdo = $this->createPdo();
            $articles = new RelationalRepository(repository: $this->createArticleRepository(new PdoSqliteStorage($pdo)));

            // e.g. a missing migration of the pivot table:
            $pdo->exec('DROP TABLE resource_relations');

            try {
                $articles->{$method}(relatedWhere: ['tags' => ['slug' => 'cms'], 'author' => ['country_id' => 5]]);
                $this->fail('No exception thrown');
            } catch (RelationException $e) {
                // the subqueries are part of the main query, so all relations are named:
                $this->assertNotNull($e->getPrevious());
                $this->assertStringStartsWith('Relation [tags, author] failed: ', $e->getMessage());
            }
        }
    }
}