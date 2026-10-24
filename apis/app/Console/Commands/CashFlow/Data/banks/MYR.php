<?php

declare(strict_types=1);

use App\Enums\StandardBankTypeEnum;

/**
 * MYR 標準銀行配置
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
    // 馬來西亞主要銀行
    // ⚠️ Corporate Account 條目必須排在對應一般銀行之前，確保 matchAgainstConfig 優先命中
    [
        'code' => 'MYR_MAYBANK_CORP',
        'standard_name' => '(MYR) - Maybank (Corporate Account)',
        'name' => 'Maybank (Corporate Account)',
        'swift' => 'MBBEMYKL',
        'aliases' => [
            'Maybank (Corporate Account)',
            'Maybank Corporate Account',
            'Maybank Corporate',
            'Maybank Business Account',
            'Maybank Business',
            'Maybank Biz',
            'MAYB_BIZ',
            'MBB Corporate',
            'MAYBANK2E', // Maybank 的企業網銀平台（零售網銀是 Maybank2u）
        ],
        'keywords' => [
            'maybank corporate',
            'maybank business',
            'maybank biz',
            'mbb corporate',
            'mbb biz',
            'maybank2e',
        ],
    ],
    [
        'code' => 'MYR_MAYBANK',
        'standard_name' => '(MYR) - Maybank',
        'name' => 'Maybank',
        'swift' => 'MBBEMYKL',
        'aliases' => [
            'Maybank',
            'May Bank',
            'Malayan Banking',
            'Malayan Banking Berhad',
            'MBB',
            'MAYBANK2U',
            'Maybank2u',
            'Malayan',
            'MB',
        ],
        'keywords' => [
            'maybank',
            'malayan',
            'maybank2u',
        ],
    ],
    [
        'code' => 'MYR_CIMB_CORP',
        'standard_name' => '(MYR) - CIMB Bank (Corporate Account)',
        'name' => 'CIMB Bank (Corporate Account)',
        'swift' => 'CIBBMYKL',
        'aliases' => [
            'CIMB Bank (Corporate Account)',
            // 原重複條目 (MYR) - CIMB (Corporate Account) 已併入本條（target/op_tickets#2922 順手修）
            'CIMB (Corporate Account)',
            '(MYR) - CIMB (Corporate Account)',
            'CIMB Corporate Account',
            'CIMB Corporate',
            'CIMB Business Account',
            'CIMB Business',
            'CIMB Biz',
            'CIMB_BIZ',
        ],
        'keywords' => [
            'cimb corporate',
            'cimb business',
            'cimb biz',
        ],
    ],
    [
        'code' => 'MYR_CIMB',
        'standard_name' => '(MYR) - CIMB Bank',
        'name' => 'CIMB Bank',
        'swift' => 'CIBBMYKL',
        'aliases' => [
            'CIMB',
            'CIMB Bank',
            'CIMBBank',
            'CIMB Bank Berhad',
            'CIMB Clicks',
            'CIMB Islamic',
            'CIMBI',
        ],
        'keywords' => [
            'cimb',
            'cimb bank',
        ],
    ],
    [
        'code' => 'MYR_PUBLIC',
        'standard_name' => '(MYR) - Public Bank',
        'name' => 'Public Bank',
        'swift' => 'PBBEMYKL',
        'aliases' => [
            'Public Bank',
            'PublicBank',
            'Public Bank Berhad',
            'PBB',
            'PBE',
            'Public Islamic',
            'Publicbank',
            'PB',
        ],
        'keywords' => [
            'public',
            'pbb',
            'publicbank',
            'public bank',
        ],
    ],
    [
        'code' => 'MYR_RHB',
        'standard_name' => '(MYR) - RHB Bank',
        'name' => 'RHB Bank',
        'swift' => 'RHBBMYKL',
        'aliases' => [
            'RHB',
            'RHB Bank',
            'RHBBank',
            'RHB Bank Berhad',
            'RHB Islamic',
            'RHB Now',
            'RHBNow',
            'RHB Invest',
        ],
        'keywords' => [
            'rhb',
            'rhb bank',
        ],
    ],
    [
        'code' => 'MYR_HONGLEONG',
        'standard_name' => '(MYR) - Hong Leong Bank',
        'name' => 'Hong Leong Bank',
        'swift' => 'HLBBMYKL',
        'aliases' => [
            'Hong Leong Bank',
            'HongLeongBank',
            'Hong Leong',
            'HongLeong',
            'HLB',
            'HLBB',
            'Hong Leong Islamic',
            'HL',
        ],
        'keywords' => [
            'hong leong',
            'hlb',
            'hongleong',
        ],
    ],
    [
        'code' => 'MYR_AMBANK',
        'standard_name' => '(MYR) - AmBank',
        'name' => 'AmBank',
        'swift' => 'ARBKMYKL',
        'aliases' => [
            'AmBank',
            'Am Bank',
            'AmBank (M) Berhad',
            'AMMB',
            'AMBANK',
            'Am Islamic',
            'Ambank',
            'Am',
            'AmBank Berhad',
        ],
        'keywords' => [
            'ambank',
            'ammb',
            'am bank',
        ],
    ],
    [
        'code' => 'MYR_AFFIN',
        'standard_name' => '(MYR) - Affin Bank',
        'name' => 'Affin Bank',
        'swift' => 'PHBMMYKL',
        'aliases' => [
            'Affin Bank',
            'AffinBank',
            'Affin Bank Berhad',
            'ABB',
            'AFFINBANK',
            'AFFIN',
            'Affin Islamic',
            'Affin Islamic Bank', // 生產 banks.name 實況（VWorld 586、Me77 6／39／104），既有決策已將伊斯蘭窗口併入主行
            'Affin Shariah',
        ],
        'keywords' => [
            'affin',
            'affin bank',
        ],
    ],
    [
        'code' => 'MYR_ALLIANCE',
        'standard_name' => '(MYR) - Alliance Bank',
        'name' => 'Alliance Bank',
        'swift' => 'MFBBMYKL',
        'aliases' => [
            'Alliance Bank',
            'AllianceBank',
            'Alliance Bank Malaysia Berhad',
            'ABMB',
            'ALLIANCE',
            'Alliance Islamic',
            'Alliancebank',
        ],
        'keywords' => [
            'alliance',
            'alliance bank',
        ],
    ],
    [
        'code' => 'MYR_BANKISLAM',
        // 主名不帶 Malaysia（RB 2026-08-27 拍板）；舊主名 Bank Islam Malaysia 降為既有 alias 承接
        'standard_name' => '(MYR) - Bank Islam',
        'name' => 'Bank Islam',
        'swift' => 'BIMBMYKL',
        'aliases' => [
            'Bank Islam',
            'BankIslam',
            'Bank Islam Malaysia',
            'Bank Islam Malaysia Berhad',
            'BIMB',
            'BANKISLAM',
            'ISLAM',
            'Islamic Bank Malaysia',
            // Me77 banks.name 實況；該列三方代碼 MYIS 與 FPay 銀行碼 251，在金流商清單都是 Bank Islam Malaysia
            'Malaysia Islamic Bank',
        ],
        // 裸 'islam'／'islamic' 會子字串命中 Affin Islamic 等其他銀行的伊斯蘭窗口，只留片語
        'keywords' => [
            'bank islam',
            'bimb',
        ],
    ],
    [
        'code' => 'MYR_MUAMALAT',
        'standard_name' => '(MYR) - Bank Muamalat',
        'name' => 'Bank Muamalat',
        'swift' => 'BMMBMYKL',
        'aliases' => [
            'Bank Muamalat',
            'BankMuamalat',
            'Bank Muamalat Malaysia Berhad',
            'BMMB',
            'Muamalat',
            'Bank Muamalat Malaysia',
        ],
        'keywords' => [
            'muamalat',
            'bmmb',
        ],
    ],
    [
        'code' => 'MYR_BANKRAKYAT',
        'standard_name' => '(MYR) - Bank Rakyat',
        'name' => 'Bank Rakyat',
        'swift' => 'BKRMMYKL',
        'aliases' => [
            'Bank Rakyat',
            'BankRakyat',
            'BANKRAKYAT',
            'RAKYAT',
            'BR',
            'People\'s Bank',
            'Bank Kerjasama Rakyat',
        ],
        'keywords' => [
            'rakyat',
            'bank rakyat',
        ],
    ],
    [
        'code' => 'MYR_BSN',
        'standard_name' => '(MYR) - Bank Simpanan Nasional',
        'name' => 'Bank Simpanan Nasional',
        'swift' => 'BSNAMYK1',
        'aliases' => [
            'Bank Simpanan Nasional',
            'BSN',
            'Simpanan Nasional',
            'BankSimpananNasional',
            'National Savings Bank',
            '(MYR) - BSN',
            '(MYR) - Bank Simpanan National',
            'Bank Simpanan National',
        ],
        'keywords' => [
            'bsn',
            'simpanan',
            'nasional',
            'simpanan nasional',
        ],
    ],

    // 外資銀行
    [
        'code' => 'MYR_HSBC',
        'standard_name' => '(MYR) - HSBC Bank',
        'name' => 'HSBC Bank',
        'swift' => 'HBMBMYKL',
        'aliases' => [
            'HSBC',
            'HSBC Bank',
            'HSBCBank',
            'HSBC Bank Malaysia',
            'HSBC Bank Malaysia Berhad',
            'HSBCM',
            'HSBC MY',
            'Hongkong and Shanghai Banking Corporation',
        ],
        'keywords' => [
            'hsbc',
            'hsbc bank',
        ],
    ],
    [
        'code' => 'MYR_CITIBANK',
        'standard_name' => '(MYR) - Citibank',
        'name' => 'Citibank',
        'swift' => 'CITIMYKL',
        'aliases' => [
            'Citibank',
            'Citi Bank',
            'Citibank Berhad',
            'CITI',
            'CITIM',
            'Citibank Malaysia',
        ],
        'keywords' => [
            'citi',
            'citibank',
        ],
    ],
    [
        'code' => 'MYR_STANDARDCHARTERED',
        'standard_name' => '(MYR) - Standard Chartered Bank',
        'name' => 'Standard Chartered Bank',
        'swift' => 'SCBLMYKX',
        'aliases' => [
            'Standard Chartered',
            'Standard Chartered Bank',
            'StandardChartered',
            'Standard Chartered Bank Malaysia Berhad',
            'STCM',
            'SCB',
            'StdChartered',
        ],
        'keywords' => [
            'standard chartered',
            'scb',
            'stcm',
            'chartered',
        ],
    ],
    [
        'code' => 'MYR_OCBC',
        'standard_name' => '(MYR) - OCBC Bank',
        'name' => 'OCBC Bank',
        'swift' => 'OCBCMYKL',
        'aliases' => [
            'OCBC',
            'OCBC Bank',
            'OCBCBank',
            'OCBC Bank (Malaysia) Berhad',
            'OCBCM',
            'OCBC Malaysia',
            'Oversea-Chinese Banking Corporation',
        ],
        'keywords' => [
            'ocbc',
            'ocbc bank',
        ],
    ],
    [
        'code' => 'MYR_UOB',
        'standard_name' => '(MYR) - United Overseas Bank',
        'name' => 'United Overseas Bank',
        'swift' => 'UOVBMYKL',
        'aliases' => [
            'UOB',
            'United Overseas Bank',
            'UnitedOverseasBank',
            'United Overseas Bank (Malaysia) Bhd',
            'UOBM',
            'UOB Malaysia',
            'UOB Bank',
        ],
        'keywords' => [
            'uob',
            'united overseas',
        ],
    ],

    // 數位銀行
    [
        'code' => 'MYR_AGROBANK',
        'standard_name' => '(MYR) - AGRO Bank',
        'name' => 'Agrobank',
        'swift' => 'AGOBMYKL',
        'aliases' => [
            'AGRO Bank',
            'AGROBank',
            'Agro Bank',
            'AgroBank',
            'AGROBANK',
            'Bank Pertanian Malaysia',
            'Agricultural',
            'Argo Bank',
            '(MYR) - Argo Bank',
        ],
        'keywords' => [
            'agro',
            'pertanian',
            'agricultural',
            'agrobank',
        ],
    ],
    [
        'code' => 'MYR_AEON',
        'standard_name' => '(MYR) - AEON Bank',
        'name' => 'AEON Bank',
        'swift' => null,
        'aliases' => [
            'AEON Bank',
            'AEONBank',
            'Aeon Bank',
            'AeonBank',
            'AEONBANK',
            'AEON',
        ],
        'keywords' => [
            'aeon',
            'aeon bank',
        ],
    ],
    [
        'code' => 'MYR_BOOSTBANK',
        'standard_name' => '(MYR) - Boost Bank',
        'name' => 'Boost Bank',
        'swift' => null,
        'aliases' => [
            'Boost Bank',
            'BoostBank',
            'BOOSTBANK',
            'Boost Fintech',
        ],
        'keywords' => [
            'boost bank',
        ],
    ],
    [
        'code' => 'MYR_GXBANK',
        'standard_name' => '(MYR) - GX Bank',
        'name' => 'GX Bank',
        'swift' => null,
        'aliases' => [
            'GX Bank',
            'GXBank',
            'GX',
            'GXS Bank',
            'GXS',
            'GX Bank Malaysia',
        ],
        'keywords' => [
            'gx',
            'gxbank',
            'gxs',
        ],
    ],

    // 電子錢包
    [
        'code' => 'MYR_TNGWALLET',
        'standard_name' => '(MYR) - Touch n GO',
        'name' => 'Touch n Go eWallet',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'Touch n GO',
            'TouchnGO',
            'Touch \'n Go',
            'TNG',
            'TNGQR',
            'Touch and Go',
            'TouchandGo',
            'TnG',
            'Touch n GO eWallet',
            'Touch\'n Go eWallet',
            'Touch N Go eWallet',
            'TnG eWallet',
            '(MYR) - Touch N Go eWallet',
            '(MYR) - Touch\'n Go eWallet',
        ],
        'keywords' => [
            'touch',
            'tng',
            'touch n go',
        ],
    ],

    // 其他銀行
    [
        'code' => 'MYR_ALRAJHI',
        'standard_name' => '(MYR) - Alrajhi Bank',
        'name' => 'Al Rajhi Bank',
        'swift' => 'RJHIMYKL',
        'aliases' => [
            'Alrajhi Bank',
            'AlrajhiBank',
            'Al Rajhi Bank',
            'Al-Rajhi Bank',
            'Alrajhi',
            'Al Rajhi',
            'Alrajhi Banking',
            'Al-Rajhi Malaysia',
            'Al-Rajhi Bank Malaysia',
            'Al Rajhi Banking & Investment Corporation (Malaysia)',
        ],
        'keywords' => [
            'alrajhi',
            'rajhi',
        ],
    ],
    [
        'code' => 'MYR_KFH',
        'standard_name' => '(MYR) - Kuwait Finance House',
        'name' => 'Kuwait Finance House',
        'swift' => 'KFHOMYKL',
        'aliases' => [
            'Kuwait Finance House',
            'KuwaitFinanceHouse',
            'KFH',
            'KFH Malaysia',
            'KFH Bank',
        ],
        'keywords' => [
            'kuwait',
            'kuwait finance',
        ],
    ],
    // Bank of America
    [
        'code' => 'MYR_BANKOFAMERICA',
        'standard_name' => '(MYR) - Bank of America',
        'name' => 'Bank of America',
        'swift' => null,
        'aliases' => [
            'Bank of America, National Association',
            'Bank of America',
            'BOA',
        ],
        'keywords' => ['bank of america', 'boa'],
    ],
    // Bangkok Bank
    [
        'code' => 'MYR_BANGKOK',
        'standard_name' => '(MYR) - Bangkok Bank Malaysia',
        'name' => 'Bangkok Bank Malaysia',
        'swift' => 'BKKBMYKL',
        'aliases' => [
            'Bangkok Bank',
            'Bangkok Bank Malaysia',
            'Bangkok Bank Berhad',
        ],
        'keywords' => ['bangkok bank', 'bangkok'],
    ],
    // ICBC
    [
        'code' => 'MYR_ICBC',
        'standard_name' => '(MYR) - ICBC',
        'name' => 'ICBC',
        'swift' => null,
        'aliases' => [
            'ICBC Bank',
            'ICBC',
            'Industrial and Commercial Bank of China',
            'Industrial & Commercial Bank of China',
            'Industrial & Commercial Bank of China (Malaysia) Berhad',
            'ICBC Malaysia',
            '(MYR) - Industrial & Commercial Bank of China',
        ],
        'keywords' => ['icbc', 'industrial commercial bank china'],
    ],
    // Deutsche Bank
    [
        'code' => 'MYR_DEUTSCHE',
        'standard_name' => '(MYR) - Deutsche Bank',
        'name' => 'Deutsche Bank',
        'swift' => null,
        'aliases' => [
            'Deutsche Bank',
        ],
        'keywords' => ['deutsche bank'],
    ],
    [
        'code' => 'MYR_BNPPARIBAS',
        'standard_name' => '(MYR) - Bank BNP Paribas',
        'name' => 'Bank BNP Paribas',
        'swift' => null,
        'aliases' => [
            'Bank BNP Paribas',
            'BNP Paribas',
            'BNPParibas',
            'Bank BNP',
            'BNP Paribas Malaysia',
            'BNP Paribas Malaysia Berhad',
        ],
        'keywords' => [
            'bnp',
            'paribas',
        ],
    ],

    // === 補 Task 1.8 (audit-feed) — 新增條目 ===

    // MBSB Bank (Malaysia Building Society Berhad)
    [
        'code' => 'MYR_MBSB',
        'standard_name' => '(MYR) - MBSB Bank',
        'name' => 'MBSB Bank',
        'swift' => null,
        'aliases' => [
            'MBSB Bank',
            'MBSB',
            'MBSB Bank Berhad',
            'Malaysia Building Society',
            'Malaysia Building Society Berhad',
            '(MYR) - MBSB Bank',
        ],
        'keywords' => [
            'mbsb',
            'malaysia building society',
        ],
    ],

    // Bank of China (Malaysia) Berhad
    [
        'code' => 'MYR_BANKOFCHINA',
        'standard_name' => '(MYR) - Bank of China',
        'name' => 'Bank of China',
        'swift' => null,
        'aliases' => [
            'Bank of China',
            'BankOfChina',
            'BOC',
            'Bank of China Malaysia',
            'Bank of China (Malaysia) Berhad',
            'BOCM',
        ],
        'keywords' => [
            'bank of china',
            'boc',
        ],
    ],

    // J.P. Morgan Chase Bank
    [
        'code' => 'MYR_JPMORGANCHASE',
        'standard_name' => '(MYR) - J.P. Morgan Chase Bank',
        'name' => 'J.P. Morgan Chase Bank',
        'swift' => null,
        'aliases' => [
            'J.P. Morgan Chase Bank',
            'JP Morgan Chase Bank',
            'JPMorgan Chase Bank',
            'JP Morgan',
            'J.P. Morgan',
            'JPMorgan',
            'JPMC',
            'Chase Bank',
            'J.P. Morgan Chase Bank Berhad',
        ],
        'keywords' => [
            'jp morgan',
            'jpmorgan',
            'j.p. morgan',
            'chase bank',
            'jpmc',
        ],
    ],

    // China Construction Bank (Malaysia) Berhad — 與 ICBC 不同
    [
        'code' => 'MYR_CHINACONSTRUCTIONMALAYSIA',
        'standard_name' => '(MYR) - China Construction Bank Malaysia',
        'name' => 'China Construction Bank Malaysia',
        'swift' => null,
        'aliases' => [
            'China Construction Bank Malaysia',
            'China Construction Bank (Malaysia) Berhad',
            'China Construction Bank',
            'CHINA CONST BK (M) BHD',
            'CCB Malaysia',
            'CCB',
            'MCCB',
            '(MYR) - China Construction Bank Malaysia',
        ],
        'keywords' => [
            'china construction',
            'ccb malaysia',
            'mccb',
        ],
    ],

    // Mizuho Bank (Malaysia) Berhad
    [
        'code' => 'MYR_MIZUHO',
        'standard_name' => '(MYR) - Mizuho Bank',
        'name' => 'Mizuho Bank',
        'swift' => 'MHCBMYKA',
        'aliases' => [
            'Mizuho Bank',
            'MIZUHO Bank',
            'Mizuho Bank (Malaysia) Berhad',
            'Mizuho',
            'MIZU',
        ],
        'keywords' => [
            'mizuho',
        ],
    ],

    // MUFG Bank (Malaysia) Berhad
    [
        'code' => 'MYR_MUFG',
        'standard_name' => '(MYR) - MUFG Bank',
        'name' => 'MUFG Bank',
        'swift' => null,
        'aliases' => [
            'MUFG Bank',
            'MUFG Bank (Malaysia) Berhad',
            'MUFG',
            'Mitsubishi UFJ Bank',
            'Mitsubishi UFJ Financial Group',
            '(MYR) - MUFG Bank',
        ],
        'keywords' => [
            'mufg',
            'mitsubishi ufj',
        ],
    ],

    // Sumitomo Mitsui Banking Corporation Malaysia
    [
        'code' => 'MYR_SMBC',
        'standard_name' => '(MYR) - Sumitomo Mitsui Bank',
        'name' => 'Sumitomo Mitsui Bank',
        'swift' => 'SMBCMYKL',
        'aliases' => [
            'Sumitomo Mitsui Bank',
            'Sumitomo Mitsui Banking Corporation',
            'Sumitomo Mitsui Banking Corporation Malaysia Berhad',
            'SMBC',
            'SMBC Malaysia',
            'Sumitomo Mitsui',
            'Sumitomo',
            'Sumimoto Mitsui Bank',
        ],
        'keywords' => [
            'sumitomo',
            'smbc',
        ],
    ],

    // Ryt Bank (Malaysia 數位銀行)
    [
        'code' => 'MYR_RYT',
        'standard_name' => '(MYR) - Ryt Bank',
        'name' => 'Ryt Bank',
        'swift' => null,
        'aliases' => [
            'Ryt Bank',
            'RytBank',
            'RYT BANK',
            'RYT',
            '(MYR) - Ryt Bank',
        ],
        'keywords' => [
            'ryt bank',
            'ryt',
        ],
    ],

    // Boost eWallet (與 Boost Bank 區分；Boost 集團同時經營銀行與電子錢包)
    // name 不用裸稱「Boost」——該稱呼是 Boost Bank 的既有別名，同幣別一個名稱只能屬一家
    [
        'code' => 'MYR_BOOST',
        'standard_name' => '(MYR) - Boost eWallet',
        'name' => 'Boost eWallet',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'Boost eWallet',
            'Boost Ewallet',
            'BoostEwallet',
            'Boost Wallet',
            'Boost E-Wallet',
            '(MYR) - Boost Ewallet',
            '(MYR) - Boost eWallet',
        ],
        'keywords' => [
            'boost ewallet',
            'boost wallet',
            'boost e-wallet',
        ],
    ],

    // BigPay (e-wallet by AirAsia)
    [
        'code' => 'MYR_BIGPAY',
        'standard_name' => '(MYR) - BigPay',
        'name' => 'BigPay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'BigPay',
            'Big Pay',
            'BIGPAY',
            'BigPay Malaysia',
            'BigPay e-Money Issuer',
            '(MYR) - BigPay',
        ],
        'keywords' => [
            'bigpay',
            'big pay',
        ],
    ],

    // Merchantrade (e-money / remittance)
    [
        'code' => 'MYR_MERCHANTRADE',
        'standard_name' => '(MYR) - Merchantrade',
        'name' => 'Merchantrade',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'Merchantrade',
            'Merchantrade Asia',
            'MERCHANTRADE',
            'Merchantrade Money',
            '(MYR) - Merchantrade',
        ],
        'keywords' => [
            'merchantrade',
        ],
    ],

    // Finexus (支付基礎設施商，經營 MYNT e-wallet)
    [
        'code' => 'MYR_FINEXUS',
        'standard_name' => '(MYR) - Finexus',
        'name' => 'Finexus',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'Finexus',
            'FINEXUS',
            'Finexus Cards',
            '(MYR) - Finexus',
        ],
        'keywords' => [
            'finexus',
        ],
    ],

    // GrabPay 電子錢包
    [
        'code' => 'MYR_GRABPAY',
        'standard_name' => '(MYR) - GrabPay',
        'name' => 'GrabPay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'GrabPay',
            'Grab Pay',
            'GRABPAY',
            '(MYR) - GrabPay',
        ],
        'keywords' => [
            'grabpay',
            'grab',
        ],
    ],

    // SetelPay（Petronas Setel 電子錢包）
    [
        'code' => 'MYR_SETELPAY',
        'standard_name' => '(MYR) - SetelPay',
        'name' => 'SetelPay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'SetelPay',
            'Setel',
            'Setel Pay',
            'SETELPAY',
            '(MYR) - SetelPay',
        ],
        'keywords' => [
            'setel',
        ],
    ],

    // ShopeePay 電子錢包
    [
        'code' => 'MYR_SHOPEEPAY',
        'standard_name' => '(MYR) - ShopeePay',
        'name' => 'ShopeePay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'ShopeePay',
            'Shopee Pay',
            'SHOPEEPAY',
            '(MYR) - ShopeePay',
        ],
        'keywords' => [
            'shopee',
        ],
    ],

    // ── 併自 database/data/standard_bank_codes.php：本檔原無對應的條目（置於陣列末＝命中優先權最低） ──
    // DuitNow IC（身分證號 proxy 收款）與 DuitNow（手機號 proxy）是三方出款端的兩個獨立碼
    // （EpicPay：DUITNOWIC vs DUITNOW），拆為獨立條目保留通道區分；置於 DuitNow 之前＝
    // keyword tier 先命中 IC 條目（alias 精確層本就分得開，此為保序防禦）。
    [
        'code' => 'MYR_DUITNOW_IC',
        'standard_name' => '(MYR) - DuitNow IC',
        'name' => 'DuitNow IC',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'DuitNow IC',
        ],
        'keywords' => [
            'duitnow ic',
        ],
    ],
    [
        'code' => 'MYR_DUITNOW',
        'standard_name' => '(MYR) - DuitNow',
        'name' => 'DuitNow',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'DuitNow',
        ],
        'keywords' => [
            'duitnow',
        ],
    ],
];
