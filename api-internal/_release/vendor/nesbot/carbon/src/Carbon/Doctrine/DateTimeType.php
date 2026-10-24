<?php

/**
 * Thanks to https://example.invalid/flaushi for his suggestion:
 * https://example.invalid/doctrine/dbal/issues/2873#issuecomment-534956358
 */
namespace Carbon\Doctrine;

use Doctrine\DBAL\Types\VarDateTimeType;

class DateTimeType extends VarDateTimeType implements CarbonDoctrineType
{
    use CarbonTypeConverter;
}
