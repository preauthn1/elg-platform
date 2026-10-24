<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * VtPay 金流商配置
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => '',
        'API_SECRET' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 83,
        'channels' => [
            // ! MYR
            [
                'currency' => 'MYR',
                'name' => 'VtPay (FPX)',
                'key' => 'FPX',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
                'options' => [
                    ['name' => 'Agro Bank', 'key' => 'AGRO01'],
                    ['name' => 'Alliance Bank', 'key' => 'ABMB0212'],
                    ['name' => 'AmBank', 'key' => 'AMBB0209'],
                    ['name' => 'Bank Islam', 'key' => 'BIMB0340'],
                    ['name' => 'Bank Muamalat', 'key' => 'BMMB0341'],
                    ['name' => 'Bank of China', 'key' => 'BOCM01'],
                    ['name' => 'Bank Rakyat', 'key' => 'BKRM0602'],
                    ['name' => 'BSBank Simpanan Nasional', 'key' => 'BSN0601'],
                    ['name' => 'CIMB Bank', 'key' => 'BCBB0235'],
                    ['name' => 'Hong Leong Bank', 'key' => 'HLB0224'],
                    ['name' => 'HSBC Bank', 'key' => 'HSBC0223'],
                    ['name' => 'KFH', 'key' => 'KFH0346'],
                    ['name' => 'Maybank', 'key' => 'MB2U0227'],
                    ['name' => 'OCBC Bank', 'key' => 'OCBC0229'],
                    ['name' => 'Public Bank', 'key' => 'PBB0233'],
                    ['name' => 'RHB Bank', 'key' => 'RHB0218'],
                    ['name' => 'Standard Chartered', 'key' => 'SCB0216'],
                    ['name' => 'United Overseas Bank', 'key' => 'UOB0226'],
                ],
                'info' => [
                    [
                        'name' => 'customer_name',
                        'type' => 'text',
                        'require' => 'true',
                        'display' => 'customer_name',
                        'placeholder' => 'customer_name',
                        'regex' => '^[a-zA-Z\s]{2,50}$',
                    ],
                    [
                        'name' => 'customer_email',
                        'type' => 'text',
                        'require' => 'true',
                        'display' => 'customer_email',
                        'placeholder' => 'customer_email',
                        'regex' => '^[a-zA-Z\s]{2,50}$',
                    ],
                    [
                        'name' => 'customer_phone',
                        'type' => 'text',
                        'require' => 'true',
                        'display' => 'customer_phone',
                        'placeholder' => 'customer_phone',
                        'regex' => '^[a-zA-Z\s]{2,50}$',
                    ],
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'VtPay (TNG)',
                'key' => 'TNG',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
                'info' => [
                    [
                        'name' => 'customer_name',
                        'type' => 'text',
                        'require' => 'true',
                        'display' => 'customer_name',
                        'placeholder' => 'customer_name',
                        'regex' => '^[a-zA-Z\s]{2,50}$',
                    ],
                    [
                        'name' => 'customer_email',
                        'type' => 'text',
                        'require' => 'true',
                        'display' => 'customer_email',
                        'placeholder' => 'customer_email',
                        'regex' => '^[a-zA-Z\s]{2,50}$',
                    ],
                    [
                        'name' => 'customer_phone',
                        'type' => 'text',
                        'require' => 'true',
                        'display' => 'customer_phone',
                        'placeholder' => 'customer_phone',
                        'regex' => '^[a-zA-Z\s]{2,50}$',
                    ],
                ],
            ],
            // ! 目前暫不支援信用卡=
            // [
            //     'currency' => 'MYR',
            //     'name' => 'VtPay (Credit Cards)',
            //     'key' => 'Cards',
            //     'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
            //     'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
            //     'info' => [
            //         [
            //             'name' => 'customer_name',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'customer_name',
            //             'placeholder' => 'customer_name',
            //             'regex' => '^[a-zA-Z\s]{2,50}$',
            //         ],
            //         [
            //             'name' => 'customer_email',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'customer_email',
            //             'placeholder' => 'customer_email',
            //             'regex' => '^[a-zA-Z\s]{2,50}$',
            //         ],
            //         [
            //             'name' => 'customer_phone',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'customer_phone',
            //             'placeholder' => 'customer_phone',
            //             'regex' => '^[a-zA-Z\s]{2,50}$',
            //         ],
            //         [
            //             'name' => 'card_holder_name',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'card_holder_name',
            //             'placeholder' => 'card_holder_name',
            //             'regex' => '^[a-zA-Z\s]{2,50}$',
            //         ],
            //         [
            //             'name' => 'card_number',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'card_number',
            //             'placeholder' => 'card_number',
            //             'regex' => '^\d{13,19}$',
            //         ],
            //         [
            //             'name' => 'card_exp_mn',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'card_exp_mn',
            //             'placeholder' => 'card_exp_mn',
            //             'regex' => '^(0[1-9]|1[0-2])$',
            //         ],
            //         [
            //             'name' => 'card_exp_yy',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'card_exp_yy',
            //             'placeholder' => 'card_exp_yy',
            //             'regex' => '^\d{2}$',
            //         ],
            //         [
            //             'name' => 'card_cvv',
            //             'type' => 'text',
            //             'require' => 'true',
            //             'display' => 'card_cvv',
            //             'placeholder' => 'card_cvv',
            //             'regex' => '^\d{3,4}$',
            //         ],
            //     ],
            // ],
            // ! SGD
            [
                'currency' => 'SGD',
                'name' => 'VtPay (Online Banking)',
                'key' => '100',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/2.png',
                'options' => [
                    ['name' => '(SGD) - DBS Bank', 'key' => 'DBS'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'key' => 'SCBS'],
                    ['name' => '(SGD) - Trust Bank', 'key' => 'TRUST'],
                    ['name' => '(SGD) - OCBC Bank', 'key' => 'OCBC'],
                ],
            ],
            [
                'currency' => 'SGD',
                'name' => 'VtPay (LBT)',
                'key' => 'lbt',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/2.png',
                'options' => [
                    ['name' => '(SGD) - DBS Bank', 'key' => 'DBS'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'key' => 'SCBS'],
                    ['name' => '(SGD) - Trust Bank', 'key' => 'TRUST'],
                    ['name' => '(SGD) - OCBC Bank', 'key' => 'OCBC'],
                ],
            ],
            // ! VND
            [
                'currency' => 'VND',
                'name' => 'VtPay - P2P',
                'key' => '100',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/3.png',
                'options' => [
                    ['name' => 'Military Commercial Joint Stock Bank', 'key' => 'MB'],
                    ['name' => 'VietinBank', 'key' => 'VTB'],
                    ['name' => 'Techcombank', 'key' => 'TCB'],
                    ['name' => 'Vietnam Prosperity Joint Stock Commercial Bank', 'key' => 'VPB'],
                    ['name' => 'DongA Bank', 'key' => 'DAB'],
                    ['name' => 'Tien Phong Commercial Joint Stock Bank', 'key' => 'TPB'],
                    ['name' => 'Asia Commercial Bank', 'key' => 'ACB'],
                    ['name' => 'Orient Commercial Joint Stock Bank', 'key' => 'OCB'],
                ],
            ],
            [
                'currency' => 'VND',
                'name' => 'VtPay - QR',
                'key' => 'QRPay',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/3.png',
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 84,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Affin Bank', 'code' => 'ABB0233'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => 'AGRO01'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'ABMB0212'],
                    ['name' => '(MYR) - AmBank', 'code' => 'AMBB0209'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'BIMB0340'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'BMMB0341'],
                    ['name' => '(MYR) - Bank of China', 'code' => 'BOCM01'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'BKRM0602'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'code' => 'BSN0601'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'BCBB0235'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HLB0224'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HSBC0223'],
                    ['name' => '(MYR) - KFH', 'code' => 'KFH0346'],
                    ['name' => '(MYR) - Maybank', 'code' => 'MB2U0227'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBC0229'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBB0233'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHB0218'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'SCB0216'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'UOB0226'],
                ],
            ],
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(SGD) - DBS Bank', 'code' => 'DBS'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => 'SCBS'],
                    ['name' => '(SGD) - Trust Bank', 'code' => 'TRUST'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                ],
            ],
            [
                'currency' => 'VND',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(VND) - Military Commercial Joint Stock Bank', 'code' => 'MB'],
                    ['name' => '(VND) - VietinBank', 'code' => 'VTB'],
                    ['name' => '(VND) - Techcombank', 'code' => 'TCB'],
                    ['name' => '(VND) - Vietnam Prosperity Joint Stock Commercial Bank', 'code' => 'VPB'],
                    ['name' => '(VND) - DongA Bank', 'code' => 'DAB'],
                    ['name' => '(VND) - Tien Phong Commercial Joint Stock Bank', 'code' => 'TPB'],
                    ['name' => '(VND) - Asia Commercial Bank', 'code' => 'ACB'],
                    ['name' => '(VND) - Orient Commercial Joint Stock Bank', 'code' => 'OCB'],
                    ['name' => '(VND) - Vietcombank', 'code' => 'VCB'],
                ],
            ],
        ],
    ],
];
