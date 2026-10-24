<?php

/**
 * This file is part of the Carbon package.
 *
 * (c) Brian Nesbitt <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Authors:
 * - RAP    user@example.invalid
 */
return array_replace_recursive(require __DIR__.'/de.php', [
    'formats' => [
        'L' => 'YYYY-MM-DD',
    ],
]);
