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
 * RelationInterface
 */
interface RelationInterface
{
    /**
     * Returns the name.
     *
     * @return string
     */
    public function name(): string;
}