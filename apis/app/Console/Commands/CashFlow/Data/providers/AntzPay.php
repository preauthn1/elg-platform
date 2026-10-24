<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * AntzPay 金流商配置
 *
 * @see https://example.invalid/appendix/payment-method
 */
return [
    'credentials' => [
        'API_URL' => '',
        'CLIENT_ID' => '',
        'CLIENT_SECRET' => '',
        'SERVER_PUBLIC_KEY' => '',
        'CLIENT_SYMMETRIC_KEY' => '',
        'CLIENT_PRIVATE_KEY' => '',
    ],

    'deposit' => [
        'cash_flow_id' => 111,
        'channels' => [
            [
                'currency' => 'THB',
                'name' => 'AntzPay',
                'key' => 'ANTZPAY',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
            ],
        ],
    ],

    'withdrawal' => [
        'cash_flow_id' => 112,
        'channels' => [
            [
                'currency' => 'THB',
                'type' => WithdrawalTypeEnum::BANK->value,
                'banks' => [
                    ['name' => '(THB) - Bank of Thailand', 'code' => 'BOTHTHBK'],
                    ['name' => '(THB) - Bangkok Bank', 'code' => 'BKKBTHBK'],
                    ['name' => '(THB) - Kasikornbank', 'code' => 'KASITHBK'],
                    ['name' => '(THB) - Royal Bank of Scotland', 'code' => 'ABNATHBK'],
                    ['name' => '(THB) - Krung Thai Bank', 'code' => 'KRTHTHBK'],
                    ['name' => '(THB) - JPMorgan Chase Bank', 'code' => 'CHASTHBX'],
                    ['name' => '(THB) - TMBThanachart Bank', 'code' => 'TMBKTHBK'],
                    ['name' => '(THB) - Siam Commercial Bank', 'code' => 'SICOTHBK'],
                    ['name' => '(THB) - Citibank', 'code' => 'CITITHBK'],
                    ['name' => '(THB) - Sumitomo Mitsui Banking Corporation', 'code' => 'SMBCTHBK'],
                    ['name' => '(THB) - Standard Chartered Bank', 'code' => 'SCBLTHBX'],
                    ['name' => '(THB) - CIMB Bank', 'code' => 'CIMBTHBK'],
                    ['name' => '(THB) - United Overseas Bank', 'code' => 'UOVBTHBK'],
                    ['name' => '(THB) - Bank of Ayudhya', 'code' => 'AYUDTHBK'],
                    ['name' => '(THB) - Mega International Commercial Bank', 'code' => 'ICBCTHBK'],
                    ['name' => '(THB) - Bank of America', 'code' => 'BOFATH2X'],
                    ['name' => '(THB) - Credit Agricole CIB', 'code' => 'CRLYTHBK'],
                    ['name' => '(THB) - Government Savings Bank', 'code' => 'GSBATHBK'],
                    ['name' => '(THB) - HSBC', 'code' => 'HSBCTHBK'],
                    ['name' => '(THB) - Deutsche Bank', 'code' => 'DEUTTHBK'],
                    ['name' => '(THB) - Government Housing Bank', 'code' => 'GHBNTHBK'],
                    ['name' => '(THB) - BAAC', 'code' => 'BAABTHBK'], // Bank for Agriculture and Agricultural Cooperatives
                    ['name' => '(THB) - Mizuho Bank', 'code' => 'MHCBTHBK'],
                    ['name' => '(THB) - BNP Paribas', 'code' => 'BNPATHBK'],
                    ['name' => '(THB) - Islamic Bank of Thailand', 'code' => 'ISBCTHBK'],
                    ['name' => '(THB) - TISCO Bank', 'code' => 'TISCOBK'],
                    ['name' => '(THB) - Kiatnakin Bank', 'code' => 'KIATTHBK'],
                    ['name' => '(THB) - ICBC', 'code' => 'ICBKTHBK'],
                    ['name' => '(THB) - Thai Credit Bank', 'code' => 'TCRBTHBK'],
                    ['name' => '(THB) - Land and Houses Bank', 'code' => 'LHBATHBK'],
                    ['name' => '(THB) - Thailand National ID Card', 'code' => 'NATID'],
                    ['name' => '(THB) - Thailand Mobile Phone', 'code' => 'MSISDN'],
                ],
            ],
        ],
    ],
];
