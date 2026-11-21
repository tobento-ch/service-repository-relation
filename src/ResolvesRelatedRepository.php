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

use Closure;
use Tobento\Service\Repository\ReadRepositoryInterface;
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\LogicException;

/**
 * ResolvesRelatedRepository: resolves the related repository, given directly or as a closure.
 *
 * The using class must provide the property:
 * - null|ReadRepositoryInterface|Closure $repository The related repository or a closure returning it.
 */
trait ResolvesRelatedRepository
{
    /**
     * Returns the name.
     *
     * @return string
     */
    abstract public function name(): string;

    /**
     * Returns the related repository or null if none, resolving a closure on first use.
     *
     * @return null|ReadRepositoryInterface
     */
    protected function resolveRepository(): null|ReadRepositoryInterface
    {
        if ($this->repository instanceof Closure) {
            $repository = ($this->repository)();

            if (! $repository instanceof ReadRepositoryInterface) {
                throw new InvalidArgumentException(sprintf(
                    'The repository closure of the relation [%s] must return a %s',
                    $this->name(),
                    ReadRepositoryInterface::class
                ));
            }

            $this->repository = $repository;
        }

        return $this->repository;
    }

    /**
     * Returns the related repository.
     *
     * @return ReadRepositoryInterface
     */
    protected function requireRepository(): ReadRepositoryInterface
    {
        return $this->resolveRepository() ?? throw new LogicException(sprintf(
            'The relation [%s] has no repository to load the related entities.',
            $this->name()
        ));
    }
}