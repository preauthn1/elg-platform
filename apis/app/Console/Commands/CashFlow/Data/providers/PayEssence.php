<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * PayEssence 金流商配置
 */
return [
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 81,
        'channels' => [
            [
                'currency' => 'MYR',
                'name' => 'PayEssence P2P',
                'key' => 'PAYESSENCE.P2P',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/PayEssence.png',
            ],
            [
                'currency' => 'MYR',
                'name' => 'PayEssence E-Wallet',
                'key' => 'PAYESSENCE.EWALLET',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/PayEssence.png',
            ],
            [
                'currency' => 'MYR',
                'name' => 'PayEssence TNGPIN',
                'key' => 'PAYESSENCE.TNGPIN',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/PayEssence.png',
            ],
            [
                'currency' => 'USDT',
                'name' => 'PayEssence EUSDT',
                'key' => 'PAYESSENCE.EUSDT',
                'type' => DepositTypeEnum::CRYPTO->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/gff/payment/usdt.png',
            ],
            [
                'currency' => 'USDT',
                'name' => 'PayEssence TUSDT',
                'key' => 'PAYESSENCE.TUSDT',
                'type' => DepositTypeEnum::CRYPTO->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/gff/payment/usdt.png',
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 82,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Maybank', 'code' => '1'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => '2'],
                    ['name' => '(MYR) - RHB Bank', 'code' => '3'],
                    ['name' => '(MYR) - Public Bank', 'code' => '4'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => '5'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => '6'],
                    ['name' => '(MYR) - Bank Islam', 'code' => '7'],
                    ['name' => '(MYR) - Affin Bank', 'code' => '8'],
                    ['name' => '(MYR) - AmBank', 'code' => '9'],
                    ['name' => '(MYR) - BSN', 'code' => '10'],
                    ['name' => '(MYR) - Touch n Go', 'code' => '11'],
                    ['name' => '(MYR) - GX Bank', 'code' => '12'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => '13'],
                    ['name' => '(MYR) - Ryt Bank', 'code' => '14'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => '15'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => '16'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => '17'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => '18'],
                    ['name' => '(MYR) - Boost Bank', 'code' => '20'],
                    ['name' => '(MYR) - Boost Ewallet', 'code' => '21'],
                    ['name' => '(MYR) - BigPay', 'code' => '22'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => '23'],
                    ['name' => '(MYR) - Citibank', 'code' => '24'],
                    ['name' => '(MYR) - Alrajhi Bank', 'code' => '25'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => '26'],
                    ['name' => '(MYR) - MUFG Bank', 'code' => '27'],
                    // target/op_tickets#2922：三方 2026-08 新增 30–39（名稱取 Data/banks/MYR.php 的 standard_name）
                    ['name' => '(MYR) - AEON Bank', 'code' => '30'],
                    ['name' => '(MYR) - Bangkok Bank Malaysia', 'code' => '31'],
                    ['name' => '(MYR) - Finexus', 'code' => '32'],
                    ['name' => '(MYR) - GrabPay', 'code' => '33'],
                    ['name' => '(MYR) - MBSB Bank', 'code' => '34'],
                    ['name' => '(MYR) - Merchantrade', 'code' => '35'],
                    ['name' => '(MYR) - Mizuho Bank', 'code' => '36'],
                    ['name' => '(MYR) - SetelPay', 'code' => '37'],
                    ['name' => '(MYR) - ShopeePay', 'code' => '38'],
                    // 三方文件拼作 Sumimoto（拼字錯誤），我方沿用正確拼法 Sumitomo
                    ['name' => '(MYR) - Sumitomo Mitsui Bank', 'code' => '39'],
                ],
            ],
            [
                'currency' => 'VND',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(VND) - Asia Commercial Bank', 'code' => '101'],
                    ['name' => '(VND) - BIDV', 'code' => '102'],
                    ['name' => '(VND) - Military Commercial Bank', 'code' => '103'],
                    ['name' => '(VND) - DongA Bank', 'code' => '104'],
                    ['name' => '(VND) - Eximbank Vietnam', 'code' => '105'],
                    ['name' => '(VND) - Saigon-Hanoi Commercial Joint Stock Bank', 'code' => '106'],
                    ['name' => '(VND) - HD Bank', 'code' => '107'],
                    ['name' => '(VND) - Sacombank', 'code' => '108'],
                    ['name' => '(VND) - AGRIBANK', 'code' => '109'],
                    ['name' => '(VND) - Techcombank', 'code' => '110'],
                    ['name' => '(VND) - VietinBank', 'code' => '111'],
                    ['name' => '(VND) - Vietcombank ', 'code' => '112'],
                ],
            ],
            [
                'currency' => 'USDT',
                'type' => WithdrawalTypeEnum::CRYPTO,
                'banks' => [
                    ['name' => 'USDT - TRC20', 'code' => '0'],
                    ['name' => 'USDT - ERC20', 'code' => '0'],
                ],
            ],
        ],
    ],
];
