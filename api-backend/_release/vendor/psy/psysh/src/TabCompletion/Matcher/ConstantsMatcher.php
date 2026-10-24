<?php

/*
 * This file is part of Psy Shell.
 *
 * (c) 2012-2018 Justin Hileman
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Psy\TabCompletion\Matcher;

/**
 * A constant name tab completion Matcher.
 *
 * This matcher provides completion for all defined constants.
 *
 * @author Marc Garcia <user@example.invalid>
 */
class ConstantsMatcher extends AbstractMatcher
{
    /**
     * {@inheritdoc}
     */
    public function getMatches(array $tokens, array $info = [])
    {
        $const = $this->getInput($tokens);

        return \array_filter(\array_keys(\get_defined_constants()), function ($constant) use ($const) {
            return AbstractMatcher::startsWith($const, $constant);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function hasMatched(array $tokens)
    {
        $token     = [REDACTED];
        $prevToken = [REDACTED];

        switch (true) {
            case self::tokenIs($prevToken, self::T_NEW):
            case self::tokenIs($prevToken, self::T_NS_SEPARATOR):
                return false;
            case self::hasToken([self::T_OPEN_TAG, self::T_STRING], $token):
            case self::isOperator($token):
                return true;
        }

        return false;
    }
}
