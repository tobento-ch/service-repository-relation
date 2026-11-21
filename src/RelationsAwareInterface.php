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

namespace Tobento\Service\Repository\Relation;

/**
 * RelationsAwareInterface: for repositories and entity factories providing relations
 * and resolving the selected ones when creating the entities.
 * Nothing is resolved by default, the relations to resolve are named explicitly.
 */
interface RelationsAwareInterface
{
    /**
     * Returns the relations.
     *
     * @return RelationsInterface
     */
    public function relations(): RelationsInterface;

    /**
     * Returns a new instance resolving exactly the relations of the given names.
     * Without names, no relations are resolved.
     *
     * @param string ...$names
     * @return static
     */
    public function withResolvedRelations(string ...$names): static;

    /**
     * Returns a new instance resolving all relations.
     *
     * @return static
     */
    public function withAllResolvedRelations(): static;

    /**
     * Returns a new instance not resolving the relations of the given names anymore.
     * Without names, nothing changes.
     *
     * @param string ...$names
     * @return static
     */
    public function withoutResolvedRelations(string ...$names): static;

    /**
     * Returns the names of the relations to resolve.
     *
     * @return array<int, string> e.g. ['author', 'tags']
     */
    public function resolvedRelations(): array;
}