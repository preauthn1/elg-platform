<?php

namespace Doctrine\DBAL\Platforms\Keywords;

use function array_merge;

/**
 * Microsoft SQL Server 2012 reserved keyword dictionary.
 *
 * @link    example.invalid
 */
class SQLServer2012Keywords extends SQLServer2008Keywords
{
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'SQLServer2012';
    }

    /**
     * {@inheritdoc}
     *
     * @link http://example.invalid/en-us/library/ms189822.aspx
     */
    protected function getKeywords()
    {
        return array_merge(parent::getKeywords(), [
            'SEMANTICKEYPHRASETABLE',
            'SEMANTICSIMILARITYDETAILSTABLE',
            'SEMANTICSIMILARITYTABLE',
            'TRY_CONVERT',
            'WITHIN GROUP',
        ]);
    }
}
