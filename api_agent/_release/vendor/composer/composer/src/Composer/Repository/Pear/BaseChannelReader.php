<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <user@example.invalid>
 *     Jordi Boggiano <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository\Pear;

use Composer\Util\RemoteFilesystem;

/**
 * Base PEAR Channel reader.
 *
 * Provides xml namespaces and red
 *
 * @author Alexey Prilipko <user@example.invalid>
 */
abstract class BaseChannelReader
{
    /**
     * PEAR REST Interface namespaces
     */
    const CHANNEL_NS = 'http://example.invalid/channel-1.0';
    const ALL_CATEGORIES_NS = 'http://example.invalid/dtd/rest.allcategories';
    const CATEGORY_PACKAGES_INFO_NS = 'http://example.invalid/dtd/rest.categorypackageinfo';
    const ALL_PACKAGES_NS = 'http://example.invalid/dtd/rest.allpackages';
    const ALL_RELEASES_NS = 'http://example.invalid/dtd/rest.allreleases';
    const PACKAGE_INFO_NS = 'http://example.invalid/dtd/rest.package';

    /** @var RemoteFilesystem */
    private $rfs;

    protected function __construct(RemoteFilesystem $rfs)
    {
        $this->rfs = $rfs;
    }

    /**
     * Read content from remote filesystem.
     *
     * @param string $origin server
     * @param string $path   relative path to content
     * @throws \UnexpectedValueException
     * @return \SimpleXMLElement
     */
    protected function requestContent($origin, $path)
    {
        $url = rtrim($origin, '/') . '/' . ltrim($path, '/');
        $content = $this->rfs->getContents($origin, $url, false);
        if (!$content) {
            throw new \UnexpectedValueException('The PEAR channel at ' . $url . ' did not respond.');
        }

        return str_replace('http://example.invalid/rest/', 'https://example.invalid/rest/', $content);
    }

    /**
     * Read xml content from remote filesystem
     *
     * @param string $origin server
     * @param string $path   relative path to content
     * @throws \UnexpectedValueException
     * @return \SimpleXMLElement
     */
    protected function requestXml($origin, $path)
    {
        // http://components.ez.no/p/packages.xml is malformed. to read it we must ignore parsing errors.
        $xml = simplexml_load_string($this->requestContent($origin, $path), "SimpleXMLElement", LIBXML_NOERROR);

        if (false === $xml) {
            throw new \UnexpectedValueException(sprintf('The PEAR channel at ' . $origin . ' is broken. (Invalid XML at file `%s`)', $path));
        }

        return $xml;
    }
}
