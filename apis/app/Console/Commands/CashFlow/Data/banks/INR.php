<?php

declare(strict_types=1);

use App\Enums\StandardBankTypeEnum;

/**
 * INR (印度盧比) 標準銀行配置
 *
 * 用於統一各金流商的銀行名稱
 *
 * 注意：印度銀行系統使用 IFSC (Indian Financial System Code) 作為識別碼
 * IFSC 格式：前 4 位是銀行代碼，第 5 位是 0，後 6 位是分行代碼
 *
 * 合併版：本檔同時承擔「名稱正規化」（standard_name / aliases / keywords，供
 * BankMismatchAuditor 與 StandardBankNameHelper 消費）與「標準銀行身分」（code /
 * name / swift，由 SyncStandardBanksSeeder 直接讀本檔隨部署 upsert 至 standard_banks）。
 * - code：一律 `{幣別}_{名稱}` 自訂語意碼，必以幣別前綴開頭（與 currency_id 互為校驗）
 * - swift：純資訊屬性（可空、可跨列重複，如 DBS 與 POSB 共用同一 BIC），不作任何鍵
 * - type：未標＝銀行帳號（StandardBankTypeEnum::BANK）；電子錢包與手機號 proxy 收款
 *   （DuitNow / PayNow）標 E_WALLET
 * - 條目順序有語意（先出現者優先命中），不可依字母或 code 重排
 */
