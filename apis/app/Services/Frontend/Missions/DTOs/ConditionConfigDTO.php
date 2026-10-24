<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\DTOs;

class ConditionConfigDTO
{
    public $type;

    public $value;

    /**
     * Properties which keys are not type or value
     *
     * @var array
     */
    public $extraDatas;

    public function __construct(array $config)
    {
        $this->type = $config['type'] ?? '';
        $this->value = $config['value'] ?? '';

        foreach ($config as $key => $value) {
            if ($key !== 'type' && $key !== 'value') {
                $this->extraDatas[$key] = $value;
            }
        }
    }
}
