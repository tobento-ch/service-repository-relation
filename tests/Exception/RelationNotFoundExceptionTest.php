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
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\RelationExceptionInterface;
use Tobento\Service\Repository\Relation\Exception\RelationNotFoundException;

class RelationNotFoundExceptionTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $e = new RelationNotFoundException(message: 'Message');

        $this->assertInstanceOf(RelationExceptionInterface::class, $e);
        $this->assertInstanceOf(InvalidArgumentException::class, $e);
    }

    public function testGetMessageMethod(): void
    {
        $e = new RelationNotFoundException(message: 'Message');

        $this->assertSame('Message', $e->getMessage());
    }
}