<?php

declare(strict_types=1);

/*
 * This is part of the league/commonmark package.
 *
 * (c) Martin Hasoň <user@example.invalid>
 * (c) Webuni s.r.o. <user@example.invalid>
 * (c) Colin O'Dell <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\CommonMark\Extension\Table;

use League\CommonMark\Block\Element\AbstractBlock;
use League\CommonMark\Cursor;
use League\CommonMark\Node\Node;

final class TableRow extends AbstractBlock
{
    public function canContain(AbstractBlock $block): bool
    {
        return $block instanceof TableCell;
    }

    public function isCode(): bool
    {
        return false;
    }

    public function matchesNextLine(Cursor $cursor): bool
    {
        return false;
    }

    /**
     * @return AbstractBlock[]
     */
    public function children(): iterable
    {
        return array_filter((array) parent::children(), static function (Node $child): bool {
            return $child instanceof AbstractBlock;
        });
    }
}