return [
    // IFSC (通用銀行轉帳系統)
    [
        'code' => 'INR_IFSC',
        'standard_name' => 'IFSC Transfer',
        'name' => 'IFSC Transfer',
        'swift' => null,
        'aliases' => [
            'IFSC',
            'IFSC Transfer',
            'Indian Financial System Code',
        ],
        'keywords' => ['indian financial system code'],
        'description' => '印度金融系統代碼轉帳（支援所有印度銀行）',
    ],

    // State Bank of India (印度國家銀行)
    [
        'code' => 'INR_STATEBANKOFINDIA',
        'standard_name' => 'State Bank of India',
        'name' => 'State Bank of India',
        'swift' => null,
        'aliases' => [
            'State Bank of India',
            'SBI',
        ],
        'keywords' => ['state bank of india', 'sbi'],
        'ifsc_prefix' => 'SBIN',
    ],

    // HDFC Bank
    [
        'code' => 'INR_HDFC',
        'standard_name' => 'HDFC Bank',
        'name' => 'HDFC Bank',
        'swift' => null,
        'aliases' => [
            'HDFC Bank',
            'HDFC',
            'Housing Development Finance Corporation Bank',
        ],
        'keywords' => ['hdfc', 'housing development finance'],
        'ifsc_prefix' => 'HDFC',
    ],

    // ICICI Bank
    [
        'code' => 'INR_ICICI',
        'standard_name' => 'ICICI Bank',
        'name' => 'ICICI Bank',
        'swift' => null,
        'aliases' => [
            'ICICI Bank',
            'ICICI',
            'Industrial Credit and Investment Corporation of India Bank',
        ],
        'keywords' => ['icici', 'industrial credit investment'],
        'ifsc_prefix' => 'ICIC',
    ],

    // Axis Bank
    [
        'code' => 'INR_AXIS',
        'standard_name' => 'Axis Bank',
        'name' => 'Axis Bank',
        'swift' => null,
        'aliases' => [
            'Axis Bank',
            'Axis',
        ],
        'keywords' => ['axis', 'axis bank'],
        'ifsc_prefix' => 'UTIB',
    ],

    // Punjab National Bank
    [
        'code' => 'INR_PUNJABNATIONAL',
        'standard_name' => 'Punjab National Bank',
        'name' => 'Punjab National Bank',
        'swift' => null,
        'aliases' => [
            'Punjab National Bank',
            'PNB',
            'Oriental Bank of Commerce', // 2020-04-01 併入 PNB
            'United Bank of India', // 2020-04-01 併入 PNB；不加會被 Bank of India 的關鍵字比中
        ],
        'keywords' => ['punjab national bank', 'pnb'],
        'ifsc_prefix' => 'PUNB',
    ],

    // Bank of Baroda
    [
        'code' => 'INR_BANKOFBARODA',
        'standard_name' => 'Bank of Baroda',
        'name' => 'Bank of Baroda',
        'swift' => null,
        'aliases' => [
            'Bank of Baroda',
            'BOB',
            'Vijaya Bank', // 2019-04-01 併入 Bank of Baroda
            'Dena Bank', // 2019-04-01 併入 Bank of Baroda
        ],
        'keywords' => ['bank of baroda', 'bob'],
        'ifsc_prefix' => 'BARB',
    ],

    // Canara Bank
    [
        'code' => 'INR_CANARA',
        'standard_name' => 'Canara Bank',
        'name' => 'Canara Bank',
        'swift' => null,
        'aliases' => [
            'Canara Bank',
            'Syndicate Bank', // 2020-04-01 併入 Canara Bank
        ],
        'keywords' => ['canara bank'],
        'ifsc_prefix' => 'CNRB',
    ],

    // Central Bank of India、South Indian Bank、City Union Bank 必須排在 Union Bank of India、
    // Bank of India、Indian Bank 之前：helper 的關鍵字分數上限 85，兩家同時命中時先出現者勝，
    // 「Central Bank of India Ltd」會被 'bank of india' 搶走

    // Central Bank of India
    [
        'code' => 'INR_CENTRALBANKOFINDIA',
        'standard_name' => 'Central Bank of India',
        'name' => 'Central Bank of India',
        'swift' => null,
        'aliases' => [
            'Central Bank of India',
            'CBI',
        ],
        'keywords' => ['central bank of india', 'cbi'],
        'ifsc_prefix' => 'CBIN',
    ],

    // South Indian Bank
    [
        'code' => 'INR_SOUTHINDIAN',
        'standard_name' => 'South Indian Bank',
        'name' => 'South Indian Bank',
        'swift' => null,
        'aliases' => [
            'South Indian Bank',
            'SIB',
        ],
        'keywords' => ['south indian bank', 'sib'],
        'ifsc_prefix' => 'SIBL',
    ],

    // City Union Bank
    [
        'code' => 'INR_CITYUNION',
        'standard_name' => 'City Union Bank',
        'name' => 'City Union Bank',
        'swift' => null,
        'aliases' => [
            'City Union Bank',
            'CUB',
        ],
        'keywords' => ['city union bank', 'cub'],
        'ifsc_prefix' => 'CIUB',
    ],

    // Union Bank of India
    [
        'code' => 'INR_UNIONBANKOFINDIA',
        'standard_name' => 'Union Bank of India',
        'name' => 'Union Bank of India',
        'swift' => null,
        'aliases' => [
            'Union Bank of India',
            'Union Bank',
            'Andhra Bank', // 2020-04-01 併入 Union Bank of India
            'Corporation Bank', // 2020-04-01 併入 Union Bank of India
        ],
        'keywords' => ['union bank of india', 'union bank'],
        'ifsc_prefix' => 'UBIN',
    ],

    // Bank of India
    [
        'code' => 'INR_BANKOFINDIA',
        'standard_name' => 'Bank of India',
        'name' => 'Bank of India',
        'swift' => null,
        'aliases' => [
            'Bank of India',
            'BOI',
        ],
        'keywords' => ['bank of india', 'boi'],
        'ifsc_prefix' => 'BKID',
    ],

    // Indian Bank
    [
        'code' => 'INR_INDIAN',
        'standard_name' => 'Indian Bank',
        'name' => 'Indian Bank',
        'swift' => null,
        'aliases' => [
            'Indian Bank',
            'Allahabad Bank', // 2020-04-01 併入 Indian Bank
        ],
        'keywords' => ['indian bank'],
        'ifsc_prefix' => 'IDIB',
    ],

    // Indian Overseas Bank
    [
        'code' => 'INR_INDIANOVERSEAS',
        'standard_name' => 'Indian Overseas Bank',
        'name' => 'Indian Overseas Bank',
        'swift' => null,
        'aliases' => [
            'Indian Overseas Bank',
            'IOB',
        ],
        'keywords' => ['indian overseas bank', 'iob'],
        'ifsc_prefix' => 'IOBA',
    ],

    // UCO Bank
    [
        'code' => 'INR_UCO',
        'standard_name' => 'UCO Bank',
        'name' => 'UCO Bank',
        'swift' => null,
        'aliases' => [
            'UCO Bank',
            'UCO',
        ],
        'keywords' => ['uco bank', 'uco'],
        'ifsc_prefix' => 'UCBA',
    ],

    // Bank of Maharashtra
    [
        'code' => 'INR_BANKOFMAHARASHTRA',
        'standard_name' => 'Bank of Maharashtra',
        'name' => 'Bank of Maharashtra',
        'swift' => null,
        'aliases' => [
            'Bank of Maharashtra',
            'BOM',
        ],
        'keywords' => ['bank of maharashtra', 'bom'],
        'ifsc_prefix' => 'MAHB',
    ],

    // Punjab & Sind Bank
    [
        'code' => 'INR_PUNJABSIND',
        'standard_name' => 'Punjab & Sind Bank',
        'name' => 'Punjab & Sind Bank',
        'swift' => null,
        'aliases' => [
            'Punjab & Sind Bank',
            'PSB',
        ],
        'keywords' => ['punjab sind bank', 'psb'],
        'ifsc_prefix' => 'PSIB',
    ],

    // Kotak Mahindra Bank
    [
        'code' => 'INR_KOTAKMAHINDRA',
        'standard_name' => 'Kotak Mahindra Bank',
        'name' => 'Kotak Mahindra Bank',
        'swift' => null,
        'aliases' => [
            'Kotak Mahindra Bank',
            'Kotak Bank',
            'Kotak',
        ],
        'keywords' => ['kotak mahindra', 'kotak bank'],
        'ifsc_prefix' => 'KKBK',
    ],

    // IndusInd Bank
    [
        'code' => 'INR_INDUSIND',
        'standard_name' => 'IndusInd Bank',
        'name' => 'IndusInd Bank',
        'swift' => null,
        'aliases' => [
            'IndusInd Bank',
            'IndusInd',
        ],
        'keywords' => ['indusind', 'indusind bank'],
        'ifsc_prefix' => 'INDB',
    ],

    // Yes Bank
    [
        'code' => 'INR_YES',
        'standard_name' => 'Yes Bank',
        'name' => 'Yes Bank',
        'swift' => null,
        'aliases' => [
            'Yes Bank',
            'YES',
        ],
        'keywords' => ['yes bank', 'yes'],
        'ifsc_prefix' => 'YESB',
    ],

    // IDFC First Bank
    [
        'code' => 'INR_IDFCFIRST',
        'standard_name' => 'IDFC First Bank',
        'name' => 'IDFC First Bank',
        'swift' => null,
        'aliases' => [
            'IDFC First Bank',
            'IDFC Bank',
            'IDFC',
        ],
        'keywords' => ['idfc first bank', 'idfc bank', 'idfc'],
        'ifsc_prefix' => 'IDFB',
    ],

    // Federal Bank
    [
        'code' => 'INR_FEDERAL',
        'standard_name' => 'Federal Bank',
        'name' => 'Federal Bank',
        'swift' => null,
        'aliases' => [
            'Federal Bank',
        ],
        'keywords' => ['federal bank'],
        'ifsc_prefix' => 'FDRL',
    ],

    // Karur Vysya Bank
    [
        'code' => 'INR_KARURVYSYA',
        'standard_name' => 'Karur Vysya Bank',
        'name' => 'Karur Vysya Bank',
        'swift' => null,
        'aliases' => [
            'Karur Vysya Bank',
            'KVB',
        ],
        'keywords' => ['karur vysya', 'kvb'],
        'ifsc_prefix' => 'KVBL',
    ],

    // Bandhan Bank
    [
        'code' => 'INR_BANDHAN',
        'standard_name' => 'Bandhan Bank',
        'name' => 'Bandhan Bank',
        'swift' => null,
        'aliases' => [
            'Bandhan Bank',
        ],
        'keywords' => ['bandhan bank'],
        'ifsc_prefix' => 'BDBL',
    ],

    // RBL Bank
    [
        'code' => 'INR_RBL',
        'standard_name' => 'RBL Bank',
        'name' => 'RBL Bank',
        'swift' => null,
        'aliases' => [
            'RBL Bank',
            'Ratnakar Bank',
        ],
        'keywords' => ['rbl bank', 'ratnakar bank'],
        'ifsc_prefix' => 'RATN',
    ],

    // Jammu & Kashmir Bank
    [
        'code' => 'INR_JAMMUKASHMIR',
        'standard_name' => 'Jammu & Kashmir Bank',
        'name' => 'Jammu & Kashmir Bank',
        'swift' => null,
        'aliases' => [
            'Jammu & Kashmir Bank',
            'J&K Bank',
        ],
        'keywords' => ['jammu kashmir bank', 'jk bank'],
        'ifsc_prefix' => 'JAKA',
    ],

    // DCB Bank
    [
        'code' => 'INR_DCB',
        'standard_name' => 'DCB Bank',
        'name' => 'DCB Bank',
        'swift' => null,
        'aliases' => [
            'DCB Bank',
            'Development Credit Bank',
        ],
        'keywords' => ['dcb bank', 'development credit bank'],
        'ifsc_prefix' => 'DCBL',
    ],

    // HSBC India
    [
        'code' => 'INR_HSBCINDIA',
        'standard_name' => 'HSBC Bank India',
        'name' => 'HSBC Bank India',
        'swift' => null,
        'aliases' => [
            'HSBC Bank India',
            'HSBC India',
            'HSBC',
        ],
        'keywords' => ['hsbc', 'hongkong shanghai banking'],
        'ifsc_prefix' => 'HSBC',
    ],

    // Standard Chartered Bank India
    [
        'code' => 'INR_STANDARDCHARTEREDINDIA',
        'standard_name' => 'Standard Chartered Bank India',
        'name' => 'Standard Chartered Bank India',
        'swift' => null,
        'aliases' => [
            'Standard Chartered Bank India',
            'Standard Chartered',
            'SCB',
        ],
        'keywords' => ['standard chartered', 'scb'],
        'ifsc_prefix' => 'SCBL',
    ],

    // Citibank India：零售業務 2024-07 已移轉 Axis（Citi IFSC 停用、改用 Axis IFSC），
    // 企業金融仍在，不是整家合併，所以條目保留、不併進 Axis
    [
        'code' => 'INR_CITIBANKINDIA',
        'standard_name' => 'Citibank India',
        'name' => 'Citibank India',
        'swift' => null,
        'aliases' => [
            'Citibank India',
            'Citibank',
            'Citi',
        ],
        'keywords' => ['citibank', 'citi'],
        'ifsc_prefix' => 'CITI',
    ],

    // Deutsche Bank India
    [
        'code' => 'INR_DEUTSCHEINDIA',
        'standard_name' => 'Deutsche Bank India',
        'name' => 'Deutsche Bank India',
        'swift' => null,
        'aliases' => [
            'Deutsche Bank India',
            'Deutsche Bank',
        ],
        'keywords' => ['deutsche bank'],
        'ifsc_prefix' => 'DEUT',
    ],

    // Barclays Bank India
    [
        'code' => 'INR_BARCLAYSINDIA',
        'standard_name' => 'Barclays Bank India',
        'name' => 'Barclays Bank India',
        'swift' => null,
        'aliases' => [
            'Barclays Bank India',
            'Barclays',
        ],
        'keywords' => ['barclays', 'barclays bank'],
        'ifsc_prefix' => 'BARC',
    ],

    // DBS Bank India
    [
        'code' => 'INR_DBSINDIA',
        'standard_name' => 'DBS Bank India',
        'name' => 'DBS Bank India',
        'swift' => null,
        'aliases' => [
            'DBS Bank India',
            'DBS',
            'Lakshmi Vilas Bank', // 2020-11-27 併入 DBS Bank India
        ],
        'keywords' => ['dbs', 'development bank singapore'],
        'ifsc_prefix' => 'DBSS',
    ],

    // Paytm Payments Bank
    [
        'code' => 'INR_PAYTMPAYMENTS',
        'standard_name' => 'Paytm Payments Bank',
        'name' => 'Paytm Payments Bank',
        'swift' => null,
        'aliases' => [
            'Paytm Payments Bank',
            'Paytm Bank',
        ],
        'keywords' => ['paytm payments bank', 'paytm bank'],
        'ifsc_prefix' => 'PYTM',
    ],

    // Airtel Payments Bank
    [
        'code' => 'INR_AIRTELPAYMENTS',
        'standard_name' => 'Airtel Payments Bank',
        'name' => 'Airtel Payments Bank',
        'swift' => null,
        'aliases' => [
            'Airtel Payments Bank',
            'Airtel Bank',
        ],
        'keywords' => ['airtel payments bank', 'airtel bank'],
        'ifsc_prefix' => 'AIRP',
    ],

    // India Post Payments Bank
    [
        'code' => 'INR_INDIAPOSTPAYMENTS',
        'standard_name' => 'India Post Payments Bank',
        'name' => 'India Post Payments Bank',
        'swift' => null,
        'aliases' => [
            'India Post Payments Bank',
            'IPPB',
        ],
        'keywords' => ['india post payments bank', 'ippb'],
        'ifsc_prefix' => 'IPOS',
    ],

    // Fino Payments Bank
    [
        'code' => 'INR_FINOPAYMENTS',
        'standard_name' => 'Fino Payments Bank',
        'name' => 'Fino Payments Bank',
        'swift' => null,
        'aliases' => [
            'Fino Payments Bank',
            'Fino Bank',
        ],
        'keywords' => ['fino payments bank', 'fino bank'],
        'ifsc_prefix' => 'FINO',
    ],

    // Jio Payments Bank
    [
        'code' => 'INR_JIOPAYMENTS',
        'standard_name' => 'Jio Payments Bank',
        'name' => 'Jio Payments Bank',
        'swift' => null,
        'aliases' => [
            'Jio Payments Bank',
            'Jio Bank',
        ],
        'keywords' => ['jio payments bank', 'jio bank'],
        'ifsc_prefix' => 'JIOP',
    ],

    // NSDL Payments Bank
    [
        'code' => 'INR_NSDLPAYMENTS',
        'standard_name' => 'NSDL Payments Bank',
        'name' => 'NSDL Payments Bank',
        'swift' => null,
        'aliases' => [
            'NSDL Payments Bank',
            'NSDL Bank',
        ],
        'keywords' => ['nsdl payments bank', 'nsdl bank'],
        'ifsc_prefix' => 'NSPB',
    ],
];
