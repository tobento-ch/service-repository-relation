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

use LogicException as BaseLogicException;
use PHPUnit\Framework\TestCase;
use Tobento\Service\Repository\Relation\Exception\LogicException;
use Tobento\Service\Repository\Relation\Exception\RelationExceptionInterface;

class LogicExceptionTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $e = new LogicException(message: 'Message');

        $this->assertInstanceOf(RelationExceptionInterface::class, $e);
        $this->assertInstanceOf(BaseLogicException::class, $e);
    }

    public function testGetMessageMethod(): void
    {
        $e = new LogicException(message: 'Message');

        $this->assertSame('Message', $e->getMessage());
    }
}