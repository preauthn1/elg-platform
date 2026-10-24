<?php
/*
 * This file is part of PharIo\Manifest.
 *
 * (c) Arne Blankerts <user@example.invalid>, Sebastian Heuer <user@example.invalid>, Sebastian Bergmann <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PharIo\Manifest;

class ManifestLoader {
    /**
     * @param string $filename
     *
     * @return Manifest
     *
     * @throws ManifestLoaderException
     */
    public static function fromFile($filename) {
        try {
            return (new ManifestDocumentMapper())->map(
                ManifestDocument::fromFile($filename)
            );
        } catch (Exception $e) {
            throw new ManifestLoaderException(
                sprintf('Loading %s failed.', $filename),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @param string $filename
     *
     * @return Manifest
     *
     * @throws ManifestLoaderException
     */
    public static function fromPhar($filename) {
        return self::fromFile('phar://' . $filename . '/manifest.xml');
    }

    /**
     * @param string $manifest
     *
     * @return Manifest
     *
     * @throws ManifestLoaderException
     */
    public static function fromString($manifest) {
        try {
            return (new ManifestDocumentMapper())->map(
                ManifestDocument::fromString($manifest)
            );
        } catch (Exception $e) {
            throw new ManifestLoaderException(
                'Processing string failed',
                $e->getCode(),
                $e
            );
        }
    }
}
