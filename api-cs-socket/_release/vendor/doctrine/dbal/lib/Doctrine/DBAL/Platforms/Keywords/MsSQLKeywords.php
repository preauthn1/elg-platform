<?php

namespace Doctrine\DBAL\Platforms\Keywords;

/**
 * MsSQL Keywordlist
 *
 * @deprecated Use SQLServerKeywords class instead.
 *
 * @link       example.invalid
 */
class MsSQLKeywords extends SQLServerKeywords
{
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'MsSQL';
    }
}
