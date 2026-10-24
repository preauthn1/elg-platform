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

namespace PhpCsFixer\Report;

/**
 * @author Boris Gorbylev <user@example.invalid>
 */
interface ReporterInterface
{
    /**
     * @return string
     */
    public function getFormat();

    /**
     * Process changed files array. Returns generated report.
     *
     * @return string
     */
    public function generate(ReportSummary $reportSummary);
}
