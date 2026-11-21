# Repository Relation Service

Explicit relations between [storage repositories](https://github.com/tobento-ch/service-repository-storage), without hidden queries.

## Table of Contents

- [Getting started](#getting-started)
    - [Requirements](#requirements)
    - [Highlights](#highlights)
    - [Simple Example](#simple-example)
- [Documentation](#documentation)
    - [Relation Storage Repository](#relation-storage-repository)
        - [Create Relation Storage Repository](#create-relation-storage-repository)
        - [Relations Aware Repositories](#relations-aware-repositories)
        - [Resolving Relations](#resolving-relations)
        - [Deleting With Relations](#deleting-with-relations)
        - [Repositories Not Extending It](#repositories-not-extending-it)
    - [Relation Entity Factory](#relation-entity-factory)
    - [Relations](#relations)
    - [Available Relations](#available-relations)
        - [Many To One](#many-to-one)
        - [One To Many](#one-to-many)
        - [Many To Many](#many-to-many)
        - [Many To Many Inverse](#many-to-many-inverse)
        - [Many To One By Type](#many-to-one-by-type)
        - [Create Custom Relation](#create-custom-relation)
    - [Relational Repository](#relational-repository)
    - [Migration](#migration)
    - [Exceptions](#exceptions)
    - [Comparison](#comparison)
- [Credits](#credits)
___

# Getting started

Add the latest version of the repository relation service project running this command.

```
composer require tobento/service-repository-relation
```

## Requirements

- PHP 8.4 or greater

## Highlights

- Framework-agnostic, will work with any project
- Decoupled design: relations are objects passed to a repository, entities stay plain
- No N+1: one query per relation, whatever the number of entities
- Works with any storage, also across storages
- Querying by related entities with sorting and pagination in one query

## Simple Example

Here is a simple example of how to use the Repository Relation service.

```php
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;

$storage = new InMemoryStorage(items: [
    'authors' => [1 => ['id' => 1, 'name' => 'John']],
    'tags' => [1 => ['id' => 1, 'name' => 'php'], 2 => ['id' => 2, 'name' => 'cms']],
    'articles' => [1 => ['id' => 1, 'title' => 'Hello', 'author_id' => 1]],
]);

class AuthorRepository extends StorageRepository {}
class TagRepository extends StorageRepository {}
class ArticleRepository extends RelationStorageRepository {}

$authorRepository = new AuthorRepository(
    storage: $storage,
    table: 'authors',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
);

$tagRepository = new TagRepository(
    storage: $storage,
    table: 'tags',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
);

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [new Column\Id(), new Column\Text(name: 'title'), new Column\Integer(name: 'author_id')],
    entityFactory: new RelationEntityFactory(),
    relations: [
        new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository),
        new ManyToMany(name: 'tags', table: 'resource_relations', ownerType: 'articles', relatedType: 'tags', repository: $tagRepository),
    ],
);

// link the tags 1 and 2 to the article 1:
$articleRepository->relations()->getManyToMany('tags')->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);

// find the article with its author and tags:
$article = $articleRepository->withResolvedRelations('author', 'tags')->findById(1);

var_dump($article['author']['name']);
// string(4) "John"

var_dump(array_map(fn (object $tag): string => $tag['name'], $article['tags']));
// array(2) { [0]=> string(3) "php" [1]=> string(3) "cms" }
```

# Documentation

Check out the [Repository Storage Service](https://github.com/tobento-ch/service-repository-storage) for the storage repositories and the [Storage Service](https://github.com/tobento-ch/service-storage#storages) for the available storages.

## Relation Storage Repository

### Create Relation Storage Repository

The relations need the related repositories. There are two ways to provide them.

In an application, type-hint the repository interfaces, as shown here. The other examples use the repository classes to stay short.

**Relations defined in the repository**

Inject the related repositories by the constructor and define the relations in the ```configureRelations``` method:

```php
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationsInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\RepositoryInterface;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Storage\StorageInterface;

// the repository interfaces of the application:
interface ArticleRepositoryInterface extends RepositoryInterface {}
interface AuthorRepositoryInterface extends RepositoryInterface {}
interface TagRepositoryInterface extends RepositoryInterface {}

class ArticleRepository extends RelationStorageRepository implements ArticleRepositoryInterface
{
    public function __construct(
        StorageInterface $storage,
        protected AuthorRepositoryInterface $authorRepository,
        protected TagRepositoryInterface $tagRepository,
    ) {
        parent::__construct(
            storage: $storage,
            table: 'articles',
            columns: [
                new Column\Id(),
                new Column\Text(name: 'title'),
                new Column\Integer(name: 'author_id'),
            ],
            // resolves the selected relations, no own code needed:
            entityFactory: new RelationEntityFactory(),
        );
    }

    protected function configureRelations(): iterable|RelationsInterface
    {
        return [
            new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $this->authorRepository),
            new ManyToMany(name: 'tags', table: 'resource_relations', ownerType: 'articles', relatedType: 'tags', repository: $this->tagRepository),
        ];
    }
}

$articleRepository = new ArticleRepository(
    storage: $storage,
    authorRepository: $authorRepository,
    tagRepository: $tagRepository,
);
```

The promoted properties are set before ```parent::__construct()``` is called, so ```configureRelations()``` can use them.

The ```configureRelations``` method may return an array or a ```Relations``` object. It is only used if no relations are passed by the ```relations``` parameter.

Define all relations of the repository. A relation does not query anything until it is resolved by ```withResolvedRelations()```, and it deletes its data with the deleted entities.

**Relations passed from outside**

Pass the relations by the ```relations``` parameter, e.g. when creating the repository on app boot. Instead of the related repository, a closure returning it may be passed. It is called without arguments on first use, e.g. to get the repository from the container only when needed:

```php
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;

class ArticleRepository extends RelationStorageRepository implements ArticleRepositoryInterface
{
    //
}

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [
        new Column\Id(),
        new Column\Text(name: 'title'),
        new Column\Integer(name: 'author_id'),
    ],
    entityFactory: new RelationEntityFactory(),
    relations: [
        new ManyToOne(
            name: 'author',
            foreignKey: 'author_id',
            repository: fn () => $container->get(AuthorRepositoryInterface::class),
        ),
        new ManyToMany(
            name: 'tags',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
            repository: fn () => $container->get(TagRepositoryInterface::class),
        ),
    ],
);
```

**Circular dependencies**

If two repositories need each other, e.g. the article repository its authors and the author repository its articles (```OneToMany```), they can not be injected into each other. Use a closure getting the repository from the container on first use, as above, when both repositories exist already.

In both ways, the repository passes its storage to the relations having none (e.g. for the pivot table of the ```ManyToMany```) and the relations to the entity factory.

### Relations Aware Repositories

Code working with any repository, e.g. a list or a filter, can check for relations by the ```RelationsAwareInterface```, implemented by the ```RelationStorageRepository::class```:

```php
use Tobento\Service\Repository\Relation\RelationsAwareInterface;

if ($repository instanceof RelationsAwareInterface) {
    // the relations:
    var_dump($repository->relations()->has('tags'));
    // bool(true)

    // resolving:
    $repository = $repository->withResolvedRelations('tags');
}

$entities = $repository->findAll();
```

The interface provides ```relations()```, ```withResolvedRelations()```, ```withAllResolvedRelations()```, ```withoutResolvedRelations()``` and ```resolvedRelations()```.

For relation names from configuration or stored settings, resolve only the existing ones, as unknown names throw a ```RelationNotFoundException```:

```php
$names = array_filter($names, fn (string $name): bool => $repository->relations()->has($name));

$repository = $repository->withResolvedRelations(...$names);
```

Repositories not extending the ```RelationStorageRepository::class``` may implement the interface too, see [Repositories Not Extending It](#repositories-not-extending-it).

### Resolving Relations

Nothing is resolved by default. Select the relations to resolve per call. Each method returns a new instance, the original repository stays unchanged:

```php
// author and tags:
$articles = $articleRepository->withResolvedRelations('author', 'tags')->findAll();

// all relations:
$articles = $articleRepository->withAllResolvedRelations()->findAll();

// all but the tags:
$articles = $articleRepository->withAllResolvedRelations()->withoutResolvedRelations('tags')->findAll();

var_dump($articleRepository->withResolvedRelations('author')->resolvedRelations());
// array(1) { [0]=> string(6) "author" }
```

The related entities are assigned under the relation name, e.g. ```$article['author']```:

| Relation | Assigned |
| --- | --- |
| ```ManyToOne```, ```ManyToOneByType``` | the entity, or ```null``` |
| ```OneToMany```, ```ManyToMany``` | the list of entities, or ```[]``` |
| ```ManyToMany``` without repository | the list of ids |

Each relation loads its related entities for all found entities at once, e.g. ```findAll(limit: 1000)``` with author and tags takes 4 queries.

Unknown names throw a ```RelationNotFoundException```, e.g. ```withResolvedRelations('autor')```.

### Deleting With Relations

```deleteById()``` and ```delete()``` delete the relation data of the deleted entities too, e.g. the pivot rows of the ```ManyToMany``` relations:

```php
$articleRepository->deleteById(1);

var_dump($articleRepository->relations()->getManyToMany('tags')->findRelatedIdsForOwner(ownerId: 1));
// array(0) { }
```

The related entities themselves are not deleted, e.g. the author or the tags of a deleted article.

### Repositories Not Extending It

Repositories not able to extend the ```RelationStorageRepository::class``` may use the ```HasRelations``` trait and implement the ```RelationsAwareInterface```, doing the wiring done by the ```RelationStorageRepository::class``` by hand:

```php
use Tobento\Service\Repository\Relation\HasRelations;
use Tobento\Service\Repository\Relation\RelationsAwareInterface;

class ArticleRepository extends OwnBaseRepository implements RelationsAwareInterface
{
    use HasRelations;

    // in the constructor: set the relations by $this->processRelations($relations),
    // pass the storage to the relations having none and the relations to the entity factory.

    // implement withResolvedRelations(), withAllResolvedRelations(),
    // withoutResolvedRelations() and resolvedRelations().

    // override deleteById() and delete() to delete the relation data.
}
```

Check out the ```RelationStorageRepository::class``` for the full implementation.

## Relation Entity Factory

The ```RelationEntityFactory::class``` resolves the selected relations before creating the entities. The relations are passed by the repository, so the standard case needs no code:

```php
use Tobento\Service\Repository\Relation\RelationEntityFactory;

class ArticleEntityFactory extends RelationEntityFactory
{
    // e.g. createEntityFromArray() for own entities,
    // receiving the attributes with the resolved relations.
}

// resolving the author by default:
$entityFactory = new ArticleEntityFactory(resolveRelations: ['author']);
```

Factories not able to extend it may use the ```HasEntityFactoryRelations``` trait and implement the ```RelationsAwareEntityFactoryInterface``` and the ```RelationsAwareInterface```, calling ```resolveRelationsForItems()``` and ```resolveRelationsForItem()``` in their create methods.

The ```RelationEntityResolver::class``` resolves relations for storage items without a factory, e.g. ```new RelationEntityResolver($repository->relations()->only('author'))->resolveForItems($items)```.

## Relations

The ```Relations::class``` is the collection of the relations of a repository:

```php
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationInterface;
use Tobento\Service\Repository\Relation\Relations;

$relations = new Relations(
    new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository),
);

// a new instance, a relation with the same name replaces the existing one:
$relations = $relations->withAdded($tagsRelation);

var_dump($relations->has('author'));
// bool(true)

// typed:
$author = $relations->get('author', ManyToOne::class);
$author = $relations->getManyToOne('author');
$tags = $relations->getManyToMany('tags');
$articles = $relations->getOneToMany('articles');
$commentable = $relations->getManyToOneByType('commentable');

// new instances, unknown names are ignored:
$relations = $relations->only('author', 'tags');
$relations = $relations->except('tags');
$relations = $relations->filter(fn (RelationInterface $relation): bool => $relation instanceof ManyToOne);

$all = $relations->all(); // ['author' => ManyToOne]
```

An unknown name or a wrong type throws a ```RelationNotFoundException```, e.g. "Relation [author] must be a ...\ManyToMany, ...\ManyToOne given".

## Available Relations

Three rules apply to all relations:

- **Foreign key relations only read:** ```ManyToOne```, ```OneToMany``` and ```ManyToOneByType``` use a column of an entity (e.g. ```articles.author_id```). You set the column like any other, by the repository of the entity having it.
- **Pivot relations write:** ```ManyToMany``` and ```ManyToManyInverse``` store the links in a pivot table. The relation writes them (```updateRelatedForOwner()```), and the repository deletes them with its deleted entities.
- **Resolve or load yourself:** ```withResolvedRelations()``` loads the related entities with the found ones, the usual way. The relation methods load them for entities you already have.

Deleting an entity never deletes the related entities, e.g. deleting an author keeps its articles.

### Many To One

For instance, an article having one author, the foreign key being a column of the article (```articles.author_id```).

```php
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\ManyToOne;

$relation = new ManyToOne(
    name: 'author',
    foreignKey: 'author_id',
    // the related repository, or a closure returning it, resolved on first use:
    repository: fn () => $authorRepository, // ReadRepositoryInterface|Closure
    // optional:
    relatedKey: 'id',
    keyResolver: null, // null|Closure, e.g. fn (Author $author): int => $author->id()
    foreignKeyResolver: null, // null|Closure, e.g. fn (Article $article): ?int => $article->authorId()
    chunkSize: 1000,
);
```

If the foreign key is optional, make the column nullable, otherwise ```null``` is read as ```0```.

#### Example

```php
use Tobento\Service\Repository\Relation\ManyToOne;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;

$storage = new InMemoryStorage(items: [
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

class AuthorRepository extends StorageRepository {}
class ArticleRepository extends RelationStorageRepository {}

$authorRepository = new AuthorRepository(
    storage: $storage,
    table: 'authors',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
);

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [
        new Column\Id(),
        new Column\Text(name: 'title'),
        // nullable, as an article may have no author:
        new Column\Integer(name: 'author_id')->type(nullable: true),
    ],
    entityFactory: new RelationEntityFactory(),
    relations: [
        new ManyToOne(name: 'author', foreignKey: 'author_id', repository: $authorRepository),
    ],
);
```

#### Reading

**Resolving**

The usual way, the articles with their author. An unknown or missing author is ```null```:

```php
$articles = $articleRepository->withResolvedRelations('author')->findAll();

foreach ($articles as $article) {
    var_dump($article['title'].': '.($article['author']['name'] ?? 'none'));
}
// string(11) "Hello: John"
// string(10) "World: Mia"
// string(20) "Deleted author: none"
// string(15) "No author: none"

// without resolving, the author is not loaded:
var_dump(isset($articleRepository->findById(2)['author']));
// bool(false)
```

**Loading later**

For articles found without resolving, e.g. if only the view decides to show the author. The relation loads the authors only, without finding the articles again:

```php
$relation = $articleRepository->relations()->getManyToOne('author');

$article = $articleRepository->findById(2);

var_dump($relation->findRelatedForItem($article)['name']);
// string(3) "Mia"

// for a list, one query for all authors, keyed by the author id:
$articles = $articleRepository->findAll(limit: 2);
$authors = $relation->findRelatedForItems($articles);

foreach ($articles as $article) {
    var_dump($article['title'].': '.($authors[$article['author_id']]['name'] ?? 'none'));
}
// string(11) "Hello: John"
// string(10) "World: Mia"

// only having ids, e.g. the selected authors of a filter, missing ones are skipped:
var_dump(array_keys($relation->findByIds([1, 99])));
// array(1) { [0]=> int(1) }
```

**Own objects**

For items not coming from the repository, using a foreign key resolver:

```php
class Article
{
    public function __construct(
        private ?int $authorId,
    ) {}

    public function authorId(): ?int
    {
        return $this->authorId;
    }
}

$relation = new ManyToOne(
    name: 'author',
    foreignKey: 'author_id',
    repository: $authorRepository,
    foreignKeyResolver: fn (Article $article): ?int => $article->authorId(),
);

var_dump($relation->findRelatedForItem(new Article(authorId: 2))['name']);
// string(3) "Mia"

var_dump($relation->findRelatedForItem(new Article(authorId: null)));
// NULL
```

#### Writing

The relation does not write. The foreign key is a column of the article, set like any other column when creating or updating the article:

```php
// creating an article with its author:
$articleRepository->create(['title' => 'New', 'author_id' => 2]);

// changing the author:
$articleRepository->updateById(4, ['author_id' => 1]);

var_dump($articleRepository->withResolvedRelations('author')->findById(4)['author']['name']);
// string(4) "John"

// removing the author, the column being nullable:
$articleRepository->updateById(4, ['author_id' => null]);
```

#### Deleting

Deleting does not change the other side. Deleting an article keeps its author. Deleting an author keeps the articles with its id, resolving ```null```:

```php
$authorRepository->deleteById(1);

var_dump($articleRepository->withResolvedRelations('author')->findById(1)['author']);
// NULL
```

To remove the deleted author from the articles, update them by the article repository:

```php
$articleRepository->update(where: ['author_id' => 2], attributes: ['author_id' => null]);
$authorRepository->deleteById(2);

var_dump($articleRepository->findById(2)['author_id']);
// NULL
```

### One To Many

For instance, an author having many articles, the foreign key being a column of the related articles (```articles.author_id```). The other side of the [Many To One](#many-to-one), set on the author repository.

```php
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\OneToMany;

$relation = new OneToMany(
    name: 'articles',
    foreignKey: 'author_id',
    // the related repository, or a closure returning it, resolved on first use:
    repository: fn () => $articleRepository, // ReadRepositoryInterface|Closure
    // optional:
    where: ['status' => 'published'], // applied to every query
    orderBy: ['title' => 'asc'], // the order of the articles per author
    ownerKey: 'id',
    foreignKeyResolver: null, // null|Closure, e.g. fn (Article $article): ?int => $article->authorId()
    chunkSize: 1000,
);
```

#### Example

```php
use Tobento\Service\Repository\Relation\OneToMany;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;

$storage = new InMemoryStorage(items: [
    'authors' => [
        1 => ['id' => 1, 'name' => 'John'],
        2 => ['id' => 2, 'name' => 'Mia'],
        3 => ['id' => 3, 'name' => 'Tom'],
    ],
    'articles' => [
        1 => ['id' => 1, 'title' => 'Hello', 'status' => 'published', 'author_id' => 1],
        2 => ['id' => 2, 'title' => 'Again', 'status' => 'published', 'author_id' => 1],
        3 => ['id' => 3, 'title' => 'Draft', 'status' => 'draft', 'author_id' => 1],
        4 => ['id' => 4, 'title' => 'World', 'status' => 'published', 'author_id' => 2],
    ],
]);

class ArticleRepository extends StorageRepository {}
class AuthorRepository extends RelationStorageRepository {}

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [
        new Column\Id(),
        new Column\Text(name: 'title'),
        new Column\Text(name: 'status'),
        new Column\Integer(name: 'author_id'),
    ],
);

$authorRepository = new AuthorRepository(
    storage: $storage,
    table: 'authors',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
    entityFactory: new RelationEntityFactory(),
    relations: [
        new OneToMany(
            name: 'articles',
            foreignKey: 'author_id',
            repository: $articleRepository,
            where: ['status' => 'published'],
            orderBy: ['title' => 'asc'],
        ),
    ],
);
```

#### Reading

**Resolving**

The usual way, the authors with their published articles. An author without articles gets ```[]```:

```php
$authors = $authorRepository->withResolvedRelations('articles')->findAll();

foreach ($authors as $author) {
    $titles = array_map(fn (object $article): string => $article['title'], $author['articles']);
    var_dump($author['name'].': '.implode(', ', $titles));
}
// string(18) "John: Again, Hello"
// string(10) "Mia: World"
// string(5) "Tom: "
```

**Loading later**

For authors found without resolving, e.g. if only the author page shows the articles. The relation loads the articles only, without finding the authors again:

```php
$relation = $authorRepository->relations()->getOneToMany('articles');

$articles = $relation->findRelatedForOwner(ownerId: 1);

var_dump(array_map(fn (object $article): string => $article['title'], $articles));
// array(2) { [0]=> string(5) "Again" [1]=> string(5) "Hello" }

// for many authors at once, one query, grouped by the author id, authors without articles are not included:
$grouped = $relation->findRelatedGroupedByOwner(ownerIds: [1, 2, 3]);

var_dump(array_map('count', $grouped));
// array(2) { [1]=> int(2) [2]=> int(1) }
```

#### Writing

The relation does not write. The foreign key is a column of the article, set like any other column when creating or updating the article:

```php
$articleRepository->create(['title' => 'First', 'status' => 'published', 'author_id' => 3]);

var_dump(count($relation->findRelatedForOwner(ownerId: 3)));
// int(1)
```

#### Deleting

Deleting does not change the other side. Deleting an article removes it from the articles of its author. Deleting an author keeps its articles with its id:

```php
$authorRepository->deleteById(3);

var_dump($articleRepository->findById(5)['author_id']);
// int(3)
```

To delete the articles with their author, or to remove the author from them, use the article repository:

```php
// delete them:
$articleRepository->delete(where: ['author_id' => 3]);

// or remove the author, the column being nullable:
$articleRepository->update(where: ['author_id' => 3], attributes: ['author_id' => null]);
```

### Many To Many

For instance, an article having many tags, linked by a pivot table (```resource_relations```).

```php
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Storage\StorageInterface;

$relation = new ManyToMany(
    name: 'tags',
    table: 'resource_relations',
    ownerType: 'articles', // null|string
    relatedType: 'tags', // null|string
    // the storage of the pivot table, usually null: the repository having the relation passes its own storage:
    storage: null, // null|StorageInterface
    // the related repository, only needed to find the entities:
    repository: fn () => $tagRepository, // null|ReadRepositoryInterface|Closure
    // optional, e.g. for existing pivot tables:
    ownerTypeColumn: 'owner_type',
    ownerIdColumn: 'owner_id',
    relatedTypeColumn: 'related_type',
    relatedIdColumn: 'related_id',
    ownerKey: 'id',
    relatedKey: 'id',
    keyResolver: null, // null|Closure, e.g. fn (Tag $tag): int => $tag->id()
    chunkSize: 1000,
);
```

The pivot rows are read and written directly by the storage, they are no entities.

Without a ```storage``` parameter, the repository having the relation passes its own storage. With PDO storages the pivot table is in the same database for all repositories. With storages not sharing their data, e.g. ```JsonFileStorage``` in different directories or ```InMemoryStorage``` instances, pass the same pivot storage to all relations using the pivot table, e.g. the ```ManyToMany``` and its ```ManyToManyInverse```:

```php
use Tobento\Service\Storage\JsonFileStorage;

$pivotStorage = new JsonFileStorage(dir: 'storage/relations/');

// on the article repository:
new ManyToMany(name: 'tags', table: 'resource_relations', ownerType: 'articles', relatedType: 'tags', storage: $pivotStorage);

// on the tag repository:
new ManyToManyInverse(name: 'articles', table: 'resource_relations', ownerType: 'articles', relatedType: 'tags', storage: $pivotStorage);
```

The types filter every query, so several relations share one pivot table, e.g. tags and categories. They must stay stable as they are stored in the table. Use ```null``` for pivot tables without type column.

#### Example

```php
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;

$storage = new InMemoryStorage(items: [
    'tags' => [
        1 => ['id' => 1, 'name' => 'php'],
        2 => ['id' => 2, 'name' => 'cms'],
        3 => ['id' => 3, 'name' => 'js'],
    ],
    'categories' => [
        1 => ['id' => 1, 'name' => 'News'],
    ],
    'articles' => [
        1 => ['id' => 1, 'title' => 'Hello'],
        2 => ['id' => 2, 'title' => 'World'],
        3 => ['id' => 3, 'title' => 'Again'],
    ],
]);

class TagRepository extends StorageRepository {}
class CategoryRepository extends StorageRepository {}
class ArticleRepository extends RelationStorageRepository {}

$tagRepository = new TagRepository(
    storage: $storage,
    table: 'tags',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
);

$categoryRepository = new CategoryRepository(
    storage: $storage,
    table: 'categories',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
);

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [new Column\Id(), new Column\Text(name: 'title')],
    entityFactory: new RelationEntityFactory(),
    relations: [
        // sharing the same pivot table, separated by the types:
        new ManyToMany(name: 'tags', table: 'resource_relations', ownerType: 'articles', relatedType: 'tags', repository: $tagRepository),
        new ManyToMany(name: 'categories', table: 'resource_relations', ownerType: 'articles', relatedType: 'categories', repository: $categoryRepository),
        // without repository, resolves the tag ids only:
        new ManyToMany(name: 'tagIds', table: 'resource_relations', ownerType: 'articles', relatedType: 'tags'),
    ],
);
```

#### Writing

The relation writes the links, as the pivot rows are no columns of the article. ```updateRelatedForOwner()``` sets the tags of an article, only the removed and new pivot rows are written:

```php
$tags = $articleRepository->relations()->getManyToMany('tags');

$tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
$tags->updateRelatedForOwner(ownerId: 2, relatedIds: [2, 3]);

$articleRepository->relations()->getManyToMany('categories')->updateRelatedForOwner(ownerId: 1, relatedIds: [1]);
```

#### Reading

**Resolving**

The usual way, the articles with their tags. An article without tags gets ```[]```:

```php
$articles = $articleRepository->withResolvedRelations('tags', 'categories')->findAll();

foreach ($articles as $article) {
    $tagNames = array_map(fn (object $tag): string => $tag['name'], $article['tags']);
    var_dump($article['title'].': '.implode(', ', $tagNames).' / '.count($article['categories']).' categories');
}
// string(30) "Hello: php, cms / 1 categories"
// string(29) "World: cms, js / 0 categories"
// string(22) "Again:  / 0 categories"

// without repository, the ids:
var_dump($articleRepository->withResolvedRelations('tagIds')->findById(2)['tagIds']);
// array(2) { [0]=> int(2) [1]=> int(3) }
```

**Loading later**

For articles found without resolving. The relation loads the tags only, without finding the articles again:

```php
$tagNames = array_map(fn (object $tag): string => $tag['name'], $tags->findRelatedForOwner(ownerId: 1));

var_dump($tagNames);
// array(2) { [0]=> string(3) "php" [1]=> string(3) "cms" }

// for many articles at once, grouped by the article id:
var_dump(array_map('count', $tags->findRelatedGroupedByOwner(ownerIds: [1, 2, 3])));
// array(2) { [1]=> int(2) [2]=> int(2) }
```

**Ids**

Only the pivot table is queried, e.g. for a form or to filter:

```php
var_dump($tags->findRelatedIdsForOwner(ownerId: 1));
// array(2) { [0]=> int(1) [1]=> int(2) }

var_dump($tags->findRelatedIdsGroupedByOwner(ownerIds: [1, 2]));
// array(2) { [1]=> array(2) { [0]=> int(1) [1]=> int(2) } [2]=> array(2) { [0]=> int(2) [1]=> int(3) } }

// the articles having the tag 2, or one of the tags 1 and 3:
var_dump($tags->findOwnerIdsForRelated(relatedId: 2));
// array(2) { [0]=> int(1) [1]=> int(2) }

var_dump($tags->findOwnerIdsForRelatedIds(relatedIds: [1, 3]));
// array(2) { [0]=> int(1) [1]=> int(2) }
```

#### Deleting

Deleting an article deletes its pivot rows too, of all its relations. The tags themselves are not deleted:

```php
$articleRepository->deleteById(1);

var_dump($tags->findRelatedIdsForOwner(ownerId: 1));
// array(0) { }
```

When a tag gets deleted, delete its pivot rows by the relation, or use the [Many To Many Inverse](#many-to-many-inverse) on the tag repository, which does it on delete:

```php
$tagRepository->deleteById(3);
$tags->deleteForRelated(relatedId: 3);

var_dump($tags->findRelatedIdsForOwner(ownerId: 2));
// array(1) { [0]=> int(2) }
```

The other methods: ```deleteForOwner()```, ```deleteForOwners()```, ```deleteForRelatedIds()```.

### Many To Many Inverse

The many to many relation read from the related side of the pivot rows, e.g. a tag having many articles, while the pivot rows are written from the articles side. Pass the same parameters as of the ```ManyToMany``` on the other side, owner and related are swapped internally.

```php
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\ManyToManyInverse;
use Tobento\Service\Storage\StorageInterface;

$relation = new ManyToManyInverse(
    name: 'articles',
    table: 'resource_relations',
    // the same as of the ManyToMany on the other side:
    ownerType: 'articles', // null|string
    relatedType: 'tags', // null|string
    // the storage of the pivot table, usually null: the repository having the relation passes its own storage:
    storage: null, // null|StorageInterface
    // the repository of the other side, only needed to find the entities:
    repository: fn () => $articleRepository, // null|ReadRepositoryInterface|Closure
    // optional, the same as of the ManyToMany on the other side:
    ownerTypeColumn: 'owner_type',
    ownerIdColumn: 'owner_id',
    relatedTypeColumn: 'related_type',
    relatedIdColumn: 'related_id',
    // the key of the tags:
    ownerKey: 'id',
    // the key of the articles:
    relatedKey: 'id',
    keyResolver: null, // null|Closure, e.g. fn (Article $article): int => $article->id()
    chunkSize: 1000,
);
```

All methods of the [Many To Many](#many-to-many) work from this side, e.g. ```findRelatedIdsForOwner(ownerId: 1)``` returns the article ids of the tag 1.

Both sides must use the same pivot storage. With PDO storages this is the case without the ```storage``` parameter, otherwise pass the same storage to both, see [Many To Many](#many-to-many).

#### Example

```php
use Tobento\Service\Repository\Relation\ManyToMany;
use Tobento\Service\Repository\Relation\ManyToManyInverse;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Storage\InMemoryStorage;

$storage = new InMemoryStorage(items: [
    'tags' => [
        1 => ['id' => 1, 'name' => 'php'],
        2 => ['id' => 2, 'name' => 'cms'],
    ],
    'articles' => [
        1 => ['id' => 1, 'title' => 'Hello'],
        2 => ['id' => 2, 'title' => 'World'],
    ],
]);

class ArticleRepository extends RelationStorageRepository {}
class TagRepository extends RelationStorageRepository {}

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [new Column\Id(), new Column\Text(name: 'title')],
    entityFactory: new RelationEntityFactory(),
    relations: [
        // without repository, only writing the links here:
        new ManyToMany(
            name: 'tags',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
        ),
    ],
);

$tagRepository = new TagRepository(
    storage: $storage,
    table: 'tags',
    columns: [new Column\Id(), new Column\Text(name: 'name')],
    entityFactory: new RelationEntityFactory(),
    relations: [
        new ManyToManyInverse(
            name: 'articles',
            table: 'resource_relations',
            ownerType: 'articles',
            relatedType: 'tags',
            repository: $articleRepository,
        ),
    ],
);

// the links, written from the articles side:
$tags = $articleRepository->relations()->getManyToMany('tags');
$tags->updateRelatedForOwner(ownerId: 1, relatedIds: [1, 2]);
$tags->updateRelatedForOwner(ownerId: 2, relatedIds: [1]);
```

#### Reading

**Resolving**

The usual way, the tags with their articles:

```php
$tagsWithArticles = $tagRepository->withResolvedRelations('articles')->findAll();

foreach ($tagsWithArticles as $tag) {
    $titles = array_map(fn (object $article): string => $article['title'], $tag['articles']);
    var_dump($tag['name'].': '.implode(', ', $titles));
}
// string(17) "php: Hello, World"
// string(10) "cms: Hello"
```

**Loading later**

For tags found without resolving, the same methods as of the [Many To Many](#many-to-many):

```php
$articles = $tagRepository->relations()->getManyToMany('articles');

var_dump($articles->findRelatedIdsForOwner(ownerId: 1));
// array(2) { [0]=> int(1) [1]=> int(2) }

var_dump(array_map(fn (object $article): string => $article['title'], $articles->findRelatedForOwner(ownerId: 2)));
// array(1) { [0]=> string(5) "Hello" }
```

#### Writing

The links may be written from both sides, e.g. setting the articles of a tag:

```php
$articles = $tagRepository->relations()->getManyToMany('articles');

$articles->updateRelatedForOwner(ownerId: 2, relatedIds: [1, 2]);

var_dump($tags->findRelatedIdsForOwner(ownerId: 2));
// array(2) { [0]=> int(1) [1]=> int(2) }
```

#### Deleting

Deleting a tag deletes its pivot rows too, so the articles do not link to it anymore:

```php
$tagRepository->deleteById(1);

// the article 2 had the tags 1 and 2:
var_dump($tags->findRelatedIdsForOwner(ownerId: 2));
// array(1) { [0]=> int(2) }
```

### Many To One By Type

A many to one relation whose related repository is picked by a type column, e.g. a comment belonging to an article or a page (```comments.commentable_type```, ```comments.commentable_id```). Also known as polymorphic relation.

```php
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\ManyToOneByType;

$relation = new ManyToOneByType(
    name: 'commentable',
    typeColumn: 'commentable_type',
    foreignKey: 'commentable_id',
    // array<string, ReadRepositoryInterface|Closure>, keyed by type:
    repositories: [
        'articles' => fn () => $articleRepository,
        'pages' => fn () => $pageRepository,
    ],
    // optional:
    relatedKey: 'id',
    keyResolver: null, // null|Closure, e.g. fn (Article $article): int => $article->id()
    typeResolver: null, // null|Closure, e.g. fn (Comment $comment): string => $comment->commentableType()
    foreignKeyResolver: null, // null|Closure, e.g. fn (Comment $comment): int => $comment->commentableId()
    chunkSize: 1000,
);
```

The keys of the ```repositories``` are the types stored in the type column, e.g. ```'articles'``` in ```comments.commentable_type```, picking the repository to find the related entity. They must be non-numeric strings and stay stable: renaming a type means the stored comments of that type no longer find their entity.

The other side, the comments of an article, is a [One To Many](#one-to-many) on the article repository with the type as ```where```, e.g. ```where: ['commentable_type' => 'articles']```.

#### Example

```php
use Tobento\Service\Repository\Relation\ManyToOneByType;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Repository\Storage\StorageRepository;
use Tobento\Service\Storage\InMemoryStorage;

$storage = new InMemoryStorage(items: [
    'articles' => [1 => ['id' => 1, 'title' => 'Article 1']],
    'pages' => [1 => ['id' => 1, 'title' => 'Page 1']],
    'comments' => [
        1 => ['id' => 1, 'text' => 'Nice', 'commentable_type' => 'articles', 'commentable_id' => 1],
        2 => ['id' => 2, 'text' => 'Thanks', 'commentable_type' => 'pages', 'commentable_id' => 1],
        3 => ['id' => 3, 'text' => 'Video', 'commentable_type' => 'videos', 'commentable_id' => 1],
    ],
]);

class ArticleRepository extends StorageRepository {}
class PageRepository extends StorageRepository {}
class CommentRepository extends RelationStorageRepository {}

$articleRepository = new ArticleRepository(
    storage: $storage,
    table: 'articles',
    columns: [new Column\Id(), new Column\Text(name: 'title')],
);

$pageRepository = new PageRepository(
    storage: $storage,
    table: 'pages',
    columns: [new Column\Id(), new Column\Text(name: 'title')],
);

$commentRepository = new CommentRepository(
    storage: $storage,
    table: 'comments',
    columns: [
        new Column\Id(),
        new Column\Text(name: 'text'),
        new Column\Text(name: 'commentable_type'),
        new Column\Integer(name: 'commentable_id'),
    ],
    entityFactory: new RelationEntityFactory(),
    relations: [
        new ManyToOneByType(
            name: 'commentable',
            typeColumn: 'commentable_type',
            foreignKey: 'commentable_id',
            repositories: [
                'articles' => $articleRepository,
                'pages' => $pageRepository,
            ],
        ),
    ],
);
```

#### Reading

**Resolving**

The usual way, the comments with what they belong to, one query per type. An unknown type (e.g. removed from the repositories) or a missing entity is ```null```:

```php
$comments = $commentRepository->withResolvedRelations('commentable')->findAll();

foreach ($comments as $comment) {
    var_dump($comment['text'].': '.($comment['commentable']['title'] ?? 'none'));
}
// string(15) "Nice: Article 1"
// string(14) "Thanks: Page 1"
// string(11) "Video: none"
```

The other side, the comments of an article, is a [One To Many](#one-to-many) on the article repository with the type as ```where```, e.g. getting the comment repository from the container:

```php
use Tobento\Service\Repository\Relation\OneToMany;

new OneToMany(
    name: 'comments',
    foreignKey: 'commentable_id',
    repository: fn () => $container->get(CommentRepositoryInterface::class),
    where: ['commentable_type' => 'articles'],
);
```

**Loading later**

For comments found without resolving:

```php
$relation = $commentRepository->relations()->getManyToOneByType('commentable');

var_dump($relation->findRelatedForItem($commentRepository->findById(2))['title']);
// string(6) "Page 1"

// for many comments at once, grouped by type and keyed by id:
$related = $relation->findRelatedForItems($commentRepository->findAll());

var_dump(array_map('array_keys', $related));
// array(2) { ["articles"]=> array(1) { [0]=> int(1) } ["pages"]=> array(1) { [0]=> int(1) } }

// only having ids of a type:
var_dump(array_keys($relation->findByIds(type: 'articles', ids: [1, 99])));
// array(1) { [0]=> int(1) }

var_dump($relation->types());
// array(2) { [0]=> string(8) "articles" [1]=> string(5) "pages" }
```

**Own objects**

For items not coming from the repository, using a type and a foreign key resolver:

```php
class Comment
{
    public function __construct(
        private string $commentableType,
        private int $commentableId,
    ) {}

    public function commentableType(): string
    {
        return $this->commentableType;
    }

    public function commentableId(): int
    {
        return $this->commentableId;
    }
}

$relation = new ManyToOneByType(
    name: 'commentable',
    typeColumn: 'commentable_type',
    foreignKey: 'commentable_id',
    repositories: ['pages' => $pageRepository],
    typeResolver: fn (Comment $comment): string => $comment->commentableType(),
    foreignKeyResolver: fn (Comment $comment): int => $comment->commentableId(),
);

var_dump($relation->findRelatedForItem(new Comment(commentableType: 'pages', commentableId: 1))['title']);
// string(6) "Page 1"
```

#### Writing

The relation does not write. The type and the foreign key are columns of the comment, set like any other column when creating or updating the comment:

```php
$commentRepository->create(['text' => 'Great', 'commentable_type' => 'articles', 'commentable_id' => 1]);

var_dump($commentRepository->count(where: ['commentable_type' => 'articles', 'commentable_id' => 1]));
// int(2)
```

#### Deleting

Deleting does not change the other side. Deleting a comment keeps its article. Deleting an article keeps its comments, resolving ```null```:

```php
$articleRepository->deleteById(1);

var_dump($commentRepository->withResolvedRelations('commentable')->findById(1)['commentable']);
// NULL
```

To delete the comments with their article, use the comment repository:

```php
$commentRepository->delete(where: ['commentable_type' => 'articles', 'commentable_id' => 1]);

var_dump($commentRepository->count());
// int(2)
```

#### Types From Configuration

In an application the types are usually not fixed, e.g. other modules add their commentable types. Build the repositories from configuration instead, so adding a type needs no change of the comment repository:

```php
use Psr\Container\ContainerInterface;
use Tobento\Service\Repository\Relation\ManyToOneByType;
use Tobento\Service\Repository\Relation\RelationEntityFactory;
use Tobento\Service\Repository\Relation\RelationsInterface;
use Tobento\Service\Repository\Relation\RelationStorageRepository;
use Tobento\Service\Repository\Storage\Column;
use Tobento\Service\Storage\StorageInterface;

class CommentRepository extends RelationStorageRepository implements CommentRepositoryInterface
{
    /**
     * @param array<string, class-string> $commentables The types with their repository interface,
     *        e.g. ['articles' => ArticleRepositoryInterface::class, 'pages' => PageRepositoryInterface::class]
     */
    public function __construct(
        StorageInterface $storage,
        protected ContainerInterface $container,
        protected array $commentables,
    ) {
        parent::__construct(
            storage: $storage,
            table: 'comments',
            columns: [
                new Column\Id(),
                new Column\Text(name: 'text'),
                new Column\Text(name: 'commentable_type'),
                new Column\Integer(name: 'commentable_id'),
            ],
            entityFactory: new RelationEntityFactory(),
        );
    }

    protected function configureRelations(): iterable|RelationsInterface
    {
        $repositories = [];

        foreach ($this->commentables as $type => $repositoryInterface) {
            // resolved on first use, so the repositories may be created later:
            $repositories[$type] = fn () => $this->container->get($repositoryInterface);
        }

        return [
            new ManyToOneByType(
                name: 'commentable',
                typeColumn: 'commentable_type',
                foreignKey: 'commentable_id',
                repositories: $repositories,
            ),
        ];
    }
}

$commentRepository = new CommentRepository(
    storage: $storage,
    container: $container,
    // e.g. from the config or registered by other modules:
    commentables: [
        'articles' => ArticleRepositoryInterface::class,
        'pages' => PageRepositoryInterface::class,
    ],
);
```

A type removed from the configuration does not break anything, its comments resolve ```null```.

### Create Custom Relation

Implement the ```RelationInterface``` for a relation only accessible by the relations, and the ```ResolvableRelationInterface``` for a relation being resolvable:

```php
use Tobento\Service\Iterable\Iter;
use Tobento\Service\Repository\Relation\ResolvableRelationInterface;

class InitialRelation implements ResolvableRelationInterface
{
    public function __construct(
        protected string $name,
        protected string $column,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function resolveForItems(iterable $items): array
    {
        $items = Iter::toArray($items);

        foreach($items as $key => $item) {
            $value = $item[$this->column] ?? null;
            $item[$this->name] = is_string($value) ? strtoupper(substr($value, 0, 1)) : null;
            $items[$key] = $item;
        }

        return $items;
    }
}

// added to the relations of the article repository, e.g. new InitialRelation(name: 'initial', column: 'title'):
$articles = $articleRepository->withResolvedRelations('initial')->findAll();
```

Load the related data for all items at once in ```resolveForItems()```, not per item, to avoid N+1 queries. Implement the ```StorageAwareRelationInterface``` to get the repository storage, and the ```DeletesWithOwnerInterface``` to delete data with the owner.

## Relational Repository

The ```RelationalRepository::class``` finds the entities of a repository by conditions on their related entities, e.g. the published articles having the tag "php", in the category "news" and with an author from country 5:

```php
use Tobento\Service\Repository\Relation\RelationalRepository;

$articles = new RelationalRepository(repository: $articleRepository);

$found = $articles->findAll(
    where: ['status' => 'published'],
    relatedWhere: [
        'tags' => ['slug' => 'php'],
        'categories' => ['slug' => 'news'],
        'author' => ['country_id' => 5],
    ],
    orderBy: ['date_created' => 'desc'],
    limit: 20,
);

$article = $articles->findOne(where: [...], relatedWhere: [...]);
$count = $articles->count(where: [...], relatedWhere: [...]); // e.g. for pagination

// the where parameters for other repository methods, null if nothing can match:
$where = $articles->buildWhere(where: [...], relatedWhere: [...]);
```

- The ```relatedWhere``` is keyed by the relation names of the repository, all conditions must match.
- Per relation, the related ids are found by the related repository, so its where handling applies (e.g. translated columns). Then the entities are found by one query of the repository, so sorting, limit and count apply to the whole result.
- A pivot table in the same storage as the repository is queried by a subquery. Otherwise, e.g. separate JSON storages or another database, the owner ids are passed as list.
- Supported relations: ```ManyToMany```, ```ManyToManyInverse```, ```ManyToOne``` and ```OneToMany```.
- Like unknown columns of the storage, unknown relation names, values not being an array and not supported relations are ignored, so the related where may come from user input, e.g. a list filter.
- Filtering and resolving are independent: ```new RelationalRepository(repository: $articleRepository->withResolvedRelations('tags'))``` loads all tags of the found articles.

## Migration

The pivot table of the [Many To Many](#many-to-many) relations, using the [Database Service](https://github.com/tobento-ch/service-database):

```php
use Tobento\Service\Database\Schema\Table;

$table = new Table(name: 'resource_relations');
$table->bigPrimary('id');
// short type columns keep the index keys small:
$table->string('owner_type', 50)->nullable(false)->default('');
$table->bigInt('owner_id')->nullable(false)->default(0);
$table->string('related_type', 50)->nullable(false)->default('');
$table->bigInt('related_id')->nullable(false)->default(0);
// covering indexes, containing all columns the queries read:
$table->index('owner_index')->column('owner_type', 'related_type', 'owner_id', 'related_id')->unique();
$table->index('related_index')->column('owner_type', 'related_type', 'related_id', 'owner_id');
```

Without the covering indexes, each found row needs a lookup: finding the articles of 100 tags took 9 s instead of 0.09 s for 500,000 pivot rows.

## Exceptions

Only misconfiguration and failures throw, missing data never does (```null``` or ```[]```, missing entities are skipped).

| Exception | When |
| --- | --- |
| ```RelationNotFoundException``` | unknown relation name or wrong type, e.g. ```get()```, ```withResolvedRelations('autor')``` |
| ```RelationException``` | reading, writing or resolving a relation failed, e.g. a database error or a misconfigured relation. The cause is the previous exception. |
| ```InvalidArgumentException``` | invalid constructor arguments, e.g. the types of the ```ManyToOneByType``` |
| ```LogicException``` | misconfiguration, e.g. ```withResolvedRelations()``` without a ```RelationEntityFactory```. Inside relations it arrives as the previous exception of a ```RelationException```. |

```RelationException``` extends the ```RepositoryException``` of the [Repository Service](https://github.com/tobento-ch/service-repository). A failing relation cleanup on ```deleteById()``` and ```delete()``` throws a ```RepositoryDeleteException```. All exceptions implement the ```RelationExceptionInterface```:

```php
use Tobento\Service\Repository\Relation\Exception\RelationExceptionInterface;

try {
    $articles = $articleRepository->withResolvedRelations('author')->findAll();
} catch (RelationExceptionInterface $e) {
    // e.g. "Relation [author] failed: ..."
    $cause = $e->getPrevious();
}
```

## Comparison

| Laravel | Cycle ORM | Doctrine ORM | Repository Relation |
| --- | --- | --- | --- |
| ```belongsTo``` | ```BelongsTo``` | ```ManyToOne``` | [Many To One](#many-to-one) |
| ```hasMany``` | ```HasMany``` | ```OneToMany``` | [One To Many](#one-to-many) |
| ```hasOne``` | ```HasOne``` | ```OneToOne``` | [Many To One](#many-to-one) from the other side, or [One To Many](#one-to-many) taking the first |
| ```belongsToMany```, ```morphToMany``` | ```ManyToMany``` | ```ManyToMany``` | [Many To Many](#many-to-many) with types |
| ```morphedByMany``` | | ```ManyToMany``` with ```mappedBy``` | [Many To Many Inverse](#many-to-many-inverse) |
| ```morphTo``` | ```BelongsToMorphed``` | | [Many To One By Type](#many-to-one-by-type) |
| ```morphMany```, ```morphOne``` | ```MorphedHasMany```, ```MorphedHasOne``` | | [One To Many](#one-to-many) with the type as ```where``` |
| ```whereHas```, ```hasManyThrough``` | | DQL with ```JOIN``` | [Relational Repository](#relational-repository) |
| ```with('author')``` | ```load('author')``` | ```fetch: 'EAGER'``` or a fetch join | [Resolving Relations](#resolving-relations), ```withResolvedRelations('author')``` |

Not supported on purpose: lazy loading (avoids N+1), saving related entities through a relation (each repository writes its own entities), and joins (separate queries using indexes, working across storages).

# Credits

- [Tobias Strub](https://www.tobento.ch)
- [All Contributors](../../contributors)