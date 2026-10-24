<?php

declare(strict_types=1);

use App\Enums\StandardBankTypeEnum;

/**
 * SGD (新加坡元) 標準銀行配置
 *
 * 用於統一各金流商的銀行名稱
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
    // MatchMove（FlashPay 提供，DB 無既有對應）
    // 注意：置於陣列最前，確保 exact-match（信心度 100）優先命中，避免被後方泛用 keyword 誤判攔截
    // 'Match Move Pay' 等別名併自原第二筆同名條目（DgPay 補丁：避免 "Match Move Pay" 因低信心被跳過）
    [
        'code' => 'SGD_MATCHMOVE',
        'standard_name' => '(SGD) - MatchMove',
        'name' => 'MatchMove',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'MATCHMOVE',
            'MatchMove',
            'Match Move Pay',
            'Match Move',
            'MPP',
        ],
        'keywords' => ['matchmove', 'match move'],
    ],

    // Bank of Singapore（FlashPay 提供，DB 無既有對應）
    // 注意：置於陣列最前，避免被 ING Bank 的 keyword 'ing'（"Singapore" 含子字串 "ing"）誤判攔截
    [
        'code' => 'SGD_BOS',
        'standard_name' => '(SGD) - Bank of Singapore',
        'name' => 'Bank of Singapore',
        'swift' => null,
        'aliases' => [
            'Bank of Singapore',
            'BOS',
        ],
        'keywords' => ['bank of singapore'],
    ],

    // ABN AMRO Bank（FlashPay 提供，DB 無既有對應）
    [
        'code' => 'SGD_ABNAMRO',
        'standard_name' => '(SGD) - ABN AMRO Bank',
        'name' => 'ABN AMRO Bank',
        'swift' => null,
        'aliases' => [
            'ABN AMRO Bank',
            'ABN',
        ],
        'keywords' => ['abn amro'],
    ],

    // DBS Bank Limited
    [
        'code' => 'SGD_DBS',
        'standard_name' => '(SGD) - DBS Bank Limited',
        'name' => 'DBS Bank Limited',
        'swift' => 'DBSSSGSG',
        'aliases' => [
            'DBS Bank Limited',
            'DBS Bank',
            'DBS',
            'Development Bank of Singapore', // DBS 2003 年前的全名
            'The Development Bank of Singapore Limited',
        ],
        'keywords' => ['dbs', 'development bank of singapore'],
    ],

    // OCBC Bank
    [
        'code' => 'SGD_OCBC',
        'standard_name' => '(SGD) - OCBC Bank',
        'name' => 'OCBC Bank',
        'swift' => 'OCBCSGSG',
        'aliases' => [
            'OCBC Bank',
            'OCBC',
            'Oversea-Chinese Banking Corporation',
        ],
        'keywords' => ['ocbc', 'oversea chinese banking'],
    ],

    // UOB Bank
    [
        'code' => 'SGD_UOB',
        'standard_name' => '(SGD) - United Overseas Bank',
        'name' => 'United Overseas Bank',
        'swift' => 'UOVBSGSG',
        'aliases' => [
            'UOB Bank',
            'UOB',
            'United Overseas Bank',
            'United Overseas Bank Limited',
        ],
        'keywords' => ['uob', 'united overseas bank'],
    ],

    // HSBC（企業戶）—— 必須排在 SGD_HSBC 之前：keyword 同為 80 分時先出現者優先命中
    // 生產 g93／g95／g116／g674 各有企業戶與個人戶兩列同時啟用，共用一個 standard 會讓
    // agent_banks 投影因 multiple_active 整組跳過；拆成獨立 standard 才解得開
    [
        'code' => 'SGD_HSBC_CORP',
        'standard_name' => '(SGD) - HSBC (Corporate Account)',
        'name' => 'HSBC (Corporate Account)',
        // 企業與商業銀行業務在母行 The Hongkong and Shanghai Banking Corporation 新加坡分行；
        // HSBCSGS2 是零售子公司 HSBC Bank (Singapore) Limited，歸 SGD_HSBC
        'swift' => 'HSBCSGSG',
        'aliases' => [
            'HSBC (Corporate Account)',
            'HSBC Corporate Account',
            'HSBC Corporate',
            'HSBC Corporation',
            'HSBC Coorperation', // 生產 banks.name 既有拼法（拼字錯），精確比對逐字收
            'HSBC Business',
        ],
        'keywords' => ['hsbc corporate', 'hsbc corporation', 'hsbc coorperation', 'hsbc business'],
    ],

    // HSBC
    [
        'code' => 'SGD_HSBC',
        'standard_name' => '(SGD) - HSBC Bank',
        'name' => 'HSBC Bank',
        'swift' => 'HSBCSGS2',
        'aliases' => [
            'HSBC',
            'HSBC Bank',
            'HSBC Singapore',
            'HSBC - Personal',
            'HSBC Singapore - Personal',
            'The Hongkong and Shanghai Banking Corporation',
        ],
        'keywords' => ['hsbc', 'hongkong shanghai banking'],
    ],

    // Citibank
    [
        'code' => 'SGD_CITIBANK',
        'standard_name' => '(SGD) - Citibank Singapore',
        'name' => 'Citibank',
        'swift' => 'CITISGSL',
        'aliases' => [
            'Citibank',
            'Citibank Singapore',
            'Citi',
        ],
        'keywords' => ['citibank', 'citi'],
    ],

    // CIMB Bank
    [
        'code' => 'SGD_CIMB',
        'standard_name' => '(SGD) - CIMB Bank',
        'name' => 'CIMB Bank',
        'swift' => 'CIBBSGSG',
        'aliases' => [
            'CIMB Bank',
            'CIMB',
        ],
        'keywords' => ['cimb', 'commerce international merchant bankers'],
    ],

    // Maybank 母行新加坡分行 —— 必須排在 SGD_MAYBANK 之前（理由同 HSBC 企業戶條目）
    // 新加坡的 Maybank 是兩個法人：子公司 Maybank Singapore Limited（BIC MBBESGS2）做個人、
    // 中小企業與商業銀行業務；母行 Malayan Banking Berhad 的新加坡分行（BIC MBBESGSG）做大型
    // 企業與金融市場業務。金流商碼表把兩者列成兩個碼（例：SurePay `MBBE` 對 Maybank Singapore、
    // `MBBGB` 對 Malayan Banking Berhad, Singapore Branch），生產 g93／g95／g116／g674 也是
    // 兩列並存，所以分成兩個條目。code 沿用 _CORP 只為身分鍵穩定，名稱以法人為準。
    [
        'code' => 'SGD_MAYBANK_CORP',
        'standard_name' => '(SGD) - Malayan Banking Berhad, Singapore Branch',
        'name' => 'Malayan Banking Berhad, Singapore Branch',
        'swift' => 'MBBESGSG',
        'aliases' => [
            'Malayan Banking Berhad',
            'Malayan Banking Berhad, Singapore Branch',
            'Malayan Banking Berhad Singapore Branch',
            'Maybank (Corporate Account)',
            'Maybank Corporate Account',
            'Maybank Corporate',
            'Maybank Business',
        ],
        'keywords' => ['malayan banking berhad', 'maybank corporate', 'maybank business'],
    ],

    // Maybank
    [
        'code' => 'SGD_MAYBANK',
        'standard_name' => '(SGD) - Maybank Singapore',
        'name' => 'Maybank Singapore',
        'swift' => 'MBBESGS2',
        'aliases' => [
            'Maybank',
            'Maybank Singapore',
            'Malayan Banking',
        ],
        'keywords' => ['maybank', 'malayan banking'],
    ],

    // Standard Chartered Bank
    [
        'code' => 'SGD_STANDARDCHARTERED',
        'standard_name' => '(SGD) - Standard Chartered Bank',
        'name' => 'Standard Chartered Bank',
        'swift' => 'SCBLSG22',
        'aliases' => [
            'Standard Chartered Bank',
            'Standard Chartered Bank Singapore',
            'Standard Chartered',
            'SCB',
        ],
        'keywords' => ['standard chartered', 'scb'],
    ],

    // Bank of China
    [
        'code' => 'SGD_BOC',
        'standard_name' => '(SGD) - Bank of China Limited',
        'name' => 'Bank of China Limited',
        'swift' => 'BKCHSGSG',
        'aliases' => [
            'Bank Of China',
            'Bank of China',
            'Bank of China Limited',
            'BOC',
        ],
        'keywords' => ['bank of china', 'boc'],
    ],

    // RHB Bank
    [
        'code' => 'SGD_RHB',
        'standard_name' => '(SGD) - RHB Bank',
        'name' => 'RHB Bank',
        'swift' => null,
        'aliases' => [
            'RHB Bank',
            'RHB',
        ],
        'keywords' => ['rhb', 'rashid hussain bank'],
    ],

    // Hong Leong Bank
    [
        'code' => 'SGD_HLB',
        'standard_name' => '(SGD) - Hong Leong Bank',
        'name' => 'Hong Leong Bank Singapore',
        'swift' => 'HLBBSGSG',
        'aliases' => [
            'HONG LEONG BANK',
            'Hong Leong Bank',
            'Hong Leong Bank Singapore',
            'HLB Singapore',
            'HLB',
        ],
        // 不收裸 'Hong Leong'：Hong Leong Finance 是另一個法人（HLFASGSG）
        'keywords' => ['hong leong bank', 'hlb'],
    ],

    // Trust Bank
    [
        'code' => 'SGD_TRUST',
        'standard_name' => '(SGD) - Trust Bank',
        'name' => 'Trust Bank',
        'swift' => null,
        'aliases' => [
            'Trust Bank',
            'TrustBank',
        ],
        'keywords' => ['trust bank', 'trustbank'],
    ],

    // POSB Bank（品牌獨立列；BIC 與 DBS 共用——DBS 官網「DBS & POSB Swift Code」明載，swift 本就允許跨列重複）
    [
        'code' => 'SGD_POSB',
        'standard_name' => '(SGD) - POSB Bank',
        'name' => 'POSB Bank',
        'swift' => 'DBSSSGSG',
        'aliases' => [
            'POSB Bank',
            'POSB',
            'Post Office Savings Bank',
        ],
        'keywords' => ['posb', 'post office savings bank'],
    ],

    // GXS Bank
    [
        'code' => 'SGD_GXS',
        'standard_name' => '(SGD) - GXS Bank',
        'name' => 'GXS Bank',
        'swift' => null,
        'aliases' => [
            'GXS BANK',
            'GXS Bank',
            'GXS',
        ],
        'keywords' => ['gxs', 'gxs bank'],
    ],

    // Maribank
    [
        'code' => 'SGD_MARIBANK',
        'standard_name' => '(SGD) - Maribank',
        'name' => 'Maribank',
        'swift' => null,
        'aliases' => [
            'Maribank',
            'MariBank',
        ],
        'keywords' => ['maribank', 'mari bank'],
    ],

    // ANEXT Bank
    [
        'code' => 'SGD_ANEXT',
        'standard_name' => '(SGD) - ANEXT Bank',
        'name' => 'ANEXT Bank',
        'swift' => null,
        'aliases' => [
            'ANEXT Bank',
            'ANEXT',
        ],
        'keywords' => ['anext', 'anext bank'],
    ],

    // ANZ Bank
    [
        'code' => 'SGD_AUSTRALIAANDNEWZEALAND',
        'standard_name' => '(SGD) - Australia and New Zealand Bank',
        'name' => 'Australia and New Zealand Bank',
        'swift' => null,
        'aliases' => [
            'Australia And New Zealand Bank (Australia)',
            'Australia and New Zealand Bank',
            'ANZ Bank',
            'ANZ',
        ],
        'keywords' => ['anz', 'australia new zealand bank'],
    ],

    // ICBC（standard_name 對齊 DEV 既有列 "(SGD) - ICBC Bank"，避免展開全名對不上而誤建重複）
    [
        'code' => 'SGD_ICBC',
        'standard_name' => '(SGD) - ICBC Bank',
        'name' => 'ICBC Singapore',
        'swift' => null,
        'aliases' => [
            'ICBC Bank',
            'ICBC',
            'Industrial and Commercial Bank of China',
            'ICBC Singapore',
            'Industrial and Commercial Bank',
        ],
        'keywords' => ['icbc', 'industrial commercial bank china', 'industrial and commercial bank'],
    ],

    // Société Générale
    [
        'code' => 'SGD_SOCITGNRALE',
        'standard_name' => '(SGD) - Société Générale',
        'name' => 'Société Générale',
        'swift' => null,
        'aliases' => [
            'SOCIETE GENERALE',
            'Société Générale',
            'Societe Generale',
        ],
        'keywords' => ['societe generale'],
    ],

    // ING Bank
    [
        'code' => 'SGD_ING',
        'standard_name' => '(SGD) - ING Bank',
        'name' => 'ING Bank',
        'swift' => null,
        'aliases' => [
            'ING Bank',
            'ING',
        ],
        // 裸 'ing' 會子字串命中 Sing Cash／Banking 等任何含 ing 的名稱，只留片語
        'keywords' => ['ing bank'],
    ],

    // BNP Paribas
    [
        'code' => 'SGD_BNPPARIBAS',
        'standard_name' => '(SGD) - BNP Paribas',
        'name' => 'BNP Paribas',
        'swift' => null,
        'aliases' => [
            'BNP Paribas',
            'BNP',
        ],
        'keywords' => ['bnp paribas', 'bnp'],
    ],

    // UBS AG
    [
        'code' => 'SGD_UBSAG',
        'standard_name' => '(SGD) - UBS AG',
        'name' => 'UBS AG',
        'swift' => null,
        'aliases' => [
            'UBS AG',
            'UBS',
        ],
        'keywords' => ['ubs', 'union bank switzerland'],
    ],

    // UniCredit Bank
    [
        'code' => 'SGD_UNICREDITAG',
        'standard_name' => '(SGD) - UniCredit Bank AG',
        'name' => 'UniCredit Bank AG',
        'swift' => null,
        'aliases' => [
            'UniCredit Bank AG',
            'UniCredit',
        ],
        'keywords' => ['unicredit', 'unicredit bank'],
    ],

    // Royal Bank of Scotland
    [
        'code' => 'SGD_ROYALBANKOFSCOTLAND',
        'standard_name' => '(SGD) - Royal Bank of Scotland',
        'name' => 'Royal Bank of Scotland',
        'swift' => null,
        'aliases' => [
            'Royal Bank of Scotland',
            'RBS',
        ],
        'keywords' => ['royal bank of scotland', 'rbs'],
    ],

    // Svenska Handelsbanken
    [
        'code' => 'SGD_SVENSKAHANDELSBANKENAB',
        'standard_name' => '(SGD) - Svenska Handelsbanken AB',
        'name' => 'Svenska Handelsbanken AB',
        'swift' => null,
        'aliases' => [
            'Svenska Handelsbanken AB',
            'Svenska Handelsbanken',
            'Handelsbanken',
        ],
        'keywords' => ['svenska handelsbanken', 'handelsbanken'],
    ],

    // Sumitomo Mitsui Banking Corporation
    [
        'code' => 'SGD_SUMITOMOMITSUI',
        'standard_name' => '(SGD) - Sumitomo Mitsui Banking Corporation',
        'name' => 'Sumitomo Mitsui Banking Corporation',
        'swift' => null,
        'aliases' => [
            'Sumitomo Mitsui Banking Corporation',
            'Sumitomo Mitsui',
            'SMBC',
        ],
        'keywords' => ['sumitomo mitsui', 'smbc'],
    ],

    // State Bank of India
    [
        'code' => 'SGD_STATEBANKOFINDIA',
        'standard_name' => '(SGD) - State Bank of India',
        'name' => 'State Bank of India',
        'swift' => null,
        'aliases' => [
            'State Bank of India',
            'SBI',
        ],
        'keywords' => ['state bank of india', 'sbi'],
    ],

    // Singapura Finance
    [
        'code' => 'SGD_SINGAPURAFINANCE',
        'standard_name' => '(SGD) - Singapura Finance Ltd',
        'name' => 'Singapura Finance Ltd',
        'swift' => null,
        'aliases' => [
            'Singapura Finance Ltd',
            'Singapura Finance',
        ],
        'keywords' => ['singapura finance'],
    ],

    // Singapore Automated Clearing House
    [
        'code' => 'SGD_SINGAPOREAUTOMATEDCLEARINGHOUSE',
        'standard_name' => '(SGD) - Singapore Automated Clearing House',
        'name' => 'Singapore Automated Clearing House',
        'swift' => null,
        'aliases' => [
            'Singapore Automated Clearing House',
            'SACH',
        ],
        'keywords' => ['singapore automated clearing house', 'sach'],
    ],

    // Sing Investments & Finance
    [
        'code' => 'SGD_SINGINVESTMENTSFINANCE',
        'standard_name' => '(SGD) - Sing Investments & Finance Limited',
        'name' => 'Sing Investments & Finance Limited',
        'swift' => null,
        'aliases' => [
            'Sing Investments & Finance Limited',
            'SIF',
        ],
        'keywords' => ['sing investments finance', 'sif'],
    ],

    // Siam Commercial Bank（縮寫 SCB 已讓渡給 Standard Chartered——同幣別一個別名只能屬一家，三家爭用時 matchExact 整組失效）
    [
        'code' => 'SGD_SIAM',
        'standard_name' => '(SGD) - Siam Commercial Bank',
        'name' => 'Siam Commercial Bank',
        'swift' => null,
        'aliases' => [
            'Siam Commercial Bank',
        ],
        'keywords' => ['siam commercial bank'],
    ],

    // Bangkok Bank
    [
        'code' => 'SGD_BANGKOKPUBLIC',
        'standard_name' => '(SGD) - Bangkok Bank Public Company Limited',
        'name' => 'Bangkok Bank Public Company Limited',
        'swift' => null,
        'aliases' => [
            'Bangkok Bank Public Company Limited',
            'Bangkok Bank',
        ],
        'keywords' => ['bangkok bank'],
    ],

    // Qatar National Bank
    [
        'code' => 'SGD_QATARNATIONAL',
        'standard_name' => '(SGD) - Qatar National Bank',
        'name' => 'Qatar National Bank',
        'swift' => null,
        'aliases' => [
            'Qatar National Bank',
            'QNB',
        ],
        'keywords' => ['qatar national bank', 'qnb'],
    ],

    // Bank Negara Indonesia
    [
        'code' => 'SGD_NEGARAINDONESIA',
        'standard_name' => '(SGD) - Bank Negara Indonesia',
        'name' => 'Bank Negara Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Negara Indonesia',
            'BNI',
        ],
        'keywords' => ['bank negara indonesia', 'bni'],
    ],

    // Mizuho Bank
    [
        'code' => 'SGD_MIZUHO',
        'standard_name' => '(SGD) - Mizuho Bank',
        'name' => 'Mizuho Bank',
        'swift' => null,
        'aliases' => [
            'Mizuho Bank',
            'Mizuho',
            'Mizuho Bank Singapore',
        ],
        'keywords' => ['mizuho', 'mizuho bank'],
    ],

    // Bank of America
    [
        'code' => 'SGD_BANKOFAMERICA',
        'standard_name' => '(SGD) - Bank of America',
        'name' => 'Bank of America',
        'swift' => null,
        'aliases' => [
            'Bank of America, National Association',
            'Bank of America',
            'BOA',
        ],
        'keywords' => ['bank of america', 'boa'],
    ],

    // Bank of East Asia
    [
        'code' => 'SGD_BANKOFEASTASIA',
        'standard_name' => '(SGD) - Bank of East Asia',
        'name' => 'Bank of East Asia',
        'swift' => null,
        'aliases' => [
            'Bank of East Asia',
            'BEA',
        ],
        'keywords' => ['bank of east asia', 'bea'],
    ],

    // Bank of India
    [
        'code' => 'SGD_BANKOFINDIA',
        'standard_name' => '(SGD) - Bank of India',
        'name' => 'Bank of India',
        'swift' => null,
        'aliases' => [
            'Bank of India',
            'BOI',
        ],
        'keywords' => ['bank of india', 'boi'],
    ],

    // CTBC Bank
    [
        'code' => 'SGD_CTBC',
        'standard_name' => '(SGD) - CTBC Bank',
        'name' => 'CTBC Bank',
        'swift' => null,
        'aliases' => [
            'CTBC Bank',
            'CTBC',
            'Chinatrust Commercial Bank',
        ],
        'keywords' => ['ctbc', 'chinatrust'],
    ],

    // Commerzbank
    [
        'code' => 'SGD_COMMERZBANKAKTIENGESELLSCHAFT',
        'standard_name' => '(SGD) - Commerzbank Aktiengesellschaft',
        'name' => 'Commerzbank Aktiengesellschaft',
        'swift' => null,
        'aliases' => [
            'Commerzbank Aktiengesellschaft',
            'Commerzbank',
        ],
        'keywords' => ['commerzbank'],
    ],

    // Crédit Agricole
    [
        'code' => 'SGD_CRDITAGRICOLECIB',
        'standard_name' => '(SGD) - Crédit Agricole CIB',
        'name' => 'Crédit Agricole CIB',
        'swift' => null,
        'aliases' => [
            'Crédit Agricole CIB',
            'Credit Agricole CIB',
            'Credit Agricole',
        ],
        'keywords' => ['credit agricole', 'crédit agricole'],
    ],

    // Deutsche Bank
    [
        'code' => 'SGD_DEUTSCHE',
        'standard_name' => '(SGD) - Deutsche Bank',
        'name' => 'Deutsche Bank',
        'swift' => null,
        'aliases' => [
            'Deutsche Bank',
        ],
        'keywords' => ['deutsche bank'],
    ],

    // PayNow (支付系統)
    [
        'code' => 'SGD_PAYNOW',
        'standard_name' => '(SGD) - PayNow',
        'name' => 'PayNow',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'PayNow',
            'PAYNOW',
            'Paynow Singapore',
        ],
        'keywords' => ['paynow', 'pay now'],
    ],

    // Wise (轉帳服務)
    [
        'code' => 'SGD_WISE',
        'standard_name' => '(SGD) - Wise',
        'name' => 'Wise',
        'swift' => null,
        'aliases' => [
            'Wise',
            'TransferWise',
            'Wise Asia Pacific',
        ],
        'keywords' => ['wise', 'transferwise'],
    ],

    // LiquidPay
    [
        'code' => 'SGD_LIQUIDPAY',
        'standard_name' => '(SGD) - LiquidPay',
        'name' => 'LiquidPay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'LiquidPay',
            'Liquid Pay',
        ],
        'keywords' => ['liquidpay', 'liquid pay'],
    ],

    // Dash
    [
        'code' => 'SGD_DASH',
        'standard_name' => '(SGD) - Dash',
        'name' => 'Dash',
        'swift' => null,
        'aliases' => [
            'Dash',
        ],
        'keywords' => ['dash'],
    ],

    // Alliance Bank
    // 名稱照 A3Pay 文件的 Bank Appendix 原文（ABMB｜Alliance Bank），不加 Malaysia：
    // 廠商那張表是 SGD 清單且同表的 MayBank Singapore／HSBC Bank Singapore 都明確帶 Singapore，
    // ABMB 偏偏沒有，無從判定是不是馬來西亞那家；新加坡金管局的金融機構名錄也查不到這家持牌銀行。
    // 舊名留在 aliases，既有資料才比對得到。
    [
        'code' => 'SGD_ALLIANCE',
        'standard_name' => '(SGD) - Alliance Bank',
        'name' => 'Alliance Bank',
        'swift' => null,
        'aliases' => [
            'ALLIANCE BANK',
            'Alliance Bank',
            'ABMB',
            'Alliance Bank Malaysia',
        ],
        'keywords' => ['alliance bank', 'abmb'],
    ],

    // Saigon Commercial Bank（縮寫 SCB 已讓渡給 Standard Chartered，同上）
    [
        'code' => 'SGD_SAIGON',
        'standard_name' => '(SGD) - Saigon Commercial Bank',
        'name' => 'Saigon Commercial Bank',
        'swift' => null,
        'aliases' => [
            'Saigon Commercial Bank',
        ],
        'keywords' => ['saigon commercial bank'],
    ],

    // Far Eastern International Bank (台灣遠東國際商銀)
    [
        'code' => 'SGD_FAREASTERNINTERNATIONAL',
        'standard_name' => '(SGD) - Far Eastern International Bank',
        'name' => 'Far Eastern International Bank',
        'swift' => null,
        'aliases' => [
            'Far Eastern International Bank',
            "Far Eastern Int'l Bank",
            'FEIB',
        ],
        'keywords' => ['far eastern international bank', 'far eastern intl bank', 'feib'],
    ],

    // First Commercial Bank (台灣第一商業銀行)
    [
        'code' => 'SGD_FIRST',
        'standard_name' => '(SGD) - First Commercial Bank',
        'name' => 'First Commercial Bank',
        'swift' => null,
        'aliases' => [
            'First Commercial Bank',
            'FCB',
        ],
        'keywords' => ['first commercial bank', 'fcb'],
    ],

    // Indian Bank
    [
        'code' => 'SGD_INDIAN',
        'standard_name' => '(SGD) - Indian Bank',
        'name' => 'Indian Bank',
        'swift' => null,
        'aliases' => [
            'Indian Bank',
        ],
        'keywords' => ['indian bank'],
    ],

    // Indian Overseas Bank
    [
        'code' => 'SGD_IOB',
        'standard_name' => '(SGD) - Indian Overseas Bank',
        'name' => 'Indian Overseas Bank',
        'swift' => null,
        'aliases' => [
            'Indian Overseas Bank',
            'IOB',
        ],
        'keywords' => ['indian overseas bank', 'iob', 'indian overseas'],
    ],

    // ── 併自 database/data/standard_bank_codes.php：本檔原無對應的條目（置於陣列末＝命中優先權最低） ──
    [
        'code' => 'SGD_ICICI',
        'standard_name' => '(SGD) - ICICI Bank',
        'name' => 'ICICI Bank',
        'swift' => null,
        'aliases' => [
            'ICICI Bank',
            'ICICI',
        ],
        'keywords' => [
            'icici',
        ],
    ],
    [
        'code' => 'SGD_PUBLIC',
        'standard_name' => '(SGD) - Public Bank Singapore',
        'name' => 'Public Bank Singapore',
        'swift' => null,
        'aliases' => [
            'Public Bank Singapore',
            'Public Bank',
        ],
        'keywords' => [
        ],
    ],

    // ── 2026-09-14 補：VWorld／TPower 啟用中、會員選得到卻無條目的三家（置於陣列末＝命中優先權最低） ──
    // standard_name 逐字對齊生產 banks.name（含大小寫）：bank:fix-mismatch 的分組層稽核以 !== 比對，
    // 改寫成正式行名會讓兩站 18 列全被標成「應改名」；銀行身分寫在 name（比照 SGD_ICBC 對齊既有列）。
    // Sing Cash 刻意不補：TPower 前台圖示是 Cash App 標誌、與名稱對不上，身分未定（RB 2026-09-14 拍板留白）。
    [
        'code' => 'SGD_JPMORGANCHASE',
        'standard_name' => '(SGD) - Jpmorgan Chase Bank',
        'name' => 'JPMorgan Chase Bank',
        'swift' => null,
        'aliases' => [
            'JPMorgan Chase Bank',
            'JPMorgan Chase Bank, N.A.',
            'JPMorgan Chase',
            'JPMorgan',
            'JPM',
        ],
        'keywords' => ['jpmorgan', 'jp morgan'],
    ],
    // 生產名稱沿用舊行名；TPower 前台圖示用的是 MUFG 標誌
    [
        'code' => 'SGD_MUFG',
        'standard_name' => '(SGD) - The Bank Of Tokyo-Mitsubishi',
        'name' => 'MUFG Bank',
        'swift' => null,
        'aliases' => [
            'The Bank Of Tokyo-Mitsubishi',
            'The Bank of Tokyo-Mitsubishi UFJ',
            'Bank of Tokyo-Mitsubishi',
            'MUFG Bank',
            'MUFG',
        ],
        // 帶連字號的片語給 BankStandardMappingService（只轉小寫、保留連字號比子字串）
        'keywords' => ['mufg', 'tokyo-mitsubishi', 'tokyo mitsubishi'],
    ],
    // 生產名稱是縮寫 GPAY；TPower 前台圖示為 Google Pay 標誌
    [
        'code' => 'SGD_GOOGLEPAY',
        'standard_name' => '(SGD) - GPAY',
        'name' => 'Google Pay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'GPAY',
            'Google Pay',
            'GooglePay',
        ],
        // 不放裸 'gpay'：StandardBankNameHelper 去空白後比子字串，DGPay 之類名稱會以 85 分被誤認成 Google Pay
        'keywords' => ['google pay', 'googlepay'],
    ],
];
