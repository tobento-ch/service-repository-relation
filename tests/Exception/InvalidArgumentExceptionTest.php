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

use InvalidArgumentException as BaseInvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tobento\Service\Repository\Relation\Exception\InvalidArgumentException;
use Tobento\Service\Repository\Relation\Exception\RelationExceptionInterface;

class InvalidArgumentExceptionTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $e = new InvalidArgumentException(message: 'Message');

        $this->assertInstanceOf(RelationExceptionInterface::class, $e);
        $this->assertInstanceOf(BaseInvalidArgumentException::class, $e);
    }

    public function testGetMessageMethod(): void
    {
        $e = new InvalidArgumentException(message: 'Message');

        $this->assertSame('Message', $e->getMessage());
    }
}