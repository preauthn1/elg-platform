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

class BundledComponentCollectionIterator implements \Iterator {
    /**
     * @var BundledComponent[]
     */
    private $bundledComponents = [];

    /**
     * @var int
     */
    private $position;

    public function __construct(BundledComponentCollection $bundledComponents) {
        $this->bundledComponents = $bundledComponents->getBundledComponents();
    }

    public function rewind() {
        $this->position = 0;
    }

    /**
     * @return bool
     */
    public function valid() {
        return $this->position < count($this->bundledComponents);
    }

    /**
     * @return int
     */
    public function key() {
        return $this->position;
    }

    /**
     * @return BundledComponent
     */
    public function current() {
        return $this->bundledComponents[$this->position];
    }

    public function next() {
        $this->position++;
    }
}
