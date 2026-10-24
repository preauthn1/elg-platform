<?php

declare(strict_types=1);

use App\Enums\StandardBankTypeEnum;

/**
 * VND 標準銀行配置（擴充別名和關鍵字版本）
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
    // 越南國有銀行
    [
        'code' => 'VND_VIETCOMBANK',
        'standard_name' => '(VND) - Vietcombank',
        'name' => 'Vietcombank',
        'swift' => 'BFTVVNVX',
        'aliases' => [
            'Vietcombank',
            'Vietnam Commercial Bank',
            'VietcomBank',
            'VIETCOMBANK',
            'VCB',
            'Viet Com Bank',
            'Joint Stock Commercial Bank for Foreign Trade of Vietnam',
            'Vietcombank - Ngân Hàng TMCP Ngoại thương Việt Nam',
        ],
        'keywords' => [
            'vietcombank',
            'vcb',
            'vietcom',
        ],
    ],
    [
        'code' => 'VND_VIETINBANK',
        'standard_name' => '(VND) - VietinBank',
        'name' => 'VietinBank',
        'swift' => 'ICBVVNVX',
        'aliases' => [
            'VietinBank',
            'Vietin Bank',
            'Vietnam Joint Stock Commercial Bank for Industry and Trade',
            'CTG',
            'VIETINBANK',
            'Vietin',
        ],
        'keywords' => [
            'vietinbank',
            'ctg',
            'vietin',
            'industry trade',
        ],
    ],
    [
        'code' => 'VND_AGRIBANK',
        'standard_name' => '(VND) - Agribank',
        'name' => 'Agribank',
        'swift' => null,
        'aliases' => [
            'Agribank',
            'AGRI BANK',
            'Agricultural Bank',
            'AgriBank',
            'Agri Bank',
        ],
        'keywords' => [
            'agribank',
            'agri',
            'agricultural',
        ],
    ],

    // 越南商業銀行 - A
    [
        'code' => 'VND_ACB',
        'standard_name' => '(VND) - Asia Commercial Bank',
        'name' => 'Asia Commercial Bank',
        'swift' => 'ASCBVNVX',
        'aliases' => [
            'Asia Commercial Bank',
            'AsiaCommercialBank',
            'ACB',
            'ASIACOMMERIALBANK',
            'Asia Commercial',
            'Saigon Asia Commercial Bank',
        ],
        'keywords' => [
            'asia commercial',
            'acb',
            'asia',
        ],
    ],
    [
        'code' => 'VND_ABBANK',
        'standard_name' => '(VND) - An Binh Commercial Joint Stock Bank',
        'name' => 'An Binh Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'An Binh Commercial Joint Stock Bank',
            'An Binh Commercial Join Stock Bank', // 拼寫錯誤變體（DB bank_id=682）
            'AnBinhCommercial',
            'An Binh Bank',
            'AnBinh',
            'ABBANK',
            'ABBank',
            'An Binh',
        ],
        'keywords' => [
            'anbinh',
            'an binh',
            'abbank',
        ],
    ],

    // 越南商業銀行 - B
    [
        'code' => 'VND_BACABANK',
        'standard_name' => '(VND) - Bac A Commercial Joint Stock Bank',
        'name' => 'Bac A Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Bac A Commercial Joint Stock Bank',
            'BacA',
            'Bac A Bank',
            'BacABank',
            'BAB',
            'Bac A',
        ],
        'keywords' => [
            'baca',
            'bac a',
            'bab',
        ],
    ],
    [
        'code' => 'VND_BAOVIET',
        'standard_name' => '(VND) - BAOVIET Bank',
        'name' => 'BaoViet Bank',
        'swift' => null,
        'aliases' => [
            'BAOVIET Bank',
            'Bao Viet Bank',
            'BaoViet',
            'BAOVIETBANK',
            'Bao Viet',
            'Bao Viet Joint Stock Commercial Bank',
        ],
        'keywords' => [
            'baoviet',
            'bao viet',
        ],
    ],

    // 越南商業銀行 - C
    [
        'code' => 'VND_CIMBNIAGA',
        'standard_name' => '(VND) - CIMB Niaga',
        'name' => 'CIMB Niaga',
        'swift' => null,
        'aliases' => [
            'CIMB Niaga',
            'CIMBNiaga',
            'CIMB Niaga Bank',
        ],
        'keywords' => [
            'cimb niaga',
            'niaga',
        ],
    ],

    // 越南商業銀行 - D
    [
        'code' => 'VND_DONGA',
        'retired_into' => 'VND_VIKKIDIGITAL', // 2025 年改名 Vikki Digital Bank，客戶帳號延續
        'standard_name' => '(VND) - DongA Bank',
        'name' => 'DongA Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'DongA Bank',
            'Dong A Bank',
            'DongABank',
            'DAB',
            'DONGABANK',
            'Dong A',
            'DongA Commercial Joint Stock Bank',
        ],
        'keywords' => [
            'dongabank',
            'dong a',
            'dab',
            'donga',
        ],
    ],

    // 越南商業銀行 - E
    [
        'code' => 'VND_EXIMBANK',
        'standard_name' => '(VND) - Eximbank Vietnam',
        'name' => 'Eximbank',
        'swift' => null,
        'aliases' => [
            'Eximbank Vietnam',
            'Eximbank',
            'EximbankVietnam',
            'EXIM',
            'EIB',
            'Exim Vietnam',
            'Vietnam Export Import Commercial Joint Stock Bank',
            'Eximbank - Ngân Hàng TMCP Xuất Nhập khẩu Việt Nam',
        ],
        'keywords' => [
            'eximbank',
            'exim',
            'eib',
            'export import',
        ],
    ],

    // 越南商業銀行 - H
    [
        'code' => 'VND_HD',
        'standard_name' => '(VND) - HD Bank',
        'name' => 'HD Bank',
        'swift' => null,
        'aliases' => [
            'HD Bank',
            'HDBank',
            'Hung Vuong Bank',
            'HDB',
            'HDBANK',
            'Hung Vuong',
            'Ho Chi Minh City Development Bank',
            'Ho Chi Minh City Development Bank JSC',
            'Ho Chi Minh City Development Joint Stock Commercial Bank',
        ],
        'keywords' => [
            'hdbank',
            'hungvuong',
            'hd',
            'hung vuong',
            'ho chi minh city development',
        ],
    ],

    // 越南商業銀行 - K
    [
        'code' => 'VND_KIENLONG',
        'standard_name' => '(VND) - Kien Long Commercial Joint Stock Bank',
        'name' => 'Kien Long Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Kien Long Commercial Joint Stock Bank',
            'Kien Long Bank',
            'KienLong',
            'KLB',
            'KIENLONGBANK',
            'Kien Long',
        ],
        'keywords' => [
            'kienlong',
            'klb',
            'kien long',
        ],
    ],

    // 越南商業銀行 - L
    [
        'code' => 'VND_LPBANK',
        'standard_name' => '(VND) - Lien Viet Post Joint Stock Commercial Bank',
        'name' => 'Lien Viet Post Joint Stock Commercial Bank',
        'swift' => null,
        'aliases' => [
            'Lien Viet Post Joint Stock Commercial Bank',
            'Lien Viet Post',
            'LienViet',
            'LVB',
            'LIENVIETPOSTBANK',
            'Lien Viet',
            'LPBank',
        ],
        'keywords' => [
            'lienviet',
            'lvb',
            'lien viet',
        ],
    ],

    // 越南商業銀行 - M
    [
        'code' => 'VND_MB',
        'standard_name' => '(VND) - Military Commercial Joint Stock Bank',
        'name' => 'Military Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Military Commercial Joint Stock Bank',
            'Military Commercial Bank', // DB bank_id=1158 變體
            '(VND) - Military Commercial Bank',
            'MB Bank',
            'MB BANK',
            'Military Bank',
            'MilitaryCommercial',
            'MB',
            'MBBANK',
            'Military',
        ],
        'keywords' => [
            'military',
            'militarybank',
            'mbbank',
        ],
    ],
    [
        'code' => 'VND_MANDIRI',
        'standard_name' => '(VND) - Mandiri Bank',
        'name' => 'Mandiri Bank',
        'swift' => null,
        'aliases' => [
            'Mandiri Bank',
            'Mandiri',
            'MANDIRI',
            'PT Bank Mandiri',
        ],
        'keywords' => [
            'mandiri',
        ],
    ],

    // 越南商業銀行 - N
    [
        'code' => 'VND_NAMABANK',
        'standard_name' => '(VND) - Nam A Commercial Joint Stock Bank',
        'name' => 'Nam A Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Nam A Commercial Joint Stock Bank',
            'Nam A Bank',
            'NamA',
            'NAB',
            'NAMABANK',
            'Nam A',
        ],
        'keywords' => [
            'nama',
            'nam a',
            'nab',
        ],
    ],
    [
        'code' => 'VND_NCB',
        'standard_name' => '(VND) - National Citizen Commercial Joint Stock Bank',
        'name' => 'National Citizen Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'National Citizen Commercial Joint Stock Bank',
            'National Citizen Bank',
            'NCB',
            'CITIZENBANK',
            'National Citizen',
        ],
        'keywords' => [
            'ncb',
            'national citizen',
            'citizen',
        ],
    ],

    // 越南商業銀行 - O
    [
        'code' => 'VND_OCB',
        'standard_name' => '(VND) - Orient Commercial Joint Stock Bank',
        'name' => 'Orient Commercial Joint Stock Bank',
        'swift' => 'ORCOVNVX',
        'aliases' => [
            'Orient Commercial Joint Stock Bank',
            'Orient Bank',
            'OrientCommercial',
            'OCB',
            'ORIENTBANK',
            'Orient',
        ],
        'keywords' => [
            'orient',
            'ocb',
            'orient bank',
        ],
    ],

    // 越南商業銀行 - P
    [
        'code' => 'VND_PGBANK',
        'standard_name' => '(VND) - Petrolimex Group Commercial Joint Stock Bank',
        'name' => 'Petrolimex Group Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Petrolimex Group Commercial Joint Stock Bank',
            'Petrolimex Bank',
            'Petrolimex',
            'PGB',
            'Petrolimex Group',
            'Petrolimex Joint Stock Commercial Bank',
            'PG Bank',
            'PGBank',
            'Prosperity and Growth Commercial Joint Stock Bank',
        ],
        'keywords' => [
            'petrolimex',
            'pgb',
            'prosperity and growth',
        ],
    ],

    // 越南商業銀行 - S
    [
        'code' => 'VND_SACOMBANK',
        'standard_name' => '(VND) - Sacombank',
        'name' => 'Sacombank',
        'swift' => 'SGTTVNVX',
        'aliases' => [
            'Sacombank',
            'SAC',
            'STB', // Sacombank ticker
            'SACOMBANK',
            'Saigon Thuong Tin Commercial Joint Stock Bank',
            // 'Saigon Commercial Bank' 已讓渡給 Saigon Joint Stock Commercial Bank（SCB 本尊）——Sacombank 是 Saigon Thuong Tin
            'Sacombank - Ngân hàng TMCP Sài Gòn Thương Tín',
        ],
        'keywords' => [
            'sacombank',
            'sac',
            'stb',
            'saigon thuong tin',
        ],
    ],
    [
        'code' => 'VND_SAIGONBANK',
        'standard_name' => '(VND) - Saigon Bank for Industry and Trade',
        'name' => 'Saigonbank',
        'swift' => null,
        'aliases' => [
            'Saigon Bank for Industry and Trade',
            'Saigon Bank',
            'SaigonBank',
            'Saigon',
            'SGB',
        ],
        'keywords' => [
            'saigonbank',
            'saigon bank',
            'industry trade',
        ],
    ],
    [
        'code' => 'VND_SCB',
        'standard_name' => '(VND) - Saigon Joint Stock Commercial Bank',
        'name' => 'Saigon Joint Stock Commercial Bank',
        'swift' => 'SACLVNVX',
        'aliases' => [
            'Saigon Joint Stock Commercial Bank',
            'Sai Gon Joint Stock Commercial Bank', // DB bank_id=692 變體
            'Saigon Commercial Bank',
            'Sai Gon Commercial Bank',
            'Saigon Commercial Joint Stock Bank', // QuickPay 碼 2009，正式站通道清單對到 SCB
            'SaiGonCommercial',
            'SCB',
            // 裸稱 'Saigon' 已讓渡給 Saigonbank（該行品牌即 Saigonbank；本行通稱 SCB）
        ],
        'keywords' => [
            'saigon commercial',
            'saigon joint stock',
        ],
    ],
    [
        'code' => 'VND_SHB',
        'standard_name' => '(VND) - Saigon-Hanoi Commercial Joint Stock Bank',
        'name' => 'Saigon-Hanoi Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Saigon-Hanoi Commercial Joint Stock Bank',
            'Saigon – Hanoi Commercial Joint Stock Bank', // DB bank_id=678 使用 en-dash 變體
            'Saigon-Hanoi Bank',
            'SaigonHanoi',
            'SHB',
            'Saigon Hanoi',
        ],
        'keywords' => [
            'saigonhanoi',
            'shb',
            'saigon hanoi',
            'saigon-hanoi',
        ],
    ],
    [
        'code' => 'VND_SEABANK',
        'standard_name' => '(VND) - Southeast Asia Commercial Joint Stock Bank',
        'name' => 'Southeast Asia Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Southeast Asia Commercial Joint Stock Bank',
            'Southeast Asia Bank',
            'SeAsiaBank',
            'SEABANK',
            'Southeast Asia',
        ],
        'keywords' => [
            'southeast asia',
            'seabank',
            'sea',
        ],
    ],

    // 越南商業銀行 - T
    [
        'code' => 'VND_TECHCOMBANK',
        'standard_name' => '(VND) - Techcombank',
        'name' => 'Techcombank',
        'swift' => 'VTCBVNVX',
        'aliases' => [
            'Techcombank',
            'Techcom Bank',
            'Tech Com',
            'TCB',
            'TECHCOMBANK',
            'Techcom',
            'Vietnam Technological and Commercial Joint Stock Bank',
            'Techcombank - Ngân hàng TMCP Kỹ Thương Việt Nam',
            'Techcombank - gân hàng TMCP Kỹ Thương Việt Nam',
        ],
        'keywords' => [
            'techcombank',
            'tcb',
            'techcom',
        ],
    ],
    [
        'code' => 'VND_TPBANK',
        'standard_name' => '(VND) - Tien Phong Commercial Joint Stock Bank',
        'name' => 'Tien Phong Commercial Joint Stock Bank',
        'swift' => 'TPBVVNVX',
        'aliases' => [
            'Tien Phong Commercial Joint Stock Bank',
            'Tien Phong Commercial', // DB bank_id=675 變體
            'TPBank',
            'TPBANK',
            'Tien Phong Bank',
            'TienPhong',
            'TPB',
            'TIENPHONGBANK',
            'Tien Phong',
        ],
        'keywords' => [
            'tienphong',
            'tpb',
            'tien phong',
            'tpbank',
        ],
    ],

    // 越南商業銀行 - V
    [
        'code' => 'VND_VIETABANK',
        'standard_name' => '(VND) - VietABank',
        'name' => 'Viet A Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'VietABank',
            'Viet A Bank',
            'VietA',
            'VAB',
            'VIETABANK',
            'Viet A',
            'Viet A Commercial Joint Stock Bank',
        ],
        'keywords' => [
            'vietabank',
            'vieta',
            'viet a',
        ],
    ],
    [
        'code' => 'VND_VIB',
        'standard_name' => '(VND) - Vietnam International Commercial Joint Stock Bank',
        'name' => 'Vietnam International Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Vietnam International Commercial Joint Stock Bank',
            'Vietnam International Bank',
            'VietnamIntl',
            'VIB',
            'Vietnam International',
        ],
        'keywords' => [
            'vietnam international',
            'vib',
            'intl',
        ],
    ],
    [
        'code' => 'VND_VPBANK',
        'standard_name' => '(VND) - Vietnam Prosperity Joint Stock Commercial Bank',
        'name' => 'Vietnam Prosperity Joint Stock Commercial Bank',
        'swift' => 'VPBKVNVX',
        'aliases' => [
            'Vietnam Prosperity Joint Stock Commercial Bank',
            'Vietnam Prosperity Joint-Stock Commercial Bank', // DB bank_id=673 變體（含連字號）
            'VPBank',
            'VPBANK',
            'Vietnam Prosperity Bank',
            'VietnamProsperity',
            'VPB',
            'Vietnam Prosperity',
        ],
        'keywords' => [
            'vietnam prosperity',
            'vpbank',
            'vpb',
        ],
    ],
    [
        'code' => 'VND_PVCOMBANK',
        'standard_name' => '(VND) - Vietnam Public Joint-stock Commercial Bank',
        'name' => 'Vietnam Public Joint Stock Commercial Bank',
        'swift' => null,
        'aliases' => [
            'Vietnam Public Joint-stock Commercial Bank',
            'Vietnam Public Bank',
            'VietnamPublic',
            // 'VPB' 已讓渡給 VPBank（VPB 是 VPBank 的股票代碼，掛在本行會使 VPB 整組失配）
            'Vietnam Public',
            'PVcomBank',
        ],
        'keywords' => [
            'vietnam public',
            'pvcombank',
        ],
    ],
    [
        'code' => 'VND_VIETBANK',
        'standard_name' => '(VND) - Vietnam Thuong Tin Commercial Joint Stock Bank',
        'name' => 'Vietnam Thuong Tin Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Vietnam Thuong Tin Commercial Joint Stock Bank',
            'Vietnam Thuong Tin Bank',
            'VietnamThuongTin',
            'TT Bank',
            'VietBank',
        ],
        'keywords' => [
            'vietnam thuong',
            'vietbank',
        ],
    ],

    // 外資銀行
    [
        'code' => 'VND_BIDV',
        'standard_name' => '(VND) - Bank for Investment and Development of Vietnam',
        'name' => 'Bank for Investment and Development of Vietnam',
        'swift' => null,
        'aliases' => [
            'Bank for Investment and Development of Vietnam',
            'BID Bank',
            'BIDV',
        ],
        'keywords' => [
            'bidv',
            'bid',
            'investment and development',
        ],
    ],
    [
        'code' => 'VND_CENTRALASIA',
        'standard_name' => '(VND) - Bank Central Asia',
        'name' => 'Bank Central Asia',
        'swift' => null,
        'aliases' => [
            'Bank Central Asia',
            'BCA',
            'Central Asia Bank',
        ],
        'keywords' => [
            'bca',
            'central asia',
        ],
    ],
    [
        'code' => 'VND_NEGARAINDONESIA',
        'standard_name' => '(VND) - Bank Negara Indonesia',
        'name' => 'Bank Negara Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Negara Indonesia',
            'BNI',
            'State Bank Indonesia',
        ],
        'keywords' => [
            'bni',
        ],
    ],
    [
        'code' => 'VND_RAKYATINDONESIA',
        'standard_name' => '(VND) - Bank Rakyat Indonesia',
        'name' => 'Bank Rakyat Indonesia',
        'swift' => null,
        'aliases' => [
            'Bank Rakyat Indonesia',
            'BRI',
            'People\'s Bank Indonesia',
        ],
        'keywords' => [
            'bri',
        ],
    ],
    [
        'code' => 'VND_VIETCAPITAL',
        'standard_name' => '(VND) - Viet Capital Bank',
        'name' => 'Viet Capital Bank',
        'swift' => null,
        // 縮寫 VCB 已移除——它是 Vietcombank 的通用縮寫（該條目本就持有），掛在本行會使 VCB 整組失配
        'aliases' => [
            'Viet Capital Bank',
            'VietCapital',
            'Viet Capital',
            'BVBank',
            'BVB',
            'Ban Viet',
            'Ban Viet Bank',
        ],
        'keywords' => [
            'viet capital',
            'bvbank',
            'ban viet',
        ],
    ],
    [
        'code' => 'VND_MSB',
        'standard_name' => '(VND) - Vietnam Maritime Commercial Joint Stock Bank',
        'name' => 'Vietnam Maritime Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Vietnam Maritime Commercial Joint Stock Bank',
            'Vietnam Maritime Bank',
            'Maritime',
            'MSB',
            'Vietnam Maritime',
            'Maritime Commercial Joint Stock Bank',
            'Maritime Bank',
        ],
        'keywords' => [
            'maritime',
            'msb',
        ],
    ],
    [
        'code' => 'VND_VRB',
        'standard_name' => '(VND) - VRB',
        'name' => 'VRB',
        'swift' => null,
        'aliases' => [
            'Ngân hàng Liên doanh Việt - Nga',
            'Vietnam – Russia Joint Venture Bank',
        ],
        'keywords' => [
            'VRB',
        ],
    ],
    [
        'code' => 'VND_IBKCHINHANHHCM',
        'standard_name' => '(VND) - IBK - chi nhanh HCM',
        'name' => 'IBK - chi nhanh HCM',
        'swift' => null,
        'aliases' => [
            'IBK HCM',
            'IBK Ho Chi Minh City Branch',
            'IBK Vietnam (HCM)',
        ],
        'keywords' => [
            'ibk hcm',
            'ibk ho chi minh',
        ],
    ],
    [
        'code' => 'VND_IBKCHINHANHHANOI',
        'standard_name' => '(VND) - IBK - chi nhanh Ha Noi',
        'name' => 'IBK - chi nhanh Ha Noi',
        'swift' => null,
        'aliases' => [
            'IBK Hanoi',
            'IBK Hanoi Branch',
            'IBK Vietnam (Hanoi)',
        ],
        'keywords' => [
            'ibk hanoi',
            'ibk ha noi',
        ],
    ],
    [
        'code' => 'VND_UOB',
        'standard_name' => '(VND) - UOB Bank',
        'name' => 'UOB Bank',
        'swift' => null,
        'aliases' => [
            'UOB Vietnam ',
            'United Overseas Bank',
        ],
        'keywords' => [
            'UOB',
        ],
    ],

    // 在越南設立分行的外資銀行
    [
        'code' => 'VND_SHINHANVIETNAM',
        'standard_name' => '(VND) - Shinhan Bank Vietnam',
        'name' => 'Shinhan Bank Vietnam',
        'swift' => null,
        'aliases' => [
            'Shinhan Bank',
            'Shinhan Bank Vietnam',
            'Shinhan Bank Vietnam Limited',
            'Shinhan',
            'SHBVN',
        ],
        'keywords' => [
            'shinhan',
        ],
    ],
    [
        'code' => 'VND_WOORIVIETNAM',
        'standard_name' => '(VND) - Woori Bank Vietnam',
        'name' => 'Woori Bank Vietnam',
        'swift' => null,
        'aliases' => [
            'Woori Bank',
            'Woori Bank Vietnam',
            'WOORI',
            'Woori',
        ],
        'keywords' => [
            'woori',
        ],
    ],
    [
        'code' => 'VND_HONGLEONGVIETNAM',
        'standard_name' => '(VND) - Hong Leong Bank Vietnam',
        'name' => 'Hong Leong Bank Vietnam',
        'swift' => null,
        'aliases' => [
            'Hong Leong Bank',
            'Hong Leong Bank Vietnam',
            'HLBVN',
            'Hong Leong',
        ],
        'keywords' => [
            'hong leong',
            'hongleong',
        ],
    ],
    [
        'code' => 'VND_HSBCVIETNAM',
        'standard_name' => '(VND) - HSBC Bank Vietnam',
        'name' => 'HSBC Bank Vietnam',
        'swift' => null,
        'aliases' => [
            'HSBC Bank',
            'HSBC Bank Vietnam',
            'HSBC Vietnam',
            'HSBC',
        ],
        'keywords' => [
            'hsbc',
        ],
    ],
    [
        'code' => 'VND_PUBLICVIETNAM',
        'standard_name' => '(VND) - Public Bank Vietnam',
        'name' => 'Public Bank Vietnam',
        'swift' => null,
        'aliases' => [
            'Public Bank',
            'Public Bank Vietnam',
            'PBVN',
        ],
        'keywords' => [
            'public bank',
            'publicbank',
        ],
    ],

    // SBV 特殊監管下的零元接管銀行
    [
        'code' => 'VND_OCEANBANK',
        // 2024-12-18 起官方英文名為 Modern Bank of Vietnam Limited（MBV）；standard_name 是各站
        // 平台銀行的名稱，cash-flow:sync 靠它找既有列，平台銀行改名前不動
        'standard_name' => '(VND) - Ocean Commercial One Member Limited Liability Bank',
        'name' => 'Modern Bank of Vietnam Limited',
        'swift' => null,
        'aliases' => [
            'Oceanbank',
            'Ocean Bank',
            'OceanBank',
            'OJB',
            'Ocean Commercial Joint Stock Bank',
            'MBV',
            'Modern Bank of Vietnam',
            'Modern Bank of Vietnam Limited',
        ],
        'keywords' => [
            'ocean bank',
            'oceanbank',
            'modern bank',
        ],
    ],
    [
        'code' => 'VND_GLOBALPETROSOLEMEMBER',
        'standard_name' => '(VND) - Global Petro Sole Member Limited Commercial Bank',
        'name' => 'Global Petro Sole Member Limited Commercial Bank',
        'swift' => null,
        'aliases' => [
            'GPBank',
            'GP Bank',
            'GPBANK',
            'Global Petro Bank',
        ],
        'keywords' => [
            'gpbank',
            'gp bank',
            'global petro',
        ],
    ],
    [
        'code' => 'VND_CONSTRUCTION',
        'standard_name' => '(VND) - Construction Commercial Joint Stock Bank',
        'name' => 'Construction Commercial Joint Stock Bank',
        'swift' => null,
        'aliases' => [
            'Construction Bank',
            'Construction Commercial Bank',
            'CB Bank',
            'CBBank',
            'CBB',
            'VCBNeo',
            'Vietcombank Neo',
            'Vietcombank Neo Limited',
        ],
        'keywords' => [
            'construction bank',
            'cbbank',
            'vcbneo',
        ],
    ],

    // 合作社銀行
    [
        'code' => 'VND_COOPERATIVEBANKOFVIETNAM',
        'standard_name' => '(VND) - Co-operative Bank of Vietnam',
        'name' => 'Co-operative Bank of Vietnam',
        'swift' => null,
        'aliases' => [
            'Co-operative Bank of Vietnam',
            'Vietnam Co-operative Bank',
            'Co-opBank',
            'Coop Bank',
            'CoopBank',
        ],
        'keywords' => [
            'co-operative bank',
            'cooperative bank',
            'coopbank',
        ],
    ],

    // 數位銀行
    [
        'code' => 'VND_VIKKIDIGITAL',
        'standard_name' => '(VND) - Vikki Digital Bank',
        'name' => 'Vikki Digital Bank',
        'swift' => null,
        'aliases' => [
            'Vikki Digital Bank',
            'Vikki Digital Bank Limited',
            'Vikki Bank',
            'Vikki',
        ],
        'keywords' => [
            'vikki',
        ],
    ],
    [
        'code' => 'VND_CAKEDIGITAL',
        'standard_name' => '(VND) - CAKE Digital Bank',
        'name' => 'CAKE Digital Bank',
        'swift' => null,
        'aliases' => [
            'CAKE Digital Bank',
            'CAKE by VPBank',
            'CAKE Bank',
            'CAKE',
        ],
        'keywords' => [
            'cake digital',
            'cake bank',
            'cake by vpbank',
        ],
    ],

    // ── 併自 database/data/standard_bank_codes.php：本檔原無對應的條目（置於陣列末＝命中優先權最低） ──
    [
        'code' => 'VND_MOMO',
        'standard_name' => '(VND) - MOMO',
        'name' => 'MOMO',
        'swift' => null,
        'type' => StandardBankTypeEnum::E_WALLET->value,
        'aliases' => [
            'MOMO',
            'MoMo Wallet',
        ],
        'keywords' => [
            'momo',
        ],
    ],
    [
        'code' => 'VND_CIMB',
        'standard_name' => '(VND) - CIMB Bank Vietnam',
        'name' => 'CIMB Bank Vietnam',
        'swift' => null,
        'aliases' => [
            'CIMB Bank Vietnam',
            'CIMB Bank',
            'CIMB',
            'CIMB Bank (Vietnam) Limited',
            'CIMB Vietnam',
        ],
        'keywords' => [
            'cimb bank',
            'cimb vietnam',
        ],
    ],

    // ── 2026-09-14 補：六站 sync-mappings dry-run 撈到的無條目銀行（TPower／Yes2Win 各多群組並存） ──
    [
        'code' => 'VND_INDOVINA',
        'standard_name' => '(VND) - Indovina Bank',
        'name' => 'Indovina Bank',
        'swift' => null,
        'aliases' => [
            'Indovina Bank',
            'Indovina Bank Limited',
            'Indovina',
            'IVB',
        ],
        'keywords' => [
            'indovina',
        ],
    ],
    [
        'code' => 'VND_VIETNAMSOCIALPOLICIES',
        'standard_name' => '(VND) - Vietnam Bank for Social Policies',
        'name' => 'Vietnam Bank for Social Policies',
        'swift' => null,
        'aliases' => [
            'Vietnam Bank for Social Policies',
            'Bank for Social Policies',
            'Social Policy Bank',
            'VBSP',
        ],
        'keywords' => [
            'social policies',
            'social policy',
        ],
    ],
];
