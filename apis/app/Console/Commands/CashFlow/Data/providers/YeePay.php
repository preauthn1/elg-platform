<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * YeePay 金流商配置
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'DEPOSIT_URL' => '',
        'WITHDRAWAL_URL' => '',
        'MERCHANT_UID' => '',
        'MERCHANT_NUM' => '',
        'API_SECRET' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 79,
        'channels' => [
            [
                'currency' => 'VND',
                'name' => 'YeePay (QR)',
                'key' => '7',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => '',
                'min_amount' => 200000,
                'max_amount' => 250000000,
            ],
            [
                'currency' => 'VND',
                'name' => 'YeePay (momo)',
                'key' => '8',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
                'min_amount' => 50000,
                'max_amount' => 15000000,
            ],
            [
                'currency' => 'VND',
                'name' => 'YeePay (Banking)',
                'key' => '9',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => '',
                'min_amount' => 200000,
                'max_amount' => 250000000,
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 80,
        'channels' => [
            [
                'currency' => 'VND',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'min_amount' => 200000,
                'max_amount' => 250000000,
                'banks' => [
                    ['name' => '(VND) - Asia Commercial Bank', 'code' => '970416'],
                    ['name' => '(VND) - Bank for Investment and Development of Vietnam', 'code' => '970418'],
                    ['name' => '(VND) - Military Commercial Joint Stock Bank', 'code' => '970422'],
                    ['name' => '(VND) - An Binh Commercial Joint Stock Bank', 'code' => '970425'],
                    ['name' => '(VND) - Bac A Commercial Joint Stock Bank', 'code' => '970409'],
                    ['name' => '(VND) - Bao Viet Joint Stock Commercial Bank', 'code' => '970438'],
                    ['name' => '(VND) - DongA Commercial Joint Stock Bank', 'code' => '970406'],
                    ['name' => '(VND) - Vietnam Export Import Commercial Joint Stock Bank', 'code' => '970431'],
                    ['name' => '(VND) - Ho Chi Minh City Development Bank JSC', 'code' => '970437'],
                    ['name' => '(VND) - Kien Long Commercial Joint Stock Bank', 'code' => '970452'],
                    ['name' => '(VND) - Lien Viet Post Joint Stock Commercial Bank', 'code' => '970449'],
                    ['name' => '(VND) - Maritime Commercial Joint Stock Bank', 'code' => '970426'],
                    ['name' => '(VND) - Nam A Commercial Joint Stock Bank', 'code' => '970428'],
                    ['name' => '(VND) - Orient Commercial Joint Stock Bank', 'code' => '970448'],
                    ['name' => '(VND) - Petrolimex Joint Stock Commercial Bank', 'code' => '970430'],
                    ['name' => '(VND) - PVcomBank', 'code' => '970412'],
                    ['name' => '(VND) - Saigon Bank for Industry and Trade', 'code' => '970400'],
                    ['name' => '(VND) - Saigon Joint Stock Commercial Bank', 'code' => '970429'],
                    ['name' => '(VND) - Southeast Asia Commercial Joint Stock Bank', 'code' => '970440'],
                    ['name' => '(VND) - Saigon-Hanoi Commercial Joint Stock Bank', 'code' => '970443'],
                    ['name' => '(VND) - Shinhan Bank Vietnam Limited', 'code' => '970424'],
                    ['name' => '(VND) - Techcombank', 'code' => '970407'],
                    ['name' => '(VND) - Tien Phong Commercial Joint Stock Bank', 'code' => '970423'],
                    ['name' => '(VND) - Vietcombank', 'code' => '970436'],
                    ['name' => '(VND) - Vietnam International Commercial Joint Stock Bank', 'code' => '970441'],
                    ['name' => '(VND) - Vietnam Prosperity Joint Stock Commercial Bank', 'code' => '970432'],
                    ['name' => '(VND) - VietinBank', 'code' => '970415'],
                    ['name' => '(VND) - Vietnam Thuong Tin Commercial Joint Stock Bank', 'code' => '970433'],
                    ['name' => '(VND) - Sacombank', 'code' => '970403'],
                    ['name' => '(VND) - CAKE Digital Bank', 'code' => '546034'],
                    ['name' => '(VND) - Agribank', 'code' => '970405'],
                    ['name' => '(VND) - Vietnam Co-operative Bank', 'code' => '970446'],
                    ['name' => '(VND) - Indovina Bank Limited', 'code' => '970434'],
                    ['name' => '(VND) - National Citizen Commercial Joint Stock Bank', 'code' => '970419'],
                    ['name' => '(VND) - Ocean Commercial Joint Stock Bank', 'code' => '970414'],
                    ['name' => '(VND) - Viet A Commercial Joint Stock Bank', 'code' => '970427'],
                    ['name' => '(VND) - Woori Bank', 'code' => '970457'],
                    ['name' => '(VND) - Viet Capital Commercial Joint Stock Bank ', 'code' => '970454'],
                    ['name' => '(VND) - VRB', 'code' => '970421'],
                    ['name' => '(VND) - GPBank', 'code' => '970408'],
                    ['name' => '(VND) - CIMB Bank', 'code' => '422589'],
                    ['name' => '(VND) - Hong Leong Bank', 'code' => '970442'],
                    ['name' => '(VND) - IBK - chi nhanh HCM', 'code' => '970456'],
                    ['name' => '(VND) - IBK - chi nhanh Ha Noi', 'code' => '970455'],
                    ['name' => '(VND) - Public Bank', 'code' => '970439'],
                    ['name' => '(VND) - UOB Bank', 'code' => '970458'],
                    // ['name' => '(VND) - MOMO', 'code' => 'MOMO'],
                ],
            ],
        ],
    ],
];
