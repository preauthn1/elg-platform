<?php

namespace Doctrine\Common\Cache;

/**
 * Interface for cache drivers that supports multiple items manipulation.
 *
 * @link   example.invalid
 */
interface MultiOperationCache extends MultiGetCache, MultiDeleteCache, MultiPutCache
{
}
