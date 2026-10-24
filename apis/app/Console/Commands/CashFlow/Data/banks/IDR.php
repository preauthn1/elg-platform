<?php

declare(strict_types=1);

use App\Enums\StandardBankTypeEnum;

/**
 * IDR 標準銀行配置（擴充別名和關鍵字版本）
 *
 * 注意：standard_name 必須與 banks.name 完全一致（DB Phase 2 改名後的版本）。
 *   - 印尼官方縮寫已展開為全名：BCA → Central Asia / BNI → Negara Indonesia 等
 *   - 全大寫片段已轉為標準大小寫：CAPITAL → Capital / JATENG → Jateng 等
 *   - 跨金流商命名已整併：BII Maybank → Maybank Indonesia / Ovo Payment → OVO 等
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
    // 印尼國有銀行 - Bank Tiga Pilar
    [
        'code' => 'IDR_BRI',
        'standard_name' => '(IDR) - Bank Rakyat Indonesia',
        'name' => 'Bank BRI',
        'swift' => null,
        'aliases' => [
            'Bank BRI',
            'BRI',
            'Bank Rakyat Indonesia',
            'BankBRI',
            'Rakyat Indonesia',
        ],
        'keywords' => [
            'bri',
            'rakyat',
        ],
    ],
    [
        'code' => 'IDR_MANDIRI',
        'standard_name' => '(IDR) - Bank Mandiri',
        'name' => 'Bank Mandiri',
        'swift' => null,
        'aliases' => [
            'Bank Mandiri',
            'Mandiri',
            'BankMandiri',
            'PT Bank Mandiri',
            'VA Mandiri',
        ],
        'keywords' => [
            'mandiri',
        ],
    ],
    [
        'code' => 'IDR_BNI',
        'standard_name' => '(IDR) - Bank Negara Indonesia',
        'name' => 'Bank BNI',
        'swift' => null,
        'aliases' => [
            'Bank BNI',
            'BNI',
            'Bank Negara Indonesia',
            'BankBNI',
            'Negara Indonesia',
            'VA BNI',
        ],
        'keywords' => [
            'bni',
            'negara',
        ],
    ],

    // 印尼私人銀行 - A
    [
        'code' => 'IDR_BCA',
        'standard_name' => '(IDR) - Bank Central Asia',
        'name' => 'Bank BCA',
        'swift' => 'CENAIDJA',
        'aliases' => [
            'Bank BCA',
            'BCA',
            'Bank Central Asia',
            'BankBCA',
            'Central Asia',
            'VA BCA',
        ],
        'keywords' => [
            'bca',
            'central asia',
        ],
    ],
    [
        'code' => 'IDR_DANAMON',
        'standard_name' => '(IDR) - Bank Danamon',
        'name' => 'Bank Danamon',
        'swift' => null,
        'aliases' => [
            'Bank Danamon',
            'Danamon',
            'BankDanamon',
            'Danamon Indonesia',
            'VA Danamon',
        ],
        'keywords' => [
            'danamon',
        ],
    ],

    // 印尼私人銀行 - P
    [
        'code' => 'IDR_PERMATA',
        'standard_name' => '(IDR) - Bank Permata',
        'name' => 'Bank Permata',
        'swift' => null,
        'aliases' => [
            'Bank Permata',
            'Permata',
            'BankPermata',
            'Permata Indonesia',
            'VA Permata',
        ],
        'keywords' => [
            'permata',
        ],
    ],
    [
        'code' => 'IDR_PERMATASYARIAH',
        'standard_name' => '(IDR) - Bank Permata Syariah',
        'name' => 'Bank Permata Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Permata Syariah',
            'Permata Syariah',
            'Permata Islamic',
        ],
        'keywords' => [
            'permata syariah',
        ],
    ],
    [
        'code' => 'IDR_PANIN',
        'standard_name' => '(IDR) - Bank Panin',
        'name' => 'Bank Panin',
        'swift' => null,
        'aliases' => [
            'Bank Panin',
            'Panin',
            'BankPanin',
            'Panin Indonesia',
            'Panin Bank',
            'PT Bank Pan Indonesia', // 三方回饋的法定全名（Yes2Win／VPlus33 實況），少了它會被 QNB 的相似度 82 分搶走
            'Bank Pan Indonesia',
            'Pan Indonesia',
        ],
        'keywords' => [
            'panin',
            'pan indonesia',
        ],
    ],

    // 印尼私人銀行 - C
    [
        'code' => 'IDR_CIMB',
        'standard_name' => '(IDR) - CIMB Niaga',
        'name' => 'CIMB Niaga',
        'swift' => null,
        'aliases' => [
            'CIMB Niaga',
            'CIMBNiaga',
            'CIMB',
            'CIMB Bank Niaga',
            'VA CIMB',
        ],
        'keywords' => [
            'cimb',
            'cimb niaga',
            'niaga',
        ],
    ],
    [
        'code' => 'IDR_CITIBANK',
        'standard_name' => '(IDR) - Citibank',
        'name' => 'Citibank',
        'swift' => null,
        'aliases' => [
            'CITIBANK',
            'Citibank',
            'Citi Bank',
            'Citibank Indonesia',
        ],
        'keywords' => [
            'citi',
        ],
    ],

    // 印尼私人銀行 - M
    [
        'code' => 'IDR_MAYBANKINDONESIA',
        'standard_name' => '(IDR) - Maybank Indonesia',
        'name' => 'Maybank Indonesia',
        'swift' => null,
        'aliases' => [
            'BII Maybank',
            'BIIMaybank',
            'Maybank',
            'Maybank Indonesia',
        ],
        'keywords' => [
            'maybank',
        ],
    ],

    // 印尼私人銀行 - O
    [
        'code' => 'IDR_OCBCNISP',
        'standard_name' => '(IDR) - Bank OCBC NISP',
        'name' => 'Bank OCBC NISP',
        'swift' => 'NISPIDJA',
        'aliases' => [
            'Bank OCBC NISP',
            'OCBC NISP',
            'OCBCNISP',
            'OCBC Bank NISP',
            'OCBC Bank',
        ],
        'keywords' => [
            'ocbc',
            'nisp',
        ],
    ],

    // 印尼私人銀行 - U
    [
        'code' => 'IDR_UOBINDONESIA',
        'standard_name' => '(IDR) - Bank UOB Indonesia',
        'name' => 'Bank UOB Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank UOB INDONESIA',
            'Bank UOB Indonesia',
            'UOB Indonesia',
            'UOB',
            'BankUOB',
            'United Overseas Bank Indonesia',
            'United Overseas Bank Limited',
            'UOBI',
        ],
        'keywords' => [
            'uob',
            'united overseas',
        ],
    ],

    // 外資銀行 - A
    [
        'code' => 'IDR_ANZINDONESIA',
        'standard_name' => '(IDR) - ANZ Indonesia',
        'name' => 'ANZ Indonesia',
        'swift' => null,
        'aliases' => [
            'ANZ Indonesia',
            'ANZIndonesia',
            'ANZ',
            'Australia New Zealand',
        ],
        'keywords' => [
            'anz',
        ],
    ],

    // 外資銀行 - B
    [
        'code' => 'IDR_BANKOFAMERICANA',
        'standard_name' => '(IDR) - Bank of America NA',
        'name' => 'Bank of America NA',
        'swift' => null,
        'aliases' => [
            'Bank of America NA',
            'Bank of America',
            'BankOfAmerica',
            'BoA',
            'Bank America',
        ],
        'keywords' => [
            'america',
            'boa',
        ],
    ],
    [
        'code' => 'IDR_BNPPARIBAS',
        'standard_name' => '(IDR) - Bank BNP Paribas',
        'name' => 'Bank BNP Paribas',
        'swift' => null,
        'aliases' => [
            'Bank BNP Paribas',
            'BNP Paribas',
            'BNPParibas',
            'Bank BNP',
        ],
        'keywords' => [
            'bnp',
            'paribas',
        ],
    ],
    [
        'code' => 'IDR_BUMIARTA',
        'standard_name' => '(IDR) - Bank Bumi Arta',
        'name' => 'Bank Bumi Arta',
        'swift' => null,
        'aliases' => [
            'Bank Bumi Arta',
            'Bank Bumi Artha',
            'Bumi Arta',
            'BumiArta',
            'Bank Bumi',
        ],
        'keywords' => [
            'bumi arta',
        ],
    ],

    // 外資銀行 - H
    [
        'code' => 'IDR_HSBC',
        'standard_name' => '(IDR) - Bank HSBC',
        'name' => 'Bank HSBC Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank HSBC',
            'HSBC',
            'HSBCIndonesia',
            'HSBC Bank Indonesia',
            'Bank HSBC Indonesia',
        ],
        'keywords' => [
            'hsbc',
        ],
    ],

    // 外資銀行 - J
    [
        'code' => 'IDR_JTRUSTINDONESIA',
        'standard_name' => '(IDR) - Bank JTrust Indonesia',
        'name' => 'Bank JTrust Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank JTRUST INDONESIA',
            'Bank JTrust Indonesia',
            'JTRUST Indonesia',
            'JTrust',
            'Bank JTrust',
        ],
        'keywords' => [
            'jtrust',
        ],
    ],

    // 外資銀行 - M
    [
        'code' => 'IDR_MIZUHOINDONESIA',
        'standard_name' => '(IDR) - Bank Mizuho Indonesia',
        'name' => 'Bank Mizuho Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Mizuho Indonesia',
            'Mizuho Indonesia',
            'MizuhoIndonesia',
            'Mizuho Bank',
        ],
        'keywords' => [
            'mizuho',
        ],
    ],

    // 外資銀行 - R
    [
        'code' => 'IDR_RESONAPERDANIA',
        'standard_name' => '(IDR) - Bank Resona Perdania',
        'name' => 'Bank Resona Perdania',
        'swift' => null,
        'aliases' => [
            'Bank Resona Perdania',
            'Resona Perdania',
            'ResonaPerdania',
            'Bank Resona',
        ],
        'keywords' => [
            'resona',
        ],
    ],
    [
        'code' => 'IDR_RABOBANK',
        'standard_name' => '(IDR) - Bank Rabobank',
        'name' => 'Bank Rabobank',
        'swift' => null,
        'aliases' => [
            'Bank Rabobank',
            'Rabobank',
            'Bank Rabo',
        ],
        'keywords' => [
            'rabobank',
        ],
    ],

    // 外資銀行 - S
    [
        'code' => 'IDR_STANDARDCHARTERED',
        'standard_name' => '(IDR) - Standard Chartered',
        'name' => 'Standard Chartered',
        'swift' => null,
        'aliases' => [
            'Standard Chartered',
            'StandardChartered',
            'SC Indonesia',
            'Standard Chartered Bank',
        ],
        'keywords' => [
            'standard chartered',
        ],
    ],

    // 外資銀行 - T
    [
        'code' => 'IDR_TOKYOMITSUBISHIUFJ',
        'standard_name' => '(IDR) - Bank Tokyo Mitsubishi UFJ',
        'name' => 'Bank Tokyo Mitsubishi UFJ',
        'swift' => null,
        'aliases' => [
            'Bank TOKYO MITSUBISHI UFJ',
            'Bank Tokyo Mitsubishi UFJ',
            'Bank MUFJ',
            'MUFJ',
            'TokyoMitsubishi',
            'Tokyo Mitsubishi UFJ',
            'Bank of Tokyo',
            'Bank of Tokyo Mitsubishi UFJ',
            'BTMU',
            'Mitsubishi UFJ Bank Indonesia',
        ],
        'keywords' => [
            'tokyo',
            'mufj',
        ],
    ],

    // 外資銀行 - D
    [
        'code' => 'IDR_DBS',
        'standard_name' => '(IDR) - Bank DBS',
        'name' => 'Bank DBS Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank DBS',
            'DBS',
            'Development Bank Singapore',
            'Bank DBS Indonesia',
        ],
        'keywords' => [
            'dbs',
        ],
    ],

    // 其他商業銀行 - A
    [
        'code' => 'IDR_ARTHAGRAHA',
        'standard_name' => '(IDR) - Bank Artha Graha',
        'name' => 'Bank Artha Graha',
        'swift' => null,
        'aliases' => [
            'Bank ARTHA GRAHA',
            'Bank Artha Graha',
            'Artha Graha',
            'ArthaGraha',
            'Bank Artha',
        ],
        'keywords' => [
            'artha',
        ],
    ],
    [
        'code' => 'IDR_ANDA',
        'retired_into' => 'IDR_CCBINDONESIA', // Bank Antardaerah，2016 年與 Windu Kentjana 合併成 CCB Indonesia
        'standard_name' => '(IDR) - Bank Antardaerah',
        'name' => 'Bank Anda',
        'swift' => null,
        'aliases' => [
            'Bank Antardaerah',
            'Antardaerah',
            'Inter-regional Bank',
            'Bank Anda',
            'Bank Antar Daerah',
        ],
        'keywords' => [
            'antardaerah',
            'antar daerah',
            'bank anda',
        ],
    ],

    // 其他商業銀行 - C
    [
        'code' => 'IDR_CAPITAL',
        'standard_name' => '(IDR) - Bank Capital',
        'name' => 'Bank Capital',
        'swift' => null,
        'aliases' => [
            'Bank CAPITAL',
            'Bank Capital',
            'Capital',
            'BankCapital',
            'Capital Indonesia',
        ],
        'keywords' => [
            'capital',
        ],
    ],

    // 其他商業銀行 - M
    [
        'code' => 'IDR_MAYAPADA',
        'standard_name' => '(IDR) - Bank Mayapada',
        'name' => 'Bank Mayapada',
        'swift' => null,
        'aliases' => [
            'Bank MAYAPADA',
            'Bank Mayapada',
            'Mayapada',
            'BankMayapada',
            'Mayapada Indonesia',
            'Mayapada Bank',
        ],
        'keywords' => [
            'mayapada',
        ],
    ],

    // 其他商業銀行 - W
    [
        'code' => 'IDR_WINDUKENTJANAINTERNATIONAL',
        'retired_into' => 'IDR_CCBINDONESIA', // 2016 年與 Bank Antardaerah 合併成 Bank China Construction Bank Indonesia
        'standard_name' => '(IDR) - Bank Windu Kentjana International',
        'name' => 'Bank Windu Kentjana International',
        'swift' => null,
        'aliases' => [
            'Bank Windu Kentjana International',
            'Windu Kentjana',
            'WinduKentjana',
            'Windu',
        ],
        'keywords' => [
            'windu',
        ],
    ],

    // 地方銀行
    [
        'code' => 'IDR_BJB',
        'standard_name' => '(IDR) - Bank Jawa Barat',
        'name' => 'Bank BJB',
        'swift' => null,
        'aliases' => [
            'Bank Jawa Barat',
            'Jawa Barat',
            'BJB',
            'Bank BJB',
            'Bank Jabar Banten',
            'Bank Jabar',
            'West Java Bank',
        ],
        'keywords' => [
            'jabar',
            'barat',
            'bjb',
        ],
    ],
    [
        'code' => 'IDR_BPDDIY',
        'standard_name' => '(IDR) - Bank BPD DIY',
        'name' => 'Bank BPD DIY',
        'swift' => null,
        'aliases' => [
            'Bank BPD DIY',
            'BPD DIY',
            'Yogyakarta',
            'Yogyakarta Bank',
        ],
        'keywords' => [
            'diy',
            'yogya',
            'bpd diy',
        ],
    ],
    [
        'code' => 'IDR_JATENG',
        'standard_name' => '(IDR) - Bank Jateng',
        'name' => 'Bank Jateng',
        'swift' => null,
        'aliases' => [
            'Bank JATENG',
            'Bank Jateng',
            'Jawa Tengah',
            'JawaTengah',
            'Central Java Bank',
        ],
        'keywords' => [
            'jateng',
            'tengah',
        ],
    ],
    [
        'code' => 'IDR_JATIM',
        'standard_name' => '(IDR) - Bank Jatim',
        'name' => 'Bank Jatim',
        'swift' => null,
        'aliases' => [
            'Bank Jatim',
            'Jawa Timur',
            'JawaTimur',
            'East Java Bank',
            'BPD Jawa Timur',
            'BPD Jatim',
            'Bank BPD Jatim',
            'Bank Pembangunan Daerah Jawa Timur',
        ],
        'keywords' => [
            'jatim',
            'jawa timur',
        ],
    ],
    [
        'code' => 'IDR_DKI',
        'standard_name' => '(IDR) - Bank DKI Jakarta',
        'name' => 'Bank DKI',
        'swift' => null,
        'aliases' => [
            'Bank DKI',
            'Bank DKI Jakarta',
            'DKI',
            'Jakarta',
            'Jakarta Bank',
            // 'Bank DKI UUS' 已讓渡給 Bank DKI Jakarta UUS（伊斯蘭業務單位為獨立條目）
        ],
        'keywords' => [
            'dki',
            'jakarta',
        ],
    ],

    // 特殊銀行 - A
    [
        'code' => 'IDR_ACEH',
        'retired_into' => 'IDR_ACEHSYARIAH', // 2016 年整家轉為伊斯蘭銀行 Bank Aceh Syariah
        'standard_name' => '(IDR) - Bank Aceh',
        'name' => 'Bank Aceh',
        'swift' => null,
        'aliases' => [
            'Bank Aceh',
            'Aceh',
            'BankAceh',
            'Aceh Bank',
        ],
        'keywords' => [
            'aceh',
        ],
    ],
    [
        'code' => 'IDR_ACEHSYARIAH',
        'standard_name' => '(IDR) - Bank Aceh Syariah',
        'name' => 'Bank Aceh Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Aceh Syariah',
            'Aceh Syariah',
            'Aceh Islamic',
            'PT Bank Aceh Syariah',
        ],
        'keywords' => [
            'aceh syariah',
        ],
    ],

    // 特殊銀行 - I (新增 IBK)
    [
        'code' => 'IDR_IBKINDONESIA',
        'standard_name' => '(IDR) - Bank IBK Indonesia',
        'name' => 'Bank IBK Indonesia',
        'swift' => null,
        'aliases' => [
            'IBK',
            'Bank IBK',
            'Bank IBK Indonesia',
            'Industrial Bank of Korea',
            'Industrial Bank of Korea Indonesia',
        ],
        'keywords' => [
            'ibk',
        ],
    ],

    // 特殊銀行 - J
    [
        'code' => 'IDR_JAMBI',
        'standard_name' => '(IDR) - Bank Jambi',
        'name' => 'Bank Jambi',
        'swift' => null,
        'aliases' => [
            'Bank Jambi',
            'Jambi',
            'BankJambi',
            'Jambi Bank',
        ],
        'keywords' => [
            'jambi',
        ],
    ],
    [
        'code' => 'IDR_JAMBISYARIAH',
        'standard_name' => '(IDR) - Bank Jambi Syariah',
        'name' => 'Bank Jambi Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Jambi Syariah',
            'Jambi Syariah',
            'Jambi Islamic',
        ],
        'keywords' => [
            'jambi syariah',
        ],
    ],

    // 特殊銀行 - N (新增 NTB / NTT)
    [
        'code' => 'IDR_NAGARI',
        'standard_name' => '(IDR) - Bank Nagari',
        'name' => 'Bank Nagari',
        'swift' => null,
        'aliases' => [
            'Bank NAGARI',
            'Bank Nagari',
            'Nagari',
            'BankNagari',
            'Nagari Bank',
            'BPD Sumatera Barat',
            'BPD Sumatera Barat UUS',
            'BPD Sumbar',
            'Bank Sumatera Barat',
        ],
        'keywords' => [
            'nagari',
            'sumbar',
            'sumatera barat',
        ],
    ],
    // 只寫進 standard_banks、cash-flow:sync 看不到，理由見 IDR_KALBARUUS 上方註解
    [
        'code' => 'IDR_NAGARIUUS',
        'sync_visible' => false,
        'standard_name' => '(IDR) - BPD Sumatera Barat UUS',
        'name' => 'BPD Sumatera Barat UUS',
        'swift' => null,
        'aliases' => [
            'BPD Sumatera Barat UUS',
            'Bank Nagari UUS',
        ],
        'keywords' => [
            'nagari uus',
            'sumatera barat uus',
        ],
    ],
    [
        'code' => 'IDR_NTB',
        'retired_into' => 'IDR_NUSATENGGARABARATSYARIAH', // 2018 年整家轉為伊斯蘭銀行 Bank NTB Syariah
        'standard_name' => '(IDR) - Bank Nusa Tenggara Barat',
        'name' => 'Bank NTB',
        'swift' => null,
        'aliases' => [
            'Bank NTB',
            'NTB',
            'Bank Nusa Tenggara Barat',
            'NTB Bank',
        ],
        'keywords' => [
            'bank ntb',
            'ntb bank',
            'tenggara barat',
        ],
    ],
    [
        'code' => 'IDR_NUSATENGGARABARATSYARIAH',
        'standard_name' => '(IDR) - Bank Nusa Tenggara Barat Syariah',
        'name' => 'Bank Nusa Tenggara Barat Syariah',
        'swift' => null,
        'aliases' => [
            'Bank NTB Syariah',
            'NTB Syariah',
            'Bank Nusa Tenggara Barat Syariah',
        ],
        'keywords' => [
            'ntb syariah',
        ],
    ],
    [
        'code' => 'IDR_NTT',
        'standard_name' => '(IDR) - Bank Nusa Tenggara Timur',
        'name' => 'Bank NTT',
        'swift' => null,
        'aliases' => [
            'Bank NTT',
            'NTT',
            'Bank Nusa Tenggara Timur',
            'NTT Bank',
        ],
        'keywords' => [
            'ntt',
            'tenggara timur',
        ],
    ],

    // 特殊銀行 - R
    [
        'code' => 'IDR_RIAU',
        'retired_into' => 'IDR_RIAUSYARIAH', // 2022 年整家轉為伊斯蘭銀行 Bank Riau Kepri Syariah
        'standard_name' => '(IDR) - Bank Riau',
        'name' => 'Bank Riau',
        'swift' => null,
        'aliases' => [
            'Bank Riau',
            'Riau',
            'BankRiau',
            'Riau Bank',
            'BPD Riau Dan Kepri',
            'BPD Riau Kepri',
            'Bank Riau Kepri',
            'Bank BPD Riau Kepri',
            'Bank Pembangunan Daerah Riau Kepri',
        ],
        'keywords' => [
            'riau',
        ],
    ],
    [
        'code' => 'IDR_RIAUSYARIAH',
        'standard_name' => '(IDR) - Bank Riau Syariah',
        'name' => 'Bank Riau Kepri Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Riau Syariah',
            'Riau Syariah',
            'Riau Islamic',
            'BPD Riau Dan Kepri UUS',
            'BPD Riau Kepri UUS',
            'Bank Riau Kepri UUS',
        ],
        'keywords' => [
            'riau syariah',
            'riau kepri uus',
        ],
    ],

    // 特殊銀行 - S
    [
        'code' => 'IDR_SUMUT',
        'standard_name' => '(IDR) - Bank Sumut',
        'name' => 'Bank Sumut',
        'swift' => null,
        'aliases' => [
            'Bank SUMUT',
            'Bank Sumut',
            'Sumatera Utara',
            'SumateraUtara',
            'North Sumatra Bank',
            'BPD Sumut',
            'Bank BPD Sumut',
            'Bank Pembangunan Daerah Sumatera Utara',
        ],
        'keywords' => [
            'sumut',
            'sumatra',
        ],
    ],
    [
        'code' => 'IDR_SUMSELBABEL',
        'standard_name' => '(IDR) - Bank Sumsel Babel',
        'name' => 'Bank Sumsel Babel',
        'swift' => null,
        'aliases' => [
            'Bank SUMSEL BABEL',
            'Bank Sumsel Babel',
            'Sumatera Selatan',
            'SumateraSelatan',
            'South Sumatra Bank',
            'BPD Sumsel Babel',
            'Bank BPD Sumsel Babel',
            'Bank Pembangunan Daerah Sumsel Babel',
        ],
        'keywords' => [
            'sumsel',
            'babel',
        ],
    ],
    [
        'code' => 'IDR_SYARIAHINDONESIA',
        'standard_name' => '(IDR) - Bank Syariah Indonesia',
        'name' => 'Bank Syariah Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Syariah Indonesia',
            'BSI',
            'VA BSI',
            'Bank BSI',
            'PT Bank Syariah Indonesia',
            'PT Bank Syariah Indonesia Tbk',
        ],
        'keywords' => [
            'bsi',
        ],
    ],

    // 外資銀行 - C
    [
        'code' => 'IDR_BANKOFCHINA',
        'standard_name' => '(IDR) - Bank of China',
        'name' => 'Bank of China',
        'swift' => null,
        'aliases' => [
            'Bank Of China',
            'Bank of China',
            'BankOfChina',
            'BOC',
            'China Bank',
        ],
        'keywords' => [
            'bank of china',
            'boc',
        ],
    ],

    // ── 電子錢包 ────────────────────────────────────────────────────
    [
        'code' => 'IDR_OVO',
        'standard_name' => '(IDR) - OVO',
        'name' => 'OVO',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'OVO',
            'Ovo',
            'Ovo Payment',
            'OVO Wallet',
        ],
        'keywords' => [
            'ovo',
        ],
    ],
    [
        'code' => 'IDR_DANA',
        'standard_name' => '(IDR) - DANA',
        'name' => 'DANA',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'DANA',
            'Dana',
            'DANA eWallet',
            'Dana Wallet',
        ],
        'keywords' => [
            'dana',
        ],
    ],
    [
        'code' => 'IDR_GOPAY',
        'standard_name' => '(IDR) - GoPay',
        'name' => 'GoPay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'GoPay',
            'Gopay',
            'Go-Pay',
            'GoPay Wallet',
        ],
        'keywords' => [
            'gopay',
        ],
    ],
    [
        'code' => 'IDR_LINKAJA',
        'standard_name' => '(IDR) - LinkAja',
        'name' => 'LinkAja',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'LinkAja',
            'Link Aja',
            'LINK Aja',
            'Link Aja App wallet',
        ],
        'keywords' => [
            'linkaja',
            'link aja',
        ],
    ],
    [
        'code' => 'IDR_SHOPEEPAY',
        'standard_name' => '(IDR) - ShopeePay',
        'name' => 'ShopeePay',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'ShopeePay',
            'Shopeepay',
            'Shopeepay Indonesia',
            'Shopee Pay',
        ],
        'keywords' => [
            'shopeepay',
            'shopee',
        ],
    ],

    // ── TopPay 新增整合銀行（cash-flow:sync --ids=94 --currency=IDR 補強） ──
    // 數位銀行
    [
        // Bank Harda Internasional 的舊名全部併入本 entry 當 alias。原本另有一筆獨立的
        // '(IDR) - Bank Harda Internasional' standard entry（含 'Harda'／'Bank Harda' 等 alias），
        // 與本 entry 的 'Bank Harda Internasional' alias 相衝，造成鏈式正名擺盪：名為 'Harda'
        // 的列先跳到 'Bank Harda Internasional'、下一輪又被本 entry 判成該叫 'Allo Bank'
        // （rename 一次只走一跳）。2026-08-27 RB 拍板「保 canonical Allo Bank」⇒ 合併兩 entry、
        // 一跳到底。判斷依據是系統內部一致性（canonical 碼表已給 Allo Bank 正式碼、auditor 判
        // 兩者同 standard），**「Harda 已更名為 Allo Bank」的銀行史實本身無獨立來源可證**。
        'code' => 'IDR_ALLO',
        'standard_name' => '(IDR) - Allo Bank',
        'name' => 'Allo Bank',
        'swift' => null,
        'aliases' => [
            'Allo Bank',
            'AlloBank',
            'Bank Allo',
            'Allo Bank Indonesia',
            'Bank Harda Internasional',
            'Harda',
            'Bank Harda',
            'PT Bank Harda Internasional Tbk',
        ],
        'keywords' => [
            'allo',
            'harda',
        ],
    ],
    [
        'code' => 'IDR_AMAR',
        'standard_name' => '(IDR) - Bank Amar Indonesia',
        'name' => 'Bank Amar',
        'swift' => null,
        'aliases' => [
            'Bank AMAR',
            'Bank Amar',
            'Amar Bank',
            'Bank Amar Indonesia',
            'PT Bank Amar Indonesia',
            'Tunaiku',
        ],
        'keywords' => [
            'amar',
        ],
    ],
    [
        'code' => 'IDR_JAGO',
        'standard_name' => '(IDR) - Bank Jago',
        'name' => 'Bank Jago',
        'swift' => null,
        'aliases' => [
            'Bank Jago',
            'BankJago',
            'Jago',
            'PT Bank Jago Tbk',
            'Bank Artos',
            'Bank Artos Indonesia',
            'Bank Artos / Bank Jago',
        ],
        'keywords' => [
            'jago',
            'artos',
        ],
    ],

    // 外資銀行
    [
        'code' => 'IDR_BANGKOK',
        'standard_name' => '(IDR) - Bangkok Bank',
        'name' => 'Bangkok Bank',
        'swift' => null,
        'aliases' => [
            'Bangkok Bank',
            'BangkokBank',
            'Bangkok Bank PCL',
            'Bangkok Bank Indonesia',
        ],
        'keywords' => [
            'bangkok',
        ],
    ],
    [
        'code' => 'IDR_BARCLAYSINDONESIA',
        'standard_name' => '(IDR) - Bank Barclays Indonesia',
        'name' => 'Bank Barclays Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Barclays Indonesia',
            'Barclays Indonesia',
            'Barclays',
            'Bank Barclays',
        ],
        'keywords' => [
            'barclays',
        ],
    ],
    [
        'code' => 'IDR_DEUTSCHE',
        'standard_name' => '(IDR) - Deutsche Bank',
        'name' => 'Deutsche Bank',
        'swift' => null,
        'aliases' => [
            'Deutsche Bank',
            'DeutscheBank',
            'Deutsche',
            'Deutsche Bank AG',
            'Deutsche Bank Indonesia',
        ],
        'keywords' => [
            'deutsche',
        ],
    ],
    [
        'code' => 'IDR_JPMORGANCHASE',
        'standard_name' => '(IDR) - JPMorgan Chase Bank',
        'name' => 'JPMorgan Chase Bank',
        'swift' => null,
        'aliases' => [
            'JPMorgan Chase Bank',
            'JPMorgan',
            'JP Morgan',
            'JPMorgan Chase',
            'JP Morgan Chase',
            'Chase Bank',
            'JPMC',
        ],
        'keywords' => [
            'jpmorgan',
            'jp morgan',
        ],
    ],
    [
        'code' => 'IDR_SUMITOMOMITSUIINDONESIA',
        'retired_into' => 'IDR_BTPN', // 2019 年併入 BTPN，BTPN 2024 年改名 Bank SMBC Indonesia
        'standard_name' => '(IDR) - Bank Sumitomo Mitsui Indonesia',
        'name' => 'Bank Sumitomo Mitsui Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Sumitomo Mitsui Indonesia',
            'Sumitomo Mitsui Indonesia',
            'SumitomoMitsui',
            'SMBC Indonesia',
            'Bank SMBC',
            'PT Bank Sumitomo Mitsui Indonesia',
        ],
        'keywords' => [
            'sumitomo',
            'smbc',
        ],
    ],
    [
        'code' => 'IDR_CCBINDONESIA',
        'standard_name' => '(IDR) - Bank CCB Indonesia',
        'name' => 'Bank CCB Indonesia',
        'swift' => null,
        'aliases' => [
            'CCB Indonesia',
            'Bank CCB',
            'Bank CCB Indonesia',
            'CCBIndonesia',
            'China Construction Bank Indonesia',
            'PT Bank China Construction Bank Indonesia',
            'Bank China Construction',
            'China Construction',
        ],
        'keywords' => [
            'ccb',
            'china construction',
        ],
    ],

    // 已更名 / 合併銀行（保留歷史名稱以匹配舊代碼）
    [
        'code' => 'IDR_BUMIPUTERA',
        'retired_into' => 'IDR_MNCINTERNASIONAL', // 2014 年改名 Bank MNC Internasional
        'standard_name' => '(IDR) - Bank Bumiputera',
        'name' => 'Bank Bumiputera',
        'swift' => null,
        'aliases' => [
            'Bank Bumiputera',
            'Bumiputera',
            'Bank ICB Bumiputera',
            'ICB Bumiputera',
            'PT Bank Bumiputera Indonesia',
        ],
        'keywords' => [
            'bumiputera',
        ],
    ],
    [
        'code' => 'IDR_EKONOMI',
        'retired_into' => 'IDR_HSBC', // 2017 年整併為 Bank HSBC Indonesia
        'standard_name' => '(IDR) - Bank Ekonomi Raharja',
        'name' => 'Bank Ekonomi',
        'swift' => null,
        'aliases' => [
            'Bank Ekonomi Raharja',
            'Bank Ekonomi',
            'Ekonomi Raharja',
            'PT Bank Ekonomi Raharja',
        ],
        'keywords' => [
            'ekonomi',
            'raharja',
        ],
    ],
    [
        'code' => 'IDR_HIMPUNANSAUDARA1906',
        'retired_into' => 'IDR_WOORISAUDARA', // 2014 年與 Woori 合併成 Bank Woori Saudara
        'standard_name' => '(IDR) - Bank Himpunan Saudara 1906',
        'name' => 'Bank Himpunan Saudara 1906',
        'swift' => null,
        'aliases' => [
            'Bank Himpunan Saudara 1906',
            'Bank Himpunan Saudara',
            'Himpunan Saudara',
            // 'Bank Saudara' 已讓渡給 Bank Woori Saudara（2014 年與 Woori 合併後的承繼實體）
            'HimpunanSaudara',
        ],
        'keywords' => [
            'himpunan saudara',
            'saudara 1906',
        ],
    ],
    [
        'code' => 'IDR_MULTICOR',
        'retired_into' => 'IDR_CCBINDONESIA', // 2008 年併入 Bank Windu Kentjana，後者 2016 年合併成 CCB Indonesia
        'standard_name' => '(IDR) - Bank Multicor',
        'name' => 'Bank Multicor',
        'swift' => null,
        'aliases' => [
            'Bank Multicor',
            'Multicor',
            'BankMulticor',
            'PT Bank Multicor',
        ],
        'keywords' => [
            'multicor',
        ],
    ],
    [
        'code' => 'IDR_MUTIARA',
        'retired_into' => 'IDR_JTRUSTINDONESIA', // 2014 年由 J Trust 收購，改名 Bank JTrust Indonesia
        'standard_name' => '(IDR) - Bank Mutiara',
        'name' => 'Bank Mutiara',
        'swift' => null,
        'aliases' => [
            'Bank Mutiara',
            'Mutiara',
            'BankMutiara',
            'PT Bank Mutiara',
            'Bank Century',
        ],
        'keywords' => [
            'mutiara',
        ],
    ],
    [
        'code' => 'IDR_QNBKESAWAN',
        'retired_into' => 'IDR_QNBINDONESIA', // 2014 年改名 Bank QNB Indonesia
        'standard_name' => '(IDR) - Bank QNB Kesawan',
        'name' => 'Bank QNB Kesawan',
        'swift' => null,
        'aliases' => [
            'QNB Kesawan',
            'Bank QNB Kesawan',
            'Bank Kesawan',
            'PT Bank QNB Kesawan',
            'PT Bank Kesawan',
        ],
        'keywords' => [
            'kesawan',
            'qnb kesawan',
        ],
    ],

    // 小型銀行 / BPR
    [
        'code' => 'IDR_ANGLOMASINTERNATIONAL',
        'retired_into' => 'IDR_AMAR', // 2014 年改名 Amar Bank
        'standard_name' => '(IDR) - Anglomas International Bank',
        'name' => 'Anglomas International Bank',
        'swift' => null,
        'aliases' => [
            'Anglomas International Bank',
            'Bank Anglomas',
            'Anglomas',
            'AIB',
        ],
        'keywords' => [
            'anglomas',
        ],
    ],
    [
        'code' => 'IDR_ANDARA',
        'retired_into' => 'IDR_OKEINDONESIA', // 2017 年改名 Bank Oke Indonesia
        'standard_name' => '(IDR) - Bank Andara',
        'name' => 'Bank Andara',
        'swift' => null,
        'aliases' => [
            'Bank Andara',
            'BankAndara',
            'Andara',
            'PT Bank Andara',
        ],
        'keywords' => [
            'andara',
        ],
    ],
    [
        'code' => 'IDR_ARTANIAGAKENCANA',
        'standard_name' => '(IDR) - Bank Arta Niaga Kencana',
        'name' => 'Bank Arta Niaga Kencana',
        'swift' => null,
        'aliases' => [
            'Bank Arta Niaga Kencana',
            'Arta Niaga Kencana',
            'ArtaNiagaKencana',
            'Bank ANK',
        ],
        'keywords' => [
            'arta niaga',
            'niaga kencana',
        ],
    ],
    [
        'code' => 'IDR_BISNISINTERNASIONAL',
        'standard_name' => '(IDR) - Bank Bisnis Internasional',
        'name' => 'Bank Bisnis Internasional',
        'swift' => null,
        'aliases' => [
            'Bank Bisnis Internasional',
            'Bank Bisnis',
            'Bisnis Internasional',
            'BBI',
        ],
        'keywords' => [
            'bisnis internasional',
        ],
    ],
    [
        'code' => 'IDR_EKA',
        'standard_name' => '(IDR) - Bank EKA',
        'name' => 'Bank EKA',
        'swift' => null,
        'aliases' => [
            'Bank EKA',
            'BPR EKA',
            'Bank EKA Bumi Artha',
            'EKA',
        ],
        'keywords' => [
            'eka',
        ],
    ],
    [
        'code' => 'IDR_METROEXPRESS',
        'retired_into' => 'IDR_SHINHAN', // 2016 年合併成 Bank Shinhan Indonesia
        'standard_name' => '(IDR) - Bank Metro Express',
        'name' => 'Bank Metro Express',
        'swift' => null,
        'aliases' => [
            'Bank Metro Express',
            'Metro Express',
            'BankMetroExpress',
            'PT Bank Metro Express',
        ],
        'keywords' => [
            'metro express',
        ],
    ],
    [
        'code' => 'IDR_MITRANIAGA',
        'retired_into' => 'IDR_IBKINDONESIA', // 2019 年與 Bank Agris 合併成 Bank IBK Indonesia
        'standard_name' => '(IDR) - Bank Mitra Niaga',
        'name' => 'Bank Mitra Niaga',
        'swift' => null,
        'aliases' => [
            'Bank Mitra Niaga',
            'Mitra Niaga',
            'BankMitraNiaga',
            'Mitraniaga',
        ],
        'keywords' => [
            'mitra niaga',
        ],
    ],

    // 地方發展銀行（BPD）
    [
        'code' => 'IDR_BENGKULU',
        'standard_name' => '(IDR) - Bank Bengkulu',
        'name' => 'Bank Bengkulu',
        'swift' => null,
        'aliases' => [
            'Bank Bengkulu',
            'Bengkulu',
            'BPD Bengkulu',
            'Bank BPD Bengkulu',
        ],
        'keywords' => [
            'bengkulu',
        ],
    ],
    [
        'code' => 'IDR_BPDKALIMANTANSELATANUUS',
        'standard_name' => '(IDR) - BPD Kalimantan Selatan UUS',
        'name' => 'BPD Kalimantan Selatan UUS',
        'swift' => null,
        'aliases' => [
            'BPD Kalimantan Selatan UUS',
            'Bank Kalsel UUS',
            'Bank Kalsel Syariah',
            'BPD Kalsel Syariah',
            'BPD Kalimantan Selatan Syariah',
        ],
        'keywords' => [
            'kalsel uus',
            'kalimantan selatan uus',
        ],
    ],

    // Syariah / UUS（伊斯蘭金融單位）
    [
        'code' => 'IDR_AGRISUUS',
        'retired_into' => 'IDR_IBKINDONESIA', // Bank Agris 2019 年與 Mitraniaga 合併成 Bank IBK Indonesia
        'standard_name' => '(IDR) - Bank Agris UUS',
        'name' => 'Bank Agris UUS',
        'swift' => null,
        'aliases' => [
            'Bank Agris UUS',
            'Bank Agris Syariah',
            'Agris UUS',
        ],
        'keywords' => [
            'agris uus',
            'agris syariah',
        ],
    ],

    // 支付清算機構（非銀行，TopPay 將其列入銀行清單）
    [
        'code' => 'IDR_ARTAJASAPEMBAYARANELEKTRONIS',
        'standard_name' => '(IDR) - Artajasa Pembayaran Elektronis',
        'name' => 'Artajasa Pembayaran Elektronis',
        'swift' => null,
        'aliases' => [
            'Artajasa Pembayaran Elektronis',
            'Artajasa',
            'ATM Bersama',
            'PT Artajasa Pembayaran Elektronis',
        ],
        'keywords' => [
            'artajasa',
        ],
    ],

    // ── mis-match 修正：Syariah / UUS 各銀行獨立 entry ──
    [
        'code' => 'IDR_BCASYARIAH',
        'standard_name' => '(IDR) - Bank BCA Syariah',
        'name' => 'Bank BCA Syariah',
        'swift' => null,
        'aliases' => [
            'Bank BCA Syariah',
            'BCA Syariah',
            'BankBCASyariah',
            'BCASyariah',
            'PT Bank BCA Syariah',
            'Bank Central Asia Syariah',
            'Central Asia Syariah',
            'PT Bank Central Asia Syariah',
        ],
        'keywords' => [
            'bca syariah',
            'central asia syariah',
        ],
    ],
    [
        'code' => 'IDR_BNISYARIAH',
        'retired_into' => 'IDR_SYARIAHINDONESIA', // 2021 年合併成 Bank Syariah Indonesia
        'standard_name' => '(IDR) - Bank BNI Syariah',
        'name' => 'Bank BNI Syariah',
        'swift' => null,
        'aliases' => [
            'Bank BNI Syariah',
            'BNI Syariah',
            'BankBNISyariah',
            'BNISyariah',
            'PT Bank BNI Syariah',
            'Bank Negara Indonesia Syariah',
            'Negara Indonesia Syariah',
            'PT Bank Negara Indonesia Syariah',
        ],
        'keywords' => [
            'bni syariah',
            'negara indonesia syariah',
        ],
    ],
    [
        'code' => 'IDR_BRISYARIAH',
        'retired_into' => 'IDR_SYARIAHINDONESIA', // 2021 年合併成 Bank Syariah Indonesia
        'standard_name' => '(IDR) - Bank BRI Syariah',
        'name' => 'Bank BRI Syariah',
        'swift' => null,
        'aliases' => [
            'Bank BRI Syariah',
            'BRI Syariah',
            'BankBRISyariah',
            'BRISyariah',
            'PT Bank BRI Syariah',
            'Bank Rakyat Indonesia Syariah',
            'Rakyat Indonesia Syariah',
            'PT Bank Rakyat Indonesia Syariah',
        ],
        'keywords' => [
            'bri syariah',
            'rakyat indonesia syariah',
        ],
    ],
    [
        'code' => 'IDR_BTPNSYARIAH',
        'standard_name' => '(IDR) - Bank BTPN Syariah',
        'name' => 'Bank BTPN Syariah',
        'swift' => null,
        'aliases' => [
            'Bank BTPN Syariah',
            'BTPN Syariah',
            'BankBTPNSyariah',
            'PT Bank BTPN Syariah',
        ],
        'keywords' => [
            'btpn syariah',
        ],
    ],
    [
        'code' => 'IDR_SYARIAHBUKOPIN',
        'standard_name' => '(IDR) - Bank Syariah Bukopin',
        'name' => 'Bank Syariah Bukopin',
        'swift' => null,
        'aliases' => [
            'Bank Syariah Bukopin',
            'Syariah Bukopin',
            'BankSyariahBukopin',
            'KB Bukopin Syariah',
            'Bank KB Bukopin Syariah',
        ],
        'keywords' => [
            'bukopin syariah',
        ],
    ],
    [
        'code' => 'IDR_SYARIAHMANDIRI',
        'retired_into' => 'IDR_SYARIAHINDONESIA', // 2021 年合併成 Bank Syariah Indonesia
        'standard_name' => '(IDR) - Bank Syariah Mandiri',
        'name' => 'Bank Syariah Mandiri',
        'swift' => null,
        'aliases' => [
            'Bank Syariah Mandiri',
            'Syariah Mandiri',
            'BankSyariahMandiri',
            'BSM',
            'PT Bank Syariah Mandiri',
        ],
        'keywords' => [
            'syariah mandiri',
            'bsm',
        ],
    ],
    [
        'code' => 'IDR_MAYBANKSYARIAHINDONESIA',
        'retired_into' => 'IDR_ALADINSYARIAH', // 2019 年改名 Bank Net Indonesia Syariah，2021 年再改名 Bank Aladin Syariah；「Maybank Syariah」現指 Maybank Indonesia 的伊斯蘭業務單位
        'standard_name' => '(IDR) - Bank Maybank Syariah Indonesia',
        'name' => 'Bank Maybank Syariah Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Maybank Syariah Indonesia',
            'Maybank Syariah Indonesia',
            'Maybank Syariah',
            'BankMaybankSyariah',
            'PT Bank Maybank Syariah',
        ],
        'keywords' => [
            'maybank syariah',
        ],
    ],
    [
        'code' => 'IDR_MAYBANKSYARIAHINDONESIAUUS',
        'standard_name' => '(IDR) - Bank Maybank Syariah Indonesia UUS',
        'name' => 'Bank Maybank Syariah Indonesia UUS',
        'swift' => null,
        'aliases' => [
            'Bank Maybank Syariah Indonesia UUS',
            'Maybank Syariah Indonesia UUS',
            'Maybank UUS',
            'Maybank Syariah',
            'UUS Maybank Indonesia',
        ],
        'keywords' => [
            'maybank uus',
        ],
    ],
    [
        'code' => 'IDR_MEGASYARIAH',
        'standard_name' => '(IDR) - Bank Mega Syariah',
        'name' => 'Bank Mega Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Mega Syariah',
            'Mega Syariah',
            'BankMegaSyariah',
            'PT Bank Mega Syariah',
        ],
        'keywords' => [
            'mega syariah',
        ],
    ],
    [
        'code' => 'IDR_CIMBNIAGASYARIAH',
        'standard_name' => '(IDR) - Bank CIMB Niaga Syariah',
        'name' => 'Bank CIMB Niaga Syariah',
        'swift' => null,
        'aliases' => [
            'CIMB Niaga Syariah',
            'Bank CIMB Niaga Syariah',
            'CIMBNiagaSyariah',
            'CIMB Syariah',
        ],
        'keywords' => [
            'cimb syariah',
            'niaga syariah',
        ],
    ],
    [
        'code' => 'IDR_PANINDUBAISYARIAH',
        'standard_name' => '(IDR) - Bank Panin Dubai Syariah',
        'name' => 'Bank Panin Dubai Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Panin Syariah',
            'Bank Panin Dubai Syariah',
            'Panin Syariah',
            'Panin Dubai Syariah',
            'BankPaninSyariah',
            'PT Bank Panin Dubai Syariah',
        ],
        'keywords' => [
            'panin syariah',
            'panin dubai',
        ],
    ],
    [
        'code' => 'IDR_VICTORIASYARIAH',
        'standard_name' => '(IDR) - Bank Victoria Syariah',
        'name' => 'Bank Syariah Nasional',
        'swift' => null,
        'aliases' => [
            'Bank Victoria Syariah',
            'Victoria Syariah',
            'BankVictoriaSyariah',
            'PT Bank Victoria Syariah',
            'PT Bank Syariah Nasional',
        ],
        'keywords' => [
            'victoria syariah',
        ],
    ],
    [
        'code' => 'IDR_JABARBANTENSYARIAH',
        'standard_name' => '(IDR) - Bank Jabar Banten Syariah',
        'name' => 'Bank Jabar Banten Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Jabar Banten Syariah',
            'BJB Syariah',
            'Bank BJB Syariah',
            'Jabar Banten Syariah',
            'JabarBantenSyariah',
        ],
        'keywords' => [
            'jabar banten syariah',
            'bjb syariah',
        ],
    ],
    [
        'code' => 'IDR_BPDDIYUUS',
        'standard_name' => '(IDR) - Bank BPD DIY UUS',
        'name' => 'Bank BPD DIY UUS',
        'swift' => null,
        'aliases' => [
            'Bank Pembangunan Daerah DIY Unit Usaha Syariah',
            'Bank BPD DIY UUS',
            'BPD DIY UUS',
            'BPD DIY Syariah',
            'Bank DIY Syariah',
        ],
        'keywords' => [
            'diy uus',
            'diy syariah',
        ],
    ],
    [
        'code' => 'IDR_BPDJAWATENGAHUUS',
        'standard_name' => '(IDR) - BPD Jawa Tengah UUS',
        'name' => 'BPD Jawa Tengah UUS',
        'swift' => null,
        'aliases' => [
            'BPD JAWA TENGAH UNIT USAHA SYARIAH',
            'BPD Jawa Tengah UUS',
            'BPD Jawa Tengah Syariah',
            'Bank Jateng Syariah',
            'Bank Jateng UUS',
        ],
        'keywords' => [
            'jateng uus',
            'jateng syariah',
        ],
    ],
    [
        'code' => 'IDR_PERMATAUUS',
        'retired_into' => 'IDR_PERMATASYARIAH', // 與 Bank Permata Syariah 是同一個伊斯蘭業務單位
        'standard_name' => '(IDR) - Bank Permata UUS',
        'name' => 'Bank Permata UUS',
        'swift' => null,
        'aliases' => [
            'Bank Permata UUS',
            'Permata UUS',
            'Permata Unit Usaha Syariah',
        ],
        'keywords' => [
            'permata uus',
        ],
    ],

    // ── mis-match 修正：被誤配到完全不同銀行的條目 ──
    [
        'code' => 'IDR_BTN',
        'standard_name' => '(IDR) - Bank BTN',
        'name' => 'Bank BTN',
        'swift' => null,
        'aliases' => [
            'Bank BTN',
            'BTN',
            'BankBTN',
            'Bank Tabungan Negara',
            'Bank Tabungan Negara Persero',
            'PT Bank Tabungan Negara',
        ],
        'keywords' => [
            'btn',
            'tabungan negara',
        ],
    ],
    [
        'code' => 'IDR_TABUNGANNEGARAUUS',
        'retired_into' => 'IDR_VICTORIASYARIAH', // 2025 年分拆併入 Bank Victoria Syariah，後者改名 Bank Syariah Nasional
        'standard_name' => '(IDR) - Bank Tabungan Negara UUS',
        'name' => 'Bank Tabungan Negara UUS',
        'swift' => null,
        'aliases' => [
            'Bank Tabungan Negara (BTN) UUS',
            'BTN UUS',
            'Bank BTN UUS',
            'Bank BTN Syariah',
            'BTN Syariah',
        ],
        'keywords' => [
            'btn uus',
            'btn syariah',
        ],
    ],
    [
        'code' => 'IDR_BTPN',
        'standard_name' => '(IDR) - Bank BTPN',
        'name' => 'Bank SMBC Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank BTPN',
            'BTPN',
            'BankBTPN',
            'PT Bank BTPN',
            'Bank Tabungan Pensiunan Nasional',
            'PT Bank SMBC Indonesia Tbk',
        ],
        'keywords' => [
            'btpn',
            'pensiunan',
        ],
    ],
    [
        'code' => 'IDR_CIMBNIAGAUUS',
        'retired_into' => 'IDR_CIMBNIAGASYARIAH', // 與 CIMB Niaga Syariah 是同一個伊斯蘭業務單位
        'standard_name' => '(IDR) - Bank CIMB Niaga UUS',
        'name' => 'Bank CIMB Niaga UUS',
        'swift' => null,
        'aliases' => [
            'Bank CIMB Niaga UUS',
            'CIMB Niaga UUS',
            'CIMB UUS',
        ],
        'keywords' => [
            'cimb uus',
            'niaga uus',
        ],
    ],
    [
        'code' => 'IDR_CIMBNIAGAREKENINGPONSEL',
        'standard_name' => '(IDR) - Bank CIMB Niaga Rekening Ponsel',
        'name' => 'Bank CIMB Niaga Rekening Ponsel',
        'swift' => null,
        'aliases' => [
            'Bank CIMB Niaga Rekening Ponsel',
            'CIMB Niaga Rekening Ponsel',
            'CIMB Rekening Ponsel',
            'Rekening Ponsel',
        ],
        'keywords' => [
            'rekening ponsel',
        ],
    ],
    [
        'code' => 'IDR_CENTRATAMA',
        'retired_into' => 'IDR_SHINHAN', // 2016 年合併成 Bank Shinhan Indonesia
        'standard_name' => '(IDR) - Bank Centratama',
        'name' => 'Bank Centratama',
        'swift' => null,
        'aliases' => [
            'Bank Centratama',
            'Centratama',
            'BankCentratama',
            'Bank Centratama Nasional',
            'PT Bank Centratama Nasional',
        ],
        'keywords' => [
            'centratama',
        ],
    ],
    [
        'code' => 'IDR_DIPOINTERNATIONAL',
        'standard_name' => '(IDR) - Bank DIPO International',
        'name' => 'Bank DIPO International',
        'swift' => null,
        'aliases' => [
            'Bank DIPO International',
            'Bank DIPO Internasional',
            'DIPO International',
            'DIPO Internasional',
            'Bank DIPO',
        ],
        'keywords' => [
            'dipo',
        ],
    ],
    [
        'code' => 'IDR_FAMAINTERNATIONAL',
        'standard_name' => '(IDR) - Bank Fama International',
        'name' => 'Super Bank Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Fama International',
            'Bank Fama Internasional',
            'Fama International',
            'BankFamaInternational',
            'Superbank',
            'PT Super Bank Indonesia',
        ],
        'keywords' => [
            'fama',
        ],
    ],
    [
        'code' => 'IDR_KALBAR',
        'standard_name' => '(IDR) - Bank Kalbar',
        'name' => 'Bank Kalbar',
        'swift' => null,
        'aliases' => [
            'BPD Kalimantan Barat UUS',
            'BPD Kalimantan Barat',
            'Bank Kalbar',
            'BPD Kalbar',
            'Bank Kalimantan Barat',
        ],
        'keywords' => [
            'kalbar',
            'kalimantan barat',
        ],
    ],
    // 伊斯蘭業務單位（UUS）三條：金流商給它們獨立的三方碼（例：TopPay Kalbar 123、Kalbar UUS 1230），
    // 但各金流商的碼還沒逐家查清，先只寫進 standard_banks，cash-flow:sync 照舊用一般戶條目比對
    // （理由見 StandardBankRegistry）。一般戶條目上的 UUS 別名，寫進 standard_banks 時會改歸這裡。
    [
        'code' => 'IDR_KALBARUUS',
        'sync_visible' => false,
        'standard_name' => '(IDR) - BPD Kalimantan Barat UUS',
        'name' => 'BPD Kalimantan Barat UUS',
        'swift' => null,
        'aliases' => [
            'BPD Kalimantan Barat UUS',
            'Bank Kalbar UUS',
        ],
        'keywords' => [
            'kalbar uus',
            'kalimantan barat uus',
        ],
    ],
    [
        'code' => 'IDR_KALTIM',
        'standard_name' => '(IDR) - Bank Kaltim',
        'name' => 'Bank Kaltim',
        'swift' => null,
        'aliases' => [
            'BPD Kalimantan Timur UUS',
            'BPD Kalimantan Timur',
            'Bank Kaltim',
            'BPD Kaltim',
            'Bank BPD Kaltim',
            'Bank Kalimantan Timur',
            'Bank Kaltimtara',
        ],
        'keywords' => [
            'kaltim',
            'kalimantan timur',
        ],
    ],
    [
        'code' => 'IDR_KALTIMUUS',
        'sync_visible' => false,
        'standard_name' => '(IDR) - BPD Kalimantan Timur UUS',
        'name' => 'BPD Kalimantan Timur UUS',
        'swift' => null,
        'aliases' => [
            'BPD Kalimantan Timur UUS',
            'Bank Kaltim UUS',
            'Bank Kaltimtara UUS',
        ],
        'keywords' => [
            'kaltim uus',
            'kalimantan timur uus',
        ],
    ],
    [
        'code' => 'IDR_OKEINDONESIA',
        'standard_name' => '(IDR) - Bank Oke Indonesia',
        'name' => 'Bank Oke Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Oke Indonesia',
            'Bank OK',
            'Bank Oke',
            'BankOkeIndonesia',
            'PT Bank Oke Indonesia',
        ],
        'keywords' => [
            'oke indonesia',
            'bank oke',
        ],
    ],
    [
        'code' => 'IDR_BANTEN',
        'standard_name' => '(IDR) - Bank Banten',
        'name' => 'Bank Banten',
        'swift' => null,
        'aliases' => [
            'Bank Banten',
            'Bank Pundi Indonesia',
            'Bank Pundi',
            'BPD Banten',
            'Bank Pembangunan Daerah Banten',
        ],
        'keywords' => [
            'bank banten',
            'pundi',
        ],
    ],
    [
        'code' => 'IDR_PURBADANARTA',
        'standard_name' => '(IDR) - Bank Purba Danarta',
        'name' => 'Bank Purba Danarta',
        'swift' => null,
        'aliases' => [
            'Bank Purba Danarta',
            'Purba Danarta',
            'BankPurbaDanarta',
            'PT Bank Purba Danarta',
        ],
        'keywords' => [
            'purba danarta',
            'purba',
        ],
    ],
    [
        'code' => 'IDR_QNBINDONESIA',
        'standard_name' => '(IDR) - Bank QNB Indonesia',
        'name' => 'Bank QNB Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank QNB Indonesia',
            'Bank QNB indonesia',
            'QNB Indonesia',
            'QNB',
            'PT Bank QNB Indonesia',
        ],
        'keywords' => [
            'qnb indonesia',
            'qnb',
        ],
    ],

    // ── 補 IDR no-match：主流商業銀行 ──
    [
        'code' => 'IDR_MEGA',
        'standard_name' => '(IDR) - Bank Mega',
        'name' => 'Bank Mega',
        'swift' => null,
        'aliases' => [
            'Bank Mega',
            'BankMega',
            'Mega',
            'PT Bank Mega Tbk',
        ],
        'keywords' => [
            'bank mega',
        ],
    ],
    [
        'code' => 'IDR_SINARMAS',
        'standard_name' => '(IDR) - Bank Sinarmas',
        'name' => 'Bank Sinarmas',
        'swift' => null,
        'aliases' => [
            'Bank Sinarmas',
            'BankSinarmas',
            'Sinarmas',
            'Sinar Mas',
            'PT Bank Sinarmas Tbk',
        ],
        'keywords' => [
            'sinarmas',
            'sinar mas',
        ],
    ],
    [
        // 涵蓋 bank_id 713 與 828（重複 row）
        'code' => 'IDR_CTBCINDONESIA',
        'standard_name' => '(IDR) - Bank CTBC Indonesia',
        'name' => 'Bank CTBC Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank CTBC Indonesia',
            'CTBC Indonesia',
            'CTBC',
            'PT Bank CTBC Indonesia',
            'Bank Chinatrust Indonesia',
        ],
        'keywords' => [
            'ctbc',
            'chinatrust',
        ],
    ],
    [
        // 涵蓋 bank_id 716 與 799（重複 row）
        'code' => 'IDR_KEBHANA',
        'standard_name' => '(IDR) - Bank KEB Hana',
        'name' => 'Bank KEB Hana',
        'swift' => null,
        'aliases' => [
            'Bank KEB Hana',
            'KEB Hana',
            'Bank Hana',
            'Hana Bank',
            'PT Bank KEB Hana Indonesia',
            'KEB Hana Indonesia',
            'LINE Bank / KEB Hana',
            'LINE Bank',
            'LINE Bank by Hana Bank',
            'LINE Bank by Hana Bank Indonesia',
        ],
        'keywords' => [
            'keb hana',
            'hana',
            'line bank',
        ],
    ],
    [
        // 涵蓋 bank_id 717 與 800（重複 row）
        'code' => 'IDR_MNCINTERNASIONAL',
        'standard_name' => '(IDR) - Bank MNC Internasional',
        'name' => 'Bank MNC Internasional',
        'swift' => null,
        'aliases' => [
            'Bank MNC Internasional',
            'PT Bank MNC Internasional',
            'MNC Internasional',
            'Bank MNC',
            'MNC Bank',
        ],
        'keywords' => [
            'mnc',
        ],
    ],
    [
        // 涵蓋 bank_id 720 與 814（重複 row）
        'code' => 'IDR_SEABANKINDONESIA',
        'standard_name' => '(IDR) - Bank Seabank Indonesia',
        'name' => 'Bank Seabank Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Seabank Indonesia',
            'Seabank Indonesia',
            'SeaBank',
            'Sea Bank',
            'Bank Seabank',
            'PT Bank Seabank Indonesia',
        ],
        'keywords' => [
            'seabank',
            'sea bank',
        ],
    ],
    [
        'code' => 'IDR_NUSANTARAPARAHYANGAN',
        'retired_into' => 'IDR_DANAMON', // 2019 年併入 Danamon
        'standard_name' => '(IDR) - Bank Nusantara Parahyangan',
        'name' => 'Bank Nusantara Parahyangan',
        'swift' => null,
        'aliases' => [
            'Bank Nusantara Parahyangan',
            'Nusantara Parahyangan',
            'PT Bank Nusantara Parahyangan',
        ],
        'keywords' => [
            'nusantara parahyangan',
            'parahyangan',
        ],
    ],
    [
        'code' => 'IDR_BANKOFINDIAINDONESIA',
        'standard_name' => '(IDR) - Bank of India Indonesia',
        'name' => 'Bank of India Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank of India Indonesia',
            'Bank of India',
            'BOI Indonesia',
            'BOII',
            'PT Bank of India Indonesia',
        ],
        'keywords' => [
            'bank of india',
            'india indonesia',
        ],
    ],
    [
        'code' => 'IDR_MUAMALAT',
        'standard_name' => '(IDR) - Bank Muamalat',
        'name' => 'Bank Muamalat',
        'swift' => null,
        'aliases' => [
            'Bank Muamalat',
            'Muamalat',
            'Muamalat Indonesia',
            'BankMuamalat',
            'PT Bank Muamalat Indonesia',
        ],
        'keywords' => [
            'muamalat',
        ],
    ],
    [
        'code' => 'IDR_MESTIKA',
        'standard_name' => '(IDR) - Bank Mestika',
        'name' => 'Bank Mestika',
        'swift' => null,
        'aliases' => [
            'Bank Mestika',
            'Mestika',
            'Bank Mestika Dharma',
            'PT Bank Mestika Dharma Tbk',
        ],
        'keywords' => [
            'mestika',
        ],
    ],
    [
        'code' => 'IDR_SHINHAN',
        'standard_name' => '(IDR) - Bank Shinhan',
        'name' => 'Bank Shinhan',
        'swift' => null,
        'aliases' => [
            'Bank Shinhan',
            'Shinhan',
            'Bank Shinhan Indonesia',
            'Shinhan Indonesia',
            'PT Bank Shinhan Indonesia',
        ],
        'keywords' => [
            'shinhan',
        ],
    ],
    [
        'code' => 'IDR_MASPION',
        'standard_name' => '(IDR) - Bank Maspion',
        'name' => 'Bank Maspion',
        'swift' => null,
        'aliases' => [
            'Bank Maspion',
            'Maspion',
            'Bank Maspion Indonesia',
            'PT Bank Maspion Indonesia Tbk',
        ],
        'keywords' => [
            'maspion',
        ],
    ],
    [
        'code' => 'IDR_GANESHA',
        'standard_name' => '(IDR) - Bank Ganesha',
        'name' => 'Bank Ganesha',
        'swift' => null,
        'aliases' => [
            'Bank Ganesha',
            'Ganesha',
            'PT Bank Ganesha Tbk',
        ],
        'keywords' => [
            'ganesha',
        ],
    ],
    [
        'code' => 'IDR_ICBC',
        'standard_name' => '(IDR) - Bank ICBC Indonesia',
        'name' => 'Bank ICBC Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank ICBC',
            'Bank ICBC Indonesia',
            'ICBC',
            'ICBC Indonesia',
            'Industrial and Commercial Bank of China',
            'PT Bank ICBC Indonesia',
        ],
        'keywords' => [
            'icbc',
            'industrial and commercial',
        ],
    ],
    [
        'code' => 'IDR_WOORISAUDARA',
        'standard_name' => '(IDR) - Bank Woori Saudara',
        'name' => 'Bank Woori Saudara',
        'swift' => null,
        'aliases' => [
            'Bank Woori Saudara',
            'Woori Saudara',
            'Bank Woori Saudara Indonesia 1906',
            'Bank Saudara',
            'PT Bank Woori Saudara Indonesia 1906 Tbk',
        ],
        'keywords' => [
            'woori',
            'saudara',
        ],
    ],
    [
        'code' => 'IDR_BUKOPIN',
        'standard_name' => '(IDR) - Bank KB Bukopin',
        'name' => 'Bank Bukopin',
        'swift' => null,
        'aliases' => [
            'Bank Bukopin',
            'Bukopin',
            'Bank KB Bukopin',
            'KB Bukopin',
            'PT Bank KB Bukopin Tbk',
            'KB Bank',
        ],
        'keywords' => [
            'bukopin',
        ],
    ],
    [
        'code' => 'IDR_JASAJAKARTA',
        'standard_name' => '(IDR) - Bank Jasa Jakarta',
        'name' => 'Bank Jasa Jakarta',
        'swift' => null,
        'aliases' => [
            'Bank Jasa Jakarta',
            'Jasa Jakarta',
            'BJJ',
            'PT Bank Jasa Jakarta',
        ],
        'keywords' => [
            'jasa jakarta',
        ],
    ],
    [
        'code' => 'IDR_NEOCOMMERCE',
        'standard_name' => '(IDR) - Bank Neo Commerce',
        'name' => 'Bank Neo Commerce',
        'swift' => null,
        'aliases' => [
            'Bank Neo Commerce',
            'Neo Commerce',
            'BNC',
            'Bank Neo',
            'PT Bank Neo Commerce Tbk',
            // Yes2Win 舊列實名（改名前身 Bank Yudha Bhakti 併記，2022 更名）
            'Bank Yudha Bhakti/ Bank Neo Commerce',
            'Bank Yudha Bhakti',
        ],
        'keywords' => [
            'neo commerce',
            'neo',
            'yudha',
        ],
    ],
    [
        'code' => 'IDR_RAYAINDONESIA',
        'standard_name' => '(IDR) - Bank Raya Indonesia',
        'name' => 'Bank Raya Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Raya Indonesia',
            'Bank Raya',
            'Bank Rakyat Indonesia Agroniaga',
            'BRI Agroniaga',
            'BRI Agro',
            'PT Bank Raya Indonesia Tbk',
            'Bank Agroniaga',
        ],
        'keywords' => [
            'raya indonesia',
            'agroniaga',
            'bank raya',
        ],
    ],
    [
        'code' => 'IDR_SBIINDONESIA',
        'standard_name' => '(IDR) - Bank SBI Indonesia',
        'name' => 'Bank SBI Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank SBI Indonesia',
            'Bank SBI Indonesia (Indomonex)',
            'SBI Indonesia',
            'Bank Indomonex',
            'Indomonex',
            'PT Bank SBI Indonesia',
        ],
        'keywords' => [
            'sbi indonesia',
            'indomonex',
        ],
    ],
    [
        // Bank Royal 已被 BCA 收購並改名為 BCA Digital (Blu by BCA)；
        // 保留 Bank Royal 作為 alias，方便 feed 對齊舊名
        'code' => 'IDR_ROYAL',
        'standard_name' => '(IDR) - Bank Royal',
        'name' => 'Bank Royal',
        'swift' => null,
        'aliases' => [
            'Bank Royal',
            'Bank Royal Indonesia',
            'PT Bank Royal Indonesia',
            'BCA Digital',
            'Blu by BCA',
            'Blu BCA',
            'Blu / Bank Central Asia Digital',
            'Bank Central Asia Digital',
            'Central Asia Digital',
            'Blu',
            'Bank Digital BCA',
            'PT Bank Digital BCA',
        ],
        'keywords' => [
            'bank royal',
            'bca digital',
            'digital bca',
            'central asia digital',
        ],
    ],
    [
        'code' => 'IDR_NATIONALNOBU',
        'standard_name' => '(IDR) - Bank Nationalnobu',
        'name' => 'Bank Nationalnobu',
        'swift' => null,
        'aliases' => [
            'Bank National Nobu',
            'Bank Nationalnobu',
            'Nobu Bank',
            'Nobu National Bank',
            'PT Bank Nationalnobu Tbk',
        ],
        'keywords' => [
            'nobu',
            'nationalnobu',
        ],
    ],
    [
        'code' => 'IDR_INA',
        'standard_name' => '(IDR) - Bank INA Perdana',
        'name' => 'Bank INA Perdana',
        'swift' => null,
        'aliases' => [
            'Bank INA',
            'Bank INA Perdana',
            'INA Perdana',
            'PT Bank INA Perdana Tbk',
        ],
        'keywords' => [
            'bank ina',
            'ina perdana',
        ],
    ],
    [
        'code' => 'IDR_PRIMAMASTER',
        'standard_name' => '(IDR) - Prima Master Bank',
        'name' => 'Prima Master Bank',
        'swift' => null,
        'aliases' => [
            'Prima Master Bank',
            'Bank Prima Master',
            'PT Prima Master Bank',
        ],
        'keywords' => [
            'prima master',
        ],
    ],
    [
        'code' => 'IDR_SAHABATSAMPOERNA',
        'standard_name' => '(IDR) - Bank Sahabat Sampoerna',
        'name' => 'Bank Sahabat Sampoerna',
        'swift' => null,
        'aliases' => [
            'Bank Sahabat Sampoerna',
            'Sahabat Sampoerna',
            'Bank Sampoerna',
            'PT Bank Sahabat Sampoerna',
        ],
        'keywords' => [
            'sahabat sampoerna',
            'sampoerna',
        ],
    ],
    [
        'code' => 'IDR_DINAR',
        'retired_into' => 'IDR_OKEINDONESIA', // 2019 年併入 Bank Oke Indonesia
        'standard_name' => '(IDR) - Bank Dinar Indonesia',
        'name' => 'Bank Dinar',
        'swift' => null,
        'aliases' => [
            'Bank Dinar',
            'Bank Dinar Indonesia',
            'PT Bank Dinar Indonesia Tbk',
        ],
        'keywords' => [
            'bank dinar',
            'dinar',
        ],
    ],
    [
        'code' => 'IDR_MULTIARTASENTOSA',
        'standard_name' => '(IDR) - Bank Multiarta Sentosa',
        'name' => 'Bank Multiarta Sentosa',
        'swift' => null,
        'aliases' => [
            'Bank Multiarta Sentosa',
            'Multiarta Sentosa',
            'Bank MAS',
            'PT Bank Multiarta Sentosa',
        ],
        'keywords' => [
            'multiarta sentosa',
            'multiarta',
        ],
    ],
    [
        'code' => 'IDR_MAYORA',
        'standard_name' => '(IDR) - Bank Mayora',
        'name' => 'Bank Mayora',
        'swift' => null,
        'aliases' => [
            'Bank Mayora',
            'Mayora',
            'PT Bank Mayora',
        ],
        'keywords' => [
            'mayora',
        ],
    ],
    [
        'code' => 'IDR_INDEXSELINDO',
        'standard_name' => '(IDR) - Bank Index Selindo',
        'name' => 'Bank Index Selindo',
        'swift' => null,
        'aliases' => [
            'Bank Index',
            'Bank Index Selindo',
            'Index Selindo',
            'PT Bank Index Selindo',
        ],
        'keywords' => [
            'bank index',
            'index selindo',
        ],
    ],
    [
        // Bank CNB = Bank Capital Nusantara / Bank Capital Indonesia 之合稱
        'code' => 'IDR_CNB',
        'retired_into' => 'IDR_SHINHAN', // Centratama Nasional Bank，2016 年合併成 Bank Shinhan Indonesia
        'standard_name' => '(IDR) - Bank CNB',
        'name' => 'Bank CNB',
        'swift' => null,
        'aliases' => [
            'CNB',
            'Bank CNB',
            'Bank Capital Nusantara',
            'PT Bank CNB',
        ],
        'keywords' => [
            'bank cnb',
            'capital nusantara',
        ],
    ],
    [
        // Bank Mantap = Bank Mandiri Taspen（舊名 Bank Mandiri Taspen Pos）
        'code' => 'IDR_MANTAP',
        'standard_name' => '(IDR) - Bank Mandiri Taspen',
        'name' => 'Bank Mantap',
        'swift' => null,
        'aliases' => [
            'Bank Mantap',
            'Bank Mandiri Taspen',
            'Mandiri Taspen',
            'Mantap',
            'PT Bank Mandiri Taspen',
            'Bank Mandiri Taspen Pos',
            'Mandiri Taspen Pos',
            'PT Bank Mandiri Taspen Pos',
        ],
        'keywords' => [
            'mantap',
            'mandiri taspen',
            'taspen',
        ],
    ],
    [
        'code' => 'IDR_VICTORIAINTERNATIONAL',
        'standard_name' => '(IDR) - Bank Victoria International',
        'name' => 'Bank Victoria International',
        'swift' => null,
        'aliases' => [
            'Bank Victoria Intl',
            'Bank Victoria International',
            'Bank Victoria',
            'Victoria International',
            'PT Bank Victoria International Tbk',
        ],
        'keywords' => [
            'victoria intl',
            'victoria international',
            'bank victoria',
        ],
    ],
    [
        // BPR Karyajatnika Sadaya (民營 Bank Perkreditan Rakyat)
        'code' => 'IDR_BPRKS',
        'standard_name' => '(IDR) - BPR KS',
        'name' => 'BPR KS',
        'swift' => null,
        'aliases' => [
            'BPR KS',
            'BPR Karyajatnika Sadaya',
            'Karyajatnika Sadaya',
            'PT BPR Karyajatnika Sadaya',
        ],
        'keywords' => [
            'bpr ks',
            'karyajatnika',
        ],
    ],
    [
        'code' => 'IDR_ALADINSYARIAH',
        'standard_name' => '(IDR) - Bank Aladin Syariah',
        'name' => 'Bank Aladin Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Aladin Syariah',
            'Aladin Syariah',
            'Bank Aladin',
            'PT Bank Aladin Syariah Tbk',
        ],
        'keywords' => [
            'aladin',
        ],
    ],
    [
        'code' => 'IDR_COMMONWEALTH',
        'retired_into' => 'IDR_OCBCNISP', // 2024 年併入 OCBC Indonesia
        'standard_name' => '(IDR) - Bank Commonwealth',
        'name' => 'Bank Commonwealth',
        'swift' => null,
        'aliases' => [
            'Bank Commonwealth',
            'Commonwealth',
            'Commonwealth Indonesia',
            'PT Bank Commonwealth',
        ],
        'keywords' => [
            'commonwealth',
        ],
    ],

    // ── 補 IDR no-match：地方發展銀行 BPD ──
    [
        'code' => 'IDR_BPDKALTENG',
        'standard_name' => '(IDR) - Bank BPD Kalteng',
        'name' => 'Bank BPD Kalteng',
        'swift' => null,
        'aliases' => [
            'Bank BPD Kalteng',
            'Bank Kalteng',
            'BPD Kalteng',
            'BPD Kalimantan Tengah',
            'Bank Pembangunan Daerah Kalimantan Tengah',
        ],
        'keywords' => [
            'kalteng',
            'kalimantan tengah',
        ],
    ],
    [
        'code' => 'IDR_LAMPUNG',
        'standard_name' => '(IDR) - Bank Lampung',
        'name' => 'Bank Lampung',
        'swift' => null,
        'aliases' => [
            'Bank Lampung',
            'BPD Lampung',
            'Bank BPD Lampung',
            'Bank Pembangunan Daerah Lampung',
        ],
        'keywords' => [
            'lampung',
        ],
    ],
    [
        'code' => 'IDR_KALSEL',
        'standard_name' => '(IDR) - Bank Kalsel',
        'name' => 'Bank Kalsel',
        'swift' => null,
        'aliases' => [
            'Bank Kalsel',
            'BPD Kalsel',
            'Bank BPD Kalsel',
            'BPD Kalimantan Selatan',
            'Bank Pembangunan Daerah Kalimantan Selatan',
        ],
        'keywords' => [
            'kalsel',
            'kalimantan selatan',
        ],
    ],
    [
        'code' => 'IDR_SULSELBAR',
        'standard_name' => '(IDR) - Bank Sulselbar',
        'name' => 'Bank Sulselbar',
        'swift' => null,
        'aliases' => [
            'Bank Sulselbar',
            'BPD Sulselbar',
            'Bank BPD Sulselbar',
            'BPD Sulawesi Selatan dan Barat',
            'Bank Pembangunan Daerah Sulawesi Selatan dan Sulawesi Barat',
        ],
        'keywords' => [
            'sulselbar',
            'sulawesi selatan',
        ],
    ],
    [
        'code' => 'IDR_SULUTGO',
        'standard_name' => '(IDR) - Bank SulutGo',
        'name' => 'Bank SulutGo',
        'swift' => null,
        'aliases' => [
            'Bank Sulut',
            'Bank SulutGo',
            'BPD Sulut',
            'Bank BPD Sulut',
            'BPD Sulawesi Utara',
            'BPD Sulawesi Utara Gorontalo',
            'Bank Pembangunan Daerah Sulawesi Utara',
            'BPD Sulawesi Utara(SulutGo)',
            'BPD Sulawesi Utara (SulutGo)',
            'Bank BPD Sulutgo',
        ],
        'keywords' => [
            'sulut',
            'sulawesi utara',
            'sulutgo',
        ],
    ],
    [
        'code' => 'IDR_BPDBALI',
        'standard_name' => '(IDR) - Bank BPD Bali',
        'name' => 'Bank BPD Bali',
        'swift' => null,
        'aliases' => [
            'Bank BPD Bali',
            'Bank Bali',
            'BPD Bali',
            'Bank Pembangunan Daerah Bali',
        ],
        'keywords' => [
            'bpd bali',
            'bali',
        ],
    ],
    [
        'code' => 'IDR_MALUKUMALUT',
        'standard_name' => '(IDR) - Bank Maluku Malut',
        'name' => 'Bank Maluku Malut',
        'swift' => null,
        'aliases' => [
            'Bank Maluku',
            'Bank Maluku Malut',
            'BPD Maluku',
            'Bank BPD Maluku',
            'Bank Pembangunan Daerah Maluku dan Maluku Utara',
        ],
        'keywords' => [
            'maluku',
        ],
    ],
    [
        'code' => 'IDR_BPDPAPUA',
        'standard_name' => '(IDR) - Bank BPD Papua',
        'name' => 'Bank BPD Papua',
        'swift' => null,
        'aliases' => [
            'Bank BPD Papua',
            'Bank Papua',
            'BPD Papua',
            'Bank Pembangunan Daerah Papua',
        ],
        'keywords' => [
            'bpd papua',
            'papua',
        ],
    ],
    [
        'code' => 'IDR_SULTENG',
        'standard_name' => '(IDR) - Bank Sulteng',
        'name' => 'Bank Sulteng',
        'swift' => null,
        'aliases' => [
            'Bank Sulteng',
            'BPD Sulteng',
            'Bank BPD Sulteng',
            'BPD Sulawesi Tengah',
            'Bank Pembangunan Daerah Sulawesi Tengah',
        ],
        'keywords' => [
            'sulteng',
            'sulawesi tengah',
        ],
    ],
    [
        'code' => 'IDR_SULTRA',
        'standard_name' => '(IDR) - Bank Sultra',
        'name' => 'Bank Sultra',
        'swift' => null,
        'aliases' => [
            'Bank Sultra',
            'BPD Sultra',
            'Bank BPD Sultra',
            'BPD Sulawesi Tenggara',
            'Bank Pembangunan Daerah Sulawesi Tenggara',
        ],
        'keywords' => [
            'sultra',
            'sulawesi tenggara',
        ],
    ],

    // ── 補 IDR Syariah / UUS（依規範獨立 entry，不與母行合併）──
    [
        'code' => 'IDR_SUMSELBABELSYARIAH',
        'standard_name' => '(IDR) - Bank Sumsel Babel Syariah',
        'name' => 'Bank Sumsel Babel Syariah',
        'swift' => null,
        'aliases' => [
            'Bank Sumsel Babel Syariah',
            'Sumsel Babel Syariah',
            'BPD Sumsel Babel Syariah',
            'Bank Sumsel Babel UUS',
            'Bank Sumsel Dan Babel UUS',
            'Sumsel Dan Babel UUS',
            'BPD Sumsel Dan Babel UUS',
        ],
        'keywords' => [
            'sumsel babel syariah',
            'sumsel babel uus',
            'sumsel dan babel uus',
        ],
    ],

    // ── low-conf-feed 處理：已停業銀行（保留 entry 以消除 audit noise）──
    [
        // Bank IFI (Indonesia Finance International) — 已於 2009 年破產停業
        // 保留以對應 SurePay 仍下發的舊 feed，避免 audit 噪音
        'code' => 'IDR_IFI',
        'standard_name' => '(IDR) - Bank IFI',
        'name' => 'Bank IFI',
        'swift' => null,
        'aliases' => [
            'Bank IFI',
            'Bank Indonesia Finance International',
            'IFI',
        ],
        'keywords' => [
            'bank ifi',
            'indonesia finance international',
        ],
    ],

    // ── 補 IDR 測試環境 no-match：Syariah / UUS 子行（依規範獨立 entry）──
    [
        // Bank Danamon Syariah Unit Usaha
        'code' => 'IDR_DANAMONUUS',
        'standard_name' => '(IDR) - Bank Danamon UUS',
        'name' => 'Bank Danamon UUS',
        'swift' => null,
        'aliases' => [
            'Bank Danamon UUS',
            'Danamon UUS',
            'Bank Danamon Syariah',
            'Danamon Syariah',
            'Bank Danamon Unit Usaha Syariah',
        ],
        'keywords' => [
            'danamon uus',
            'danamon syariah',
        ],
    ],
    [
        // Bank DKI Syariah / Unit Usaha Syariah
        'code' => 'IDR_DKIJAKARTAUUS',
        'standard_name' => '(IDR) - Bank DKI Jakarta UUS',
        'name' => 'Bank DKI Jakarta UUS',
        'swift' => null,
        'aliases' => [
            'Bank DKI Jakarta UUS',
            'Bank DKI UUS',
            'DKI Jakarta UUS',
            'DKI UUS',
            'Bank DKI Syariah',
            'Bank DKI Jakarta Syariah',
        ],
        'keywords' => [
            'dki uus',
            'dki syariah',
            'dki jakarta uus',
        ],
    ],
    [
        // Bank Jatim Syariah / BPD Jatim Unit Usaha Syariah
        'code' => 'IDR_JATIMUUS',
        'standard_name' => '(IDR) - Bank Jatim UUS',
        'name' => 'Bank Jatim UUS',
        'swift' => null,
        'aliases' => [
            'Bank Jatim UUS',
            'Bank Jatim Syariah',
            'BPD Jatim UUS',
            'BPD Jawa Timur UUS',
            'Jatim UUS',
            'Jatim Syariah',
        ],
        'keywords' => [
            'jatim uus',
            'jatim syariah',
        ],
    ],
    [
        // Bank OCBC NISP Unit Usaha Syariah
        'code' => 'IDR_OCBCNISPUUS',
        'standard_name' => '(IDR) - Bank OCBC NISP UUS',
        'name' => 'Bank OCBC NISP UUS',
        'swift' => null,
        'aliases' => [
            'Bank OCBC NISP UUS',
            'OCBC NISP UUS',
            'Bank OCBC NISP Syariah',
            'OCBC NISP Syariah',
            'Bank OCBC NISP Unit Usaha Syariah',
        ],
        'keywords' => [
            'ocbc nisp uus',
            'ocbc nisp syariah',
        ],
    ],
    [
        // Bank Sinarmas Unit Usaha Syariah
        'code' => 'IDR_SINARMASUUS',
        'standard_name' => '(IDR) - Bank Sinarmas UUS',
        'name' => 'Bank Sinarmas UUS',
        'swift' => null,
        'aliases' => [
            'Bank Sinarmas UUS',
            'Sinarmas UUS',
            'Bank Sinarmas Syariah',
            'Sinarmas Syariah',
            'Bank Sinarmas Unit Usaha Syariah',
        ],
        'keywords' => [
            'sinarmas uus',
            'sinarmas syariah',
        ],
    ],
    [
        // Bank Sulselbar Unit Usaha Syariah
        'code' => 'IDR_SULSELBARUUS',
        'standard_name' => '(IDR) - Bank Sulselbar UUS',
        'name' => 'Bank Sulselbar UUS',
        'swift' => null,
        'aliases' => [
            'Bank Sulselbar UUS',
            'Sulselbar UUS',
            'Bank Sulselbar Syariah',
            'Sulselbar Syariah',
            'BPD Sulselbar UUS',
        ],
        'keywords' => [
            'sulselbar uus',
            'sulselbar syariah',
        ],
    ],
    [
        // Bank Sumut Unit Usaha Syariah
        'code' => 'IDR_SUMUTUUS',
        'standard_name' => '(IDR) - Bank Sumut UUS',
        'name' => 'Bank Sumut UUS',
        'swift' => null,
        'aliases' => [
            'Bank Sumut UUS',
            'Sumut UUS',
            'Bank Sumut Syariah',
            'Sumut Syariah',
            'BPD Sumut UUS',
        ],
        'keywords' => [
            'sumut uus',
            'sumut syariah',
        ],
    ],

    // ── 補 IDR 測試環境 no-match：合資 / 外資 / 已停業 / 其他 ──
    [
        // Bank ANZ Panin — ANZ 與 Bank Panin Indonesia 合資銀行
        'code' => 'IDR_ANZPANIN',
        'retired_into' => 'IDR_ANZINDONESIA', // 2012 年改名 ANZ Indonesia
        'standard_name' => '(IDR) - Bank ANZ Panin',
        'name' => 'Bank ANZ Panin',
        'swift' => null,
        'aliases' => [
            'Bank ANZ Panin',
            'ANZ Panin',
            'ANZ Panin Bank',
            'PT Bank ANZ Panin',
            'PT ANZ Panin Bank',
        ],
        'keywords' => [
            'anz panin',
        ],
    ],
    [
        // Royal Bank of Scotland — 已於 2017 年退出印尼市場
        // 保留 entry 以消除 audit noise
        'code' => 'IDR_ROYALBANKOFSCOTLAND',
        'standard_name' => '(IDR) - Royal Bank of Scotland (RBS)',
        'name' => 'Royal Bank of Scotland (RBS)',
        'swift' => null,
        'aliases' => [
            'Royal Bank of Scotland (RBS)',
            'Royal Bank of Scotland',
            'RBS',
            'RBS Indonesia',
            'PT Bank RBS',
        ],
        'keywords' => [
            'royal bank of scotland',
            'rbs',
        ],
    ],
    [
        // Koperasi Intidana — 合作社銀行
        'code' => 'IDR_KOPERASIINTIDANA',
        'standard_name' => '(IDR) - Koperasi Intidana',
        'name' => 'Koperasi Intidana',
        'swift' => null,
        'aliases' => [
            'Koperasi Intidana',
            'Intidana',
            'KSP Intidana',
            'Koperasi Simpan Pinjam Intidana',
        ],
        'keywords' => [
            'intidana',
            'koperasi intidana',
        ],
    ],
];
