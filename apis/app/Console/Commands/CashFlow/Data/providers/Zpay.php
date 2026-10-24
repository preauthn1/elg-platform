<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;

/**
 * ZPay 金流商配置
 */
return [
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 99,
        'channels' => [
            [
                'currency' => 'MYR',
                'name' => 'ZPay - P2P',
                'key' => '',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                // 'type' => 11, // Vplus33 type
                'image_path' => '',
            ],
            [
                'currency' => 'MYR',
                'name' => 'ZPay - TNG',
                'key' => 'TNG',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                // 'type' => 12, // Vplus33 type
                'image_path' => '',
            ],
        ],
    ],
];
