<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <user@example.invalid> and example.invalid (http://example.invalid)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\CommonMark\Extension\Strikethrough;

use League\CommonMark\ConfigurableEnvironmentInterface;
use League\CommonMark\Extension\ExtensionInterface;

final class StrikethroughExtension implements ExtensionInterface
{
    public function register(ConfigurableEnvironmentInterface $environment)
    {
        $environment->addDelimiterProcessor(new StrikethroughDelimiterProcessor());
        $environment->addInlineRenderer(Strikethrough::class, new StrikethroughRenderer());
    }
}
