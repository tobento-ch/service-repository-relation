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

namespace Tobento\Service\Repository\Relation\Test\Exception;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tobento\Service\Repository\Relation\Exception\RelationException;
use Tobento\Service\Repository\Relation\Exception\RelationExceptionInterface;
use Tobento\Service\Repository\RepositoryException;

class RelationExceptionTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $e = new RelationException(message: 'Message');

        $this->assertInstanceOf(RelationExceptionInterface::class, $e);
        $this->assertInstanceOf(RepositoryException::class, $e);
    }

    public function testGetMessageMethod(): void
    {
        $e = new RelationException(message: 'Message');

        $this->assertSame('Message', $e->getMessage());
    }

    public function testWrapMethodWrapsException(): void
    {
        $previous = new RuntimeException('Database gone');

        $e = RelationException::wrap('tags', $previous);

        $this->assertSame('Relation [tags] failed: Database gone', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testWrapMethodReturnsRelationExceptionUnchanged(): void
    {
        $exception = new RelationException(message: 'Message');

        $this->assertSame($exception, RelationException::wrap('tags', $exception));
    }
}