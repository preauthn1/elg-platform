<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\DTOs;

class ActionConfigDTO
{
    public $type;

    /**
     * Properties which keys are not type or value
     *
     * @var array
     */
    public $extraDatas;

    public function __construct(array $config)
    {
        $this->type = $config['type'] ?? '';

        foreach ($config as $key => $value) {
            if ($key !== 'type') {
                $this->extraDatas[$key] = $value;
            }
        }
    }
}
