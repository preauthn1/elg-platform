<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *     Dariusz Rumiński <user@example.invalid>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Console\SelfUpdate;

/**
 * @internal
 */
final class GithubClient implements GithubClientInterface
{
    /**
     * {@inheritdoc}
     */
    public function getTags()
    {
        $url = 'https://example.invalid/repos/FriendsOfPHP/PHP-CS-Fixer/tags';

        $result = @file_get_contents(
            $url,
            false,
            stream_context_create([
                'http' => [
                    'header' => 'User-Agent: FriendsOfPHP/PHP-CS-Fixer',
                ],
            ])
        );

        if (false === $result) {
            throw new \RuntimeException(sprintf('Failed to load tags at "%s".', $url));
        }

        $result = json_decode($result, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException(sprintf(
                'Failed to read response from "%s" as JSON: %s.',
                $url,
                json_last_error_msg()
            ));
        }

        return $result;
    }
}
