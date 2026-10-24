<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * Bigpayz 金流商配置
 *
 * agent_cash_flow.info 使用三組網域，無 API_URL fallback：
 * - PAYIN_API_URL   : https://example.invalid (Payin DepositV2, TransInfo)
 * - PAYOUT_API_URL  : https://example.invalid (Payout Withdrawal, GetBalance)
 * - DUITNOW_API_URL : https://example.invalid (DuitNow Deposit)
 */
return [
    'credentials' => [
        'PAYIN_API_URL' => '',
        'PAYOUT_API_URL' => '',
        'DUITNOW_API_URL' => '',
        'MERCHANT_CODE' => '',
        'API_KEY' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 107,
        'channels' => [
            [
                'currency' => 'MYR',
                'min_amount' => 30,
                'max_amount' => 10000,
                'name' => 'Bigpayz Duitnow',
                'key' => 'qr.duitnow',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
            ],
            [
                'currency' => 'MYR',
                'min_amount' => 30,
                'max_amount' => 10000,
                'name' => 'Bigpayz Transfer',
                'choose_bank' => true,
                'key' => 'p2p',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => '',
                'options' => [
                    ['name' => '(MYR) - Affin Bank', 'key' => 'AFBB'],
                    ['name' => '(MYR) - Alliance Bank', 'key' => 'ABMB'],
                    ['name' => '(MYR) - Ambank', 'key' => 'AMBB'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'key' => 'BIMB'],
                    ['name' => '(MYR) - Bank Kerjasama Rakyat', 'key' => 'BKRM'],
                    ['name' => '(MYR) - Bank Simpanan National', 'key' => 'BSNB'],
                    ['name' => '(MYR) - CIMB Bank Berhad', 'key' => 'CIMB'],
                    ['name' => '(MYR) - Hong Leong Bank Berhad', 'key' => 'HLBB'],
                    ['name' => '(MYR) - Maybank Berhad', 'key' => 'MB2U'],
                    ['name' => '(MYR) - Public Bank Berhad', 'key' => 'PUBB'],
                    ['name' => '(MYR) - RHB Bank Berhad', 'key' => 'RHBB'],
                    ['name' => '(MYR) - OCBC Bank', 'key' => 'OCBC'],
                ],
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 108,
        'channels' => [
            [
                'currency' => 'MYR',
                'min_amount' => 100,
                'max_amount' => 30000,
                'type' => WithdrawalTypeEnum::BANK->value,
                'image_path' => '',
                'banks' => [
                    ['name' => '(MYR) - Affin Bank', 'code' => 'AFBB'],
                    ['name' => '(MYR) - AGROBANK', 'code' => 'AGRO'],
                    ['name' => '(MYR) - Alliance Bank Malaysia Berhad', 'code' => 'ABMB'],
                    ['name' => '(MYR) - Ambank', 'code' => 'AMBB'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'BIMB'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'BKRM'], // Bank Kerjasama Rakyat -> Bank Rakyat
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'BMMB'],
                    ['name' => '(MYR) - Bank Of America', 'code' => 'BOFA'],
                    ['name' => '(MYR) - Bank Of China', 'code' => 'BOCM'],
                    ['name' => '(MYR) - Bank Simpanan National', 'code' => 'BSNB'],
                    ['name' => '(MYR) - CIMB Bank Berhad', 'code' => 'CIMB'],
                    ['name' => '(MYR) - GX Bank Berhad', 'code' => 'GXSP'],
                    ['name' => '(MYR) - Hong Leong Bank Berhad', 'code' => 'HLBB'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(MYR) - Maybank Berhad', 'code' => 'MB2U'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(MYR) - Public Bank Berhad', 'code' => 'PUBB'],
                    ['name' => '(MYR) - RHB Bank Berhad', 'code' => 'RHBB'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'STCB'],
                    ['name' => '(MYR) - Touch N Go', 'code' => 'TNGO'],
                    ['name' => '(MYR) - UNITED OVERSEAS BANK', 'code' => 'UOBB'],
                ],
            ],
        ],
    ],
];
