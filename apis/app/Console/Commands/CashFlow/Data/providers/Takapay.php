<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * TakaPay 金流商配置
 * 支付類型: Ewallet
 * 幣別: BDT
 */
return [
    'deposit' => [
        'cash_flow_id' => 89,
        'channels' => [
            [
                'currency' => 'BDT',
                'name' => 'TakaPay',
                'key' => '1',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
                'min_amount' => 200,
                'max_amount' => 25000,
                'choose_bank' => true,
                'options' => [
                    ['name' => 'Bkash Wallet', 'key' => '1.bkash'],
                    ['name' => 'Rocket Wallet', 'key' => '1.rocket'],
                    ['name' => 'Nagad Wallet', 'key' => '1.nagad'],
                ],
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 90,
        'channels' => [
            [
                'currency' => 'BDT',
                'type' => WithdrawalTypeEnum::BANK->value,
                'min_amount' => 500,
                'max_amount' => 25000,
                'banks' => [
                    ['name' => '(BDT) - Bkash Wallet', 'code' => '1.bkash'],
                    ['name' => '(BDT) - Rocket Wallet', 'code' => '1.rocket'],
                    ['name' => '(BDT) - Nagad Wallet', 'code' => '1.nagad'],
                ],
            ],
        ],
    ],
];
