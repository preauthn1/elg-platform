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

namespace PhpCsFixer\FixerConfiguration;

interface FixerConfigurationResolverInterface
{
    /**
     * @return FixerOptionInterface[]
     */
    public function getOptions();

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    public function resolve(array $configuration);
}
