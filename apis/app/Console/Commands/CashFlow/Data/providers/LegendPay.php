<?php

declare(strict_types=1);

use App\Enums\WithdrawalTypeEnum;

/**
 * LegendPay 金流商配置
 */
return [
    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 64,
        'channels' => [
            [
                'currency' => 'INR',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    [
                        'name' => 'IFSC',
                        'code' => 'IFSC',
                        'info' => [
                            'country' => [
                                'require' => true,
                                'type' => 'select',
                                'display' => 'country',
                                'placeholder' => 'country',
                                'options' => [
                                    [
                                        'text' => 'India',
                                        'value' => '91',
                                    ],
                                ],
                            ],
                            'mobile' => [
                                'require' => true,
                                'type' => 'text',
                                'display' => 'mobile',
                                'placeholder' => 'mobile',
                                'regex' => '^[6-9]\d{9}$',
                            ],
                            'ifsc' => [
                                'require' => true,
                                'type' => 'text',
                                'display' => 'IFSC',
                                'placeholder' => 'IFSC',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
