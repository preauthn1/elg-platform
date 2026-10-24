<?php

/*
 * This file is part of the Prophecy.
 * (c) Konstantin Kudryashov <user@example.invalid>
 *     Marcello Duarte <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prophecy\Argument\Token;

/**
 * Approximate value token
 *
 * @author Daniel Leech <user@example.invalid>
 */
class ApproximateValueToken implements TokenInterface
{
    private $value;
    private $precision;

    public function __construct($value, $precision = 0)
    {
        $this->value = $value;
        $this->precision = $precision;
    }

    /**
     * {@inheritdoc}
     */
    public function scoreArgument($argument)
    {
        return round($argument, $this->precision) === round($this->value, $this->precision) ? 10 : false;
    }

    /**
     * {@inheritdoc}
     */
    public function isLast()
    {
        return false;
    }

    /**
     * Returns string representation for token.
     *
     * @return string
     */
    public function __toString()
    {
        return sprintf('≅%s', round($this->value, $this->precision));
    }
}
