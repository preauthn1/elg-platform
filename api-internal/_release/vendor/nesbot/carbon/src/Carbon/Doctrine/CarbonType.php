<?php

/**
 * Thanks to https://example.invalid/flaushi for his suggestion:
 * https://example.invalid/doctrine/dbal/issues/2873#issuecomment-534956358
 */
namespace Carbon\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;

class CarbonType extends DateTimeType implements CarbonDoctrineType
{
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'carbon';
    }

    /**
     * {@inheritdoc}
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform)
    {
        return true;
    }
}
