<?php

declare(strict_types=1);

use App\Enums\CashFlowTypeEnum;

/** @noinspection SpellCheckingInspection */
return [
    [
        'id' => 58,
        'third_party_code' => 'D_TNGPIN',
        'name' => 'TNGPIN - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => null,
        // 回調來源 IP 白名單（工單 target/tickets#4449）。空白名單＝一律拒絕（fail-closed），
        // 新站台建表時沒帶上這份就收不到回調。只在建立時寫入，既有站台的調整不會被 sync 覆蓋。
        // 廠商沒給清單，這個 IP 是從正式 log 反推的（273 筆回調全部同一個來源）。
        'whitelist' => '0.0.0.0', // hardcode-ok: 廠商出口 IP，本檔即唯一出處
    ],
    [
        'id' => 63,
        'third_party_code' => 'D_LEGEND',
        'name' => 'LegendPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
    ],
    [
        'id' => 64,
        'third_party_code' => 'W_LEGEND',
        'name' => 'LegendPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
    ],
    // [
    //     'id' => 65,
    //     'third_party_code' => 'D_OXY',
    //     'name' => 'OxyPay - Deposit',
    //     'rate' => '1.00000',
    //     'percentage' => '0.00000',
    //     'type' => 1,
    //     'remark' => 'THB',
    // ],
    // [
    //     'id' => 66,
    //     'third_party_code' => 'W_OXY',
    //     'name' => 'OxyPay - Withdrawal',
    //     'rate' => '1.00000',
    //     'percentage' => '0.00000',
    //     'type' => 3,
    //     'remark' => 'THB',
    // ],
    [
        'id' => 67,
        'third_party_code' => 'D_FPAY',
        'name' => 'FPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
            'VND' => [
                'rate' => 0.000156242,
                'min_amount' => 50000,
                'max_amount' => 30000000,
            ],
            'IDR' => [
                'rate' => 0.000247394,
                'min_amount' => 10000,
                'max_amount' => 10000000,
            ],
        ],
    ],
    [
        'id' => 68,
        'third_party_code' => 'W_FPAY',
        'name' => 'FPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
            'VND' => [
                'rate' => 0.000157104,
                'min_amount' => 100000,
                'max_amount' => 30000000,
            ],
            'IDR' => [
                'rate' => 0.000248759,
                'min_amount' => 10000,
                'max_amount' => 20000000,
            ],
        ],
    ],
    [
        'id' => 69,
        'third_party_code' => 'D_AP8',
        'name' => 'Ap8Pay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        // 回調來源 IP 白名單（廠商提供，工單 target/tickets#4446）。Ap8Pay 回調無驗簽，
        // 由 CallbackSourceGuardTrait 依這份擋回調，空白名單＝一律拒絕（fail-closed），
        // 新站台建表時沒帶上這份就收不到回調。只在建立時寫入，既有站台的調整不會被 sync 覆蓋。
        // 這份是第二次提供的；客服第一次給的那五個 IP 是錯的，dev 實測一個都對不上。
        // 廠商不分環境，正式與 dev 共用這三個出口 IP。
        'whitelist' => '0.0.0.0,0.0.0.0,0.0.0.0', // hardcode-ok: 廠商出口 IP，本檔即唯一出處
    ],
    [
        'id' => 70,
        'third_party_code' => 'W_AP8',
        'name' => 'Ap8Pay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        // 這列刻意不帶 whitelist：AP8Pay 沒有代付（Providers/Ap8Pay 底下沒有 Withdrawal、
        // 也沒有 WithdrawalCallback），沒有回調端點要守。本列備而不用，哪天真的接了代付
        // 再照 D_AP8 補同一組 IP（廠商代收代付共用出口 IP）。
    ],
    [
        'id' => 71,
        'third_party_code' => 'D_MAJESTIC',
        'name' => 'MajesticPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 1,
                'max_amount' => 20000,
            ],
        ],
    ],
    [
        'id' => 72,
        'third_party_code' => 'W_MAJESTIC',
        'name' => 'MajesticPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 1,
                'max_amount' => 20000,
            ],
        ],
    ],
    [
        'id' => 73,
        'third_party_code' => 'D_GMPAY',
        'name' => 'GMPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 20,
                'max_amount' => 3000,
            ],
        ],
    ],
    [
        'id' => 74,
        'third_party_code' => 'W_GMPAY',
        'name' => 'GMPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 3000,
            ],
        ],
    ],
    [
        'id' => 75,
        'third_party_code' => 'D_EASYPAY',
        'name' => 'EasyPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        // 回調來源 IP 白名單（工單 target/tickets#4449）。空白名單＝一律拒絕（fail-closed），
        // 新站台建表時沒帶上這份就收不到回調。只在建立時寫入，既有站台的調整不會被 sync 覆蓋。
        // 廠商提供，已與正式 log 的實際來源逐一對上。
        'whitelist' => '0.0.0.0,0.0.0.0,0.0.0.0,0.0.0.0,0.0.0.0', // hardcode-ok: 廠商出口 IP，本檔即唯一出處
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 200,
                'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 76,
        'third_party_code' => 'W_EASYPAY',
        'name' => 'EasyPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        // 回調來源 IP 白名單（工單 target/tickets#4449）。規則同 D_EASYPAY。
        // 與代收同一組：廠商未分代收代付，TPower／VWorld／Yes2Win 線上三站即如此配置。
        'whitelist' => '0.0.0.0,0.0.0.0,0.0.0.0,0.0.0.0,0.0.0.0', // hardcode-ok: 廠商出口 IP，本檔即唯一出處
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 30,
                'max_amount' => 50000,
            ],
        ],
    ],
    // [
    //     'id' => 77,
    //     'third_party_code' => 'D_EPICPAY',
    //     'name' => 'EpicPay - Deposit',
    //     'rate' => '1.00000',
    //     'percentage' => '0.00000',
    //     'type' => 1,
    //     'remark' => '',
    //     'info' => [
    //         'MYR' => [
    //             'rate' => 1,
    //             'min_amount' => 10,
    //             'max_amount' => 50000,
    //         ],
    //     ],
    // ],
    [
        'id' => 78,
        'third_party_code' => 'W_EPICPAY',
        'name' => 'EpicPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 79,
        'third_party_code' => 'D_YEEPAY',
        'name' => 'YeePay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'VND' => [
                'rate' => 0.000154167,
            ],
        ],
    ],
    [
        'id' => 80,
        'third_party_code' => 'W_YEEPAY',
        'name' => 'YeePay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'VND' => [
                'rate' => 0.000155017,
            ],
        ],
    ],
    [
        'id' => 81,
        'third_party_code' => 'D_PAYESSENCE',
        'name' => 'PayEssence - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 50000,
            ],
        ],
    ],
    [
        'id' => 82,
        'third_party_code' => 'W_PAYESSENCE',
        'name' => 'PayEssence - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 83,
        'third_party_code' => 'D_VTPAY',
        'name' => 'VtPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 10000,
            ],
            'SGD' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
            'VND' => [
                'rate' => 0.000156242,
                'min_amount' => 50000,
                'max_amount' => 30000000,
            ],
        ],
    ],
    [
        'id' => 84,
        'third_party_code' => 'W_VTPAY',
        'name' => 'VtPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 10000,
            ],
            'SGD' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
            'VND' => [
                'rate' => 0.000156242,
                'min_amount' => 50000,
                'max_amount' => 30000000,
            ],
        ],
    ],
    [
        'id' => 85,
        'third_party_code' => 'D_GLOBEPAY',
        'name' => 'GlobePay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 50000,
            ],
        ],
    ],
    [
        'id' => 86,
        'third_party_code' => 'W_GLOBEPAY',
        'name' => 'GlobePay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 87,
        'third_party_code' => 'D_QUICKPAY',
        'name' => 'QuickPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 50000,
            ],
        ],
    ],
    [
        'id' => 88,
        'third_party_code' => 'W_QUICKPAY',
        'name' => 'QuickPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 89,
        'third_party_code' => 'D_TAKAPAY',
        'name' => 'TakaPay - Ewallet',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => 'Ewallet',
        'info' => [
            'BDT' => [
                'rate' => 0.034830065,
                'min_amount' => 200,
                'max_amount' => 25000,
            ],
        ],
    ],
    [
        'id' => 90,
        'third_party_code' => 'W_TAKAPAY',
        'name' => 'TakaPay',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => 'Ewallet',
        'info' => [
            'BDT' => [
                'rate' => 0.034830065,
                'min_amount' => 500,
                'max_amount' => 25000,
            ],
        ],
    ],
    [
        'id' => 89,
        'third_party_code' => 'D_TAKAPAY',
        'name' => 'TakaPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => 'Ewallet',
        'info' => [
            'BDT' => [
                'rate' => 1,
                'min_amount' => 200,
                'max_amount' => 25000,
            ],
        ],
    ],
    [
        'id' => 90,
        'third_party_code' => 'W_TAKAPAY',
        'name' => 'TakaPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => 'Ewallet',
        'info' => [
            'BDT' => [
                'rate' => 1,
                'min_amount' => 200,
                'max_amount' => 25000,
            ],
        ],
    ],
    [
        'id' => 91,
        'third_party_code' => 'D_KIRA',
        'name' => 'KIRA - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 30,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 92,
        'third_party_code' => 'W_KIRA',
        'name' => 'KIRA - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 30,
                'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 93,
        'third_party_code' => 'D_TOPPAY',
        'name' => 'TopPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'IDR' => [
                'rate' => 0.00023,
                'min_amount' => 10000,
                'max_amount' => 100000000,
            ],
        ],
    ],
    [
        'id' => 94,
        'third_party_code' => 'W_TOPPAY',
        'name' => 'TopPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'IDR' => [
                'rate' => 0.00023,
                'min_amount' => 10000,
                'max_amount' => 20000000,
            ],
        ],
    ],
    [
        'id' => 95,
        'third_party_code' => 'D_TRUSTPAY',
        'name' => 'TrustPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 50000,
            ],
        ],
    ],
    [
        'id' => 96,
        'third_party_code' => 'W_TRUSTPAY',
        'name' => 'TrustPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 97,
        'third_party_code' => 'D_SUREPAY',
        'name' => 'SurePay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 50000,
            ],
            'IDR' => [
                'rate' => 0.00023,
                'min_amount' => 20000,
                'max_amount' => 20000000,
            ],
        ],
    ],
    [
        'id' => 98,
        'third_party_code' => 'W_SUREPAY',
        'name' => 'SurePay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 49999.99,
            ],
            'IDR' => [
                'rate' => 0.000248759,
                'min_amount' => 10000,
                'max_amount' => 50000000,
            ],
        ],
    ],
    [
        'id' => 99,
        'third_party_code' => 'D_ZPay',
        'name' => 'ZPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 50000,
            ],
        ],
    ],
    [
        'id' => 100,
        'third_party_code' => 'W_ZPay',
        'name' => 'ZPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 101,
        'third_party_code' => 'D_PayPro',
        'name' => 'PayPro - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'IDR' => [
                'rate' => 1,
                'min_amount' => 10000,
                'max_amount' => 10000000,
            ],
        ],
    ],
    [
        'id' => 102,
        'third_party_code' => 'W_PayPro',
        'name' => 'PayPro - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'IDR' => [
                'rate' => 1,
                'min_amount' => 10000,
                'max_amount' => 50000000,
            ],
        ],
    ],
    [
        'id' => 103,
        'third_party_code' => 'D_VaderPay',
        'name' => 'VaderPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'IDR' => [
                'rate' => 1,
                'min_amount' => 10000,
                'max_amount' => 10000000,
            ],
        ],
    ],
    [
        'id' => 104,
        'third_party_code' => 'W_VaderPay',
        'name' => 'VaderPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'IDR' => [
                'rate' => 1,
                'min_amount' => 10000,
                'max_amount' => 10000000,
            ],
        ],
    ],
    [
        'id' => 105,
        'third_party_code' => 'D_AKPAY',
        'name' => 'AkPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 1,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 106,
        'third_party_code' => 'W_AKPAY',
        'name' => 'AkPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => 3,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 107,
        'third_party_code' => 'D_BIGPAYZ',
        'name' => 'BigPayz - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                // 'min_amount' => 50,
                // 'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 108,
        'third_party_code' => 'W_BIGPAYZ',
        'name' => 'BigPayz - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            'MYR' => [
                'rate' => 1,
                // 'min_amount' => 50,
                // 'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 109,
        'third_party_code' => 'D_BILLIONPAY',
        'name' => 'BillionPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
        ],
    ],
    [
        'id' => 110,
        'third_party_code' => 'W_BILLIONPAY',
        'name' => 'BillionPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
        ],
    ],
    [
        'id' => 111,
        'third_party_code' => 'D_ANTZPAY',
        'name' => 'AntzPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            'THB' => [
                'rate' => 0.12,
                'min_amount' => 200,
                'max_amount' => 2000000,
            ],
        ],
    ],
    [
        'id' => 112,
        'third_party_code' => 'W_ANTZPAY',
        'name' => 'AntzPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            'THB' => [
                'rate' => 0.12,
                'min_amount' => 200,
                'max_amount' => 2000000,
            ],
        ],
    ],
    [
        'id' => 113,
        'third_party_code' => 'D_MAXPAY',
        'name' => 'MaxPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        // 回調來源 IP 白名單（工單 target/tickets#4449）。空白名單＝一律拒絕（fail-closed），
        // 新站台建表時沒帶上這份就收不到回調。只在建立時寫入，既有站台的調整不會被 sync 覆蓋。
        // 廠商提供，已與正式 log 的實際來源對上。
        'whitelist' => '0.0.0.0', // hardcode-ok: 廠商出口 IP，本檔即唯一出處
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 30,
                'max_amount' => 5000,
            ],
        ],
    ],
    [
        'id' => 114,
        'third_party_code' => 'W_MAXPAY',
        'name' => 'MaxPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        // 回調來源 IP 白名單（工單 target/tickets#4449）。規則同 D_MAXPAY。
        // 與代收同一個 IP：廠商未分代收代付，TPower／VWorld／Yes2Win 線上三站即如此配置。
        'whitelist' => '0.0.0.0', // hardcode-ok: 廠商出口 IP，本檔即唯一出處
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 5000,
            ],
        ],
    ],
    [
        'id' => 117,
        'third_party_code' => 'D_FLASHPAY',
        'name' => 'FlashPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 30,
                'max_amount' => 10000,
            ],
        ],
    ],
    [
        'id' => 118,
        'third_party_code' => 'W_FLASHPAY',
        'name' => 'FlashPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 100,
                'max_amount' => 10000,
            ],
        ],
    ],
    // DGPay 全新一組（共用 DgPay Driver；幣別靠 agent_cash_flows.currency_id 區分，不再每幣別多開列）
    [
        'id' => 119,
        'third_party_code' => 'D_DGPAY',
        'name' => 'DGPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '代收',
        'info' => [
            'MYR' => ['rate' => 1],
            'SGD' => ['rate' => 1],
            'IDR' => ['rate' => 1],
        ],
    ],
    [
        'id' => 120,
        'third_party_code' => 'W_DGPAY',
        'name' => 'DGPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '代付',
        'info' => [
            'MYR' => ['rate' => 1],
            'SGD' => ['rate' => 1],
            'IDR' => ['rate' => 1],
        ],
    ],
    [
        'id' => 121,
        'third_party_code' => 'D_PAYMATE',
        'name' => 'PayMate - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            // PayMate 實際限額：QR/eWallet min RM5（max 依客戶錢包上限，無固定值，取 P2P Deposit 的
            // RM30000 作為平台層保守上限）；P2P Deposit min RM10、max RM30000
            'MYR' => [
                'rate' => 1,
                'min_amount' => 5,
                'max_amount' => 30000,
            ],
        ],
    ],
    [
        'id' => 122,
        'third_party_code' => 'W_PAYMATE',
        'name' => 'PayMate - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            // PayMate 代付實際限額：Payout min RM50、max RM4999
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 4999,
            ],
        ],
    ],
    [
        'id' => 123,
        'third_party_code' => 'D_B3WALLET',
        'name' => 'B3 Wallet - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
        ],
    ],
    [
        'id' => 124,
        'third_party_code' => 'W_B3WALLET',
        'name' => 'B3 Wallet - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '',
        'info' => [
            'SGD' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 5000,
            ],
        ],
    ],
    // TTPay ＝ DGPay 換名白牌（TtPay 薄繼承殼共用 DgPay 實作；規格同 DGPay 119/120）
    // id 125/126：123/124 已被 develop in-flight 的 B3 Wallet 占用（D_B3WALLET/W_B3WALLET）
    [
        'id' => 125,
        'third_party_code' => 'D_TTPAY',
        'name' => 'TTPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '代收',
        'info' => [
            'MYR' => ['rate' => 1],
            'SGD' => ['rate' => 1],
            'IDR' => ['rate' => 1],
        ],
    ],
    [
        'id' => 126,
        'third_party_code' => 'W_TTPAY',
        'name' => 'TTPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '代付',
        'info' => [
            'MYR' => ['rate' => 1],
            'SGD' => ['rate' => 1],
            'IDR' => ['rate' => 1],
        ],
    ],
    // SpeedPay ＝ SE PayCenter 同源協定的另一組商戶（共用 PayMate 實作，見 config/payment.php
    // 的 SPEEDPAY 註冊）。工單 #4172；商戶自身的舊系統代收配置見 docs/integrations/payments/SpeedPay/api.md
    [
        'id' => 127,
        'third_party_code' => 'D_SPEEDPAY',
        'name' => 'SpeedPay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '代收',
        'info' => [
            // 平台層保守上限：取本商戶舊系統代收實際成交區間（P2P 29,196 筆 min 10 / max 20000；
            // Duitnow 最小成交 5）。三方文檔僅載「限額依商戶設定」，無固定值
            'MYR' => [
                'rate' => 1,
                'min_amount' => 5,
                'max_amount' => 20000,
            ],
        ],
    ],
    [
        'id' => 128,
        'third_party_code' => 'W_SPEEDPAY',
        'name' => 'SpeedPay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '代付',
        'info' => [
            // ! 本商戶（SPMVW）尚無任何代付配置與代付實績，限額為暫用保守值：
            // ! 取同協定（SE PayCenter）唯一有代付實績的 PayMate cf 122 之實際限額
            // ! min RM50 / max RM4999。此值會經 AgentBankSyncService 寫進
            // ! agent_group + currency 層級共用的 banks 列（該 group 缺列時），
            // ! 故不可放寬為代收上限 20000——錯了會連帶影響同 group 其他代付渠道。
            // ! 上線前須向 SpeedPay 確認實際代付限額與開通狀態
            'MYR' => [
                'rate' => 1,
                'min_amount' => 50,
                'max_amount' => 4999,
            ],
        ],
    ],
    // CCPayment：加密貨幣支付閘道，本次接入範圍為 USDT-TRC20 代收與代付（工單 target/tickets#4238）。
    // 整合模式採 Permanent Address Mode（每位會員一個固定收款地址），選型歷程見
    // docs/integrations/payments/CCPayment/integration.md 第 6 節。
    [
        'id' => 129,
        'third_party_code' => 'D_CCPAYMENT',
        'name' => 'CCPayment - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '代收',
        // ! 刻意不設 info 限額：實測 cash-flow:sync --dry-run 確認，本欄唯一效果是去改寫
        // ! base 層（agent_group_id 0）共用的 USDT - TRC20 銀行列 min/max 與 image_url，
        // ! 影響所有引用該列的支付通道而非只有 CCPayment；OrderType 本來就吃不到此值（顯示 N/A）。
        // ! 支付通道限額請在後台的群組層銀行列設定（bank:set-amount-limits 命令仍在銀行收斂分支，尚未合入 master）。
    ],
    [
        'id' => 130,
        'third_party_code' => 'W_CCPAYMENT',
        'name' => 'CCPayment - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '代付',
        // ! 同代收，刻意不設 info 限額，理由見上。TPower 群組層（agent_group_id 674）
        // ! 已有 USDT 提款限額 min 100 / max 10000（banks.id 485，code USDT0001；
        // ! 上線命令會沿用該列並以 base 層 1369 覆寫名稱與手續費），
        // ! 該值不受本檔影響。網路費由誰負擔以金流商戶 info 的 MERCHANT_PAY_NETWORK_FEE 決定，
        // ! 預設 false 由會員負擔（到帳數量＝提現數量－網路費），PM 定案後改設定不改代碼。
    ],
    // A3Pay：SGD 代收代付（VWorld-SG），工單 target/tickets#4394。
    // 限額工單未給，刻意不設 info（理由同 CCPayment：只會改寫 base 層共用銀行列），由後台群組層設定。
    [
        'id' => 131,
        'third_party_code' => 'D_A3PAY',
        'name' => 'A3Pay - Deposit',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::DEPOSIT->value,
        'remark' => '代收',
    ],
    [
        'id' => 132,
        'third_party_code' => 'W_A3PAY',
        'name' => 'A3Pay - Withdrawal',
        'rate' => '1.00000',
        'percentage' => '0.00000',
        'type' => CashFlowTypeEnum::WITHDRAW->value,
        'remark' => '代付',
    ],
];

/**
 * 設定完請執行 php artisan db:seed --class=SyncCashFlowsSeeder 寫入數據
 * - 代收會在 cash_flows, order_types 新增資料
 * - 代付會在 cash_flows, cash_flow_banks, banks 新增資料
 *
 * 然後執行 php artisan agent:add-agent-cash-flow 建立商戶
 */
