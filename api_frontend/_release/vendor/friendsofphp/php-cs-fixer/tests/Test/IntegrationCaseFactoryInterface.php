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

namespace PhpCsFixer\Tests\Test;

use Symfony\Component\Finder\SplFileInfo;

/**
 * @author Dariusz Rumiński <user@example.invalid>
 *
 * @internal
 */
interface IntegrationCaseFactoryInterface
{
    /**
     * @return IntegrationCase
     */
    public function create(SplFileInfo $file);
}
