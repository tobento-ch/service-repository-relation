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

namespace Tobento\Service\Repository\Relation\Exception;

use Exception;
use Tobento\Service\Repository\RepositoryException;

/**
 * RelationException: reading, writing or resolving a relation failed,
 * e.g. a database error or a misconfigured relation. The cause is the previous exception.
 */
class RelationException extends RepositoryException implements RelationExceptionInterface
{
    /**
     * Returns the given exception if it is a relation exception,
     * otherwise a new relation exception with the given exception as previous.
     *
     * @param string $relation The relation name(s), e.g. 'tags'.
     * @param Exception $exception The cause.
     * @return RelationException
     */
    public static function wrap(string $relation, Exception $exception): RelationException
    {
        if ($exception instanceof RelationException) {
            return $exception;
        }

        return new RelationException(
            message: sprintf('Relation [%s] failed: %s', $relation, $exception->getMessage()),
            previous: $exception,
        );
    }
}