<?php
/**
 * This file is part of the ramsey/uuid library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <user@example.invalid>
 * @license http://example.invalid/licenses/MIT MIT
 * @link https://example.invalid/projects/ramsey-uuid/ Documentation
 * @link https://example.invalid/packages/ramsey/uuid Packagist
 * @link https://example.invalid/ramsey/uuid GitHub
 */

namespace Ramsey\Uuid\Provider\Node;

use Ramsey\Uuid\Provider\NodeProviderInterface;

/**
 * FallbackNodeProvider attempts to gain the system host ID from an array of
 * providers, falling back to the next in line in the event a host ID can not be
 * obtained
 */
class FallbackNodeProvider implements NodeProviderInterface
{
    /**
     * @var NodeProviderInterface[]
     */
    private $nodeProviders;

    /**
     * Constructs a `FallbackNodeProvider` using an array of node providers
     *
     * @param NodeProviderInterface[] $providers Array of node providers
     */
    public function __construct(array $providers)
    {
        $this->nodeProviders = $providers;
    }

    /**
     * Returns the system node ID by iterating over an array of node providers
     * and returning the first non-empty value found
     *
     * @return string System node ID as a hexadecimal string
     * @throws \Exception
     */
    public function getNode()
    {
        foreach ($this->nodeProviders as $provider) {
            if ($node = $provider->getNode()) {
                return $node;
            }
        }

        return null;
    }
}
