<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * TtPay 金流商配置
 *
 * TTPay ＝ DGPay 換名白牌：API 規格、channel key 編碼、銀行清單與 DgPay.php 完全相同，
 * 僅品牌名、cash_flows（125/126）與商戶憑證不同。規格依據 docs/integrations/payments/DgPay/。
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        // TODO: TTPay 商戶開通後補 API base URL（DGPay 慣例含 /ajax/api 前綴，裸 host 打 /withdraw 回 404）
        'API_URL' => '',
        'MERCHANT' => '',         // opCode
        'MERCHANT_KEY' => '',     // secretKey（簽名 / 回調驗簽用）
        'CURRENCY' => '',
        // 全域預設 V1（MYR/IDR 與全部代付，含 SGD 代付）；僅 SGD「代收」走 V2，
        // 由 deposit.info_overrides 於 seeding 時自動套用
        'VERSION' => 'V1',
        // 註：PROVIDER_ID / PROVIDER_TYPE / PAYMENT_CODE 刻意不列入 credentials 模板（同 DgPay.php）。
        //   代收恆為 0/空、全由 order_types.key 動態解析（Deposit::resolveProviderPair()／resolvePaymentType()）；
        //   代付所需實值由 withdrawal.info_overrides 依幣別補回（MYR 5/10、IDR 55/10、SGD 10/10）。
    ],

    // 代收配置（cash_flow 125 = D_TTPAY）
    //
    // channel key 編碼規則同 DgPay.php（V1「{providerId}_{providerType}」、SGD V2 編 paymentType），
    // 由繼承殼沿用的 Deposit::resolveProviderPair()／resolvePaymentType() 解析。
    'deposit' => [
        'cash_flow_id' => 125,
        // 依幣別覆寫 agent_cash_flows.info（binding 粒度 = 金流類型×幣別；見 MergesProviderInfoOverrides）。
        // MYR/IDR 不列：代收 PROVIDER_ID/TYPE 必須維持 0 走 channel key 動態解析
        'info_overrides' => [
            'SGD' => ['VERSION' => 'V2'], // SGD 代收走 V2（P2P/QR/QRKYC，官方 §7）
        ],
        'channels' => [
            // ─── MYR（V1）───
            [
                'currency' => 'MYR',
                'name' => 'TTPay Instant TopUp Route2',
                'key' => '5_10', // Route2 網銀轉帳，data: fromBankCode + redirectUrl
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'min_amount' => 10,
                'max_amount' => 50000,
                // cost 空值（0）：作為「依 channel 拆分 AgentCashFlow」的建議開關
                // （hasChannelCostConfigured 只看 cost key 是否存在、不看值；拆分形式最終由
                //  操作者確認——--mode / 互動詢問，非互動未指定時才依此自動分流）；
                // 實際手續費各白標不同，由各站 agent_cash_flows.cost 自行設定，不寫死於此共用 config。
                'cost' => 0,
                'options' => [
                    // 僅官方 §6 Route2「Transfer Supported = YES」的個人戶銀行
                    ['name' => '(MYR) - Affin Bank', 'key' => 'AFIN'],
                    ['name' => '(MYR) - Alliance Bank', 'key' => 'ALLI'],
                    ['name' => '(MYR) - Hong Leong Bank', 'key' => 'HOLB'],
                    ['name' => '(MYR) - CIMB Bank', 'key' => 'CIMB'],
                    ['name' => '(MYR) - Maybank', 'key' => 'MAYB'],
                    ['name' => '(MYR) - Public Bank', 'key' => 'PBBB'],
                    ['name' => '(MYR) - RHB Bank', 'key' => 'RHBB'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'key' => 'SINA'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'key' => 'MYIS'],
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'TTPay QR Route2',
                'key' => '5_20', // Route2 QR，data: redirectUrl
                'type' => DepositTypeEnum::QRCODE->value,
                'min_amount' => 30,
                'max_amount' => 10000,
                'cost' => 0, // 空值：同上，觸發拆分開關，實際 cost 各站 DB 自設
            ],
            // ─── IDR（V1，Route49 providerId=55）───
            [
                'currency' => 'IDR',
                'name' => 'TTPay QRIS',
                'key' => '55_10', // Qris，data: accName
                'type' => DepositTypeEnum::QRCODE->value,
                'min_amount' => 10000,
                'max_amount' => 10000000,
            ],
            // ─── SGD（V2，channel key 編碼 paymentType，官方 §7）───
            [
                'currency' => 'SGD',
                'name' => 'TTPay PayNow',
                'key' => 'P2P', // Direct transfer；sender.bankCode 取銀行子項 key（官方 §5：required for P2P）
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'min_amount' => 50,
                'max_amount' => 5000,
                'options' => [
                    // 官方 SGD V2 §6「SGD P2P」段僅 DBS / UOB / OCBC 支援代收
                    ['name' => '(SGD) - DBS Bank', 'key' => 'DBS'],
                    ['name' => '(SGD) - United Overseas Bank', 'key' => 'UOB'],
                    ['name' => '(SGD) - OCBC Bank', 'key' => 'OCBC'],
                ],
            ],
            [
                'currency' => 'SGD',
                'name' => 'TTPay QR KYC',
                'key' => 'QRKYC', // Qr code transfer + KYC；sender.username 必填（官方 §5：only for QRKYC）、不帶 bankCode
                'type' => DepositTypeEnum::QRCODE->value,
                'min_amount' => 50,
                'max_amount' => 5000,
            ],
        ],
    ],

    // 代付配置（cash_flow 126 = W_TTPAY）
    'withdrawal' => [
        'cash_flow_id' => 126,
        // 代付一律 V1（含 SGD，官方 withdrawal 文檔為 V1；V2 僅代收用），必須配實值，
        // 0/0 三方回 -180 Invalid Payment
        'info_overrides' => [
            // Auto=5/10（DGPay DEV 實測走通）；切 Manual(10/10) 屬營運決策，DB 手調後不會被 re-run 打回（既有值優先）
            'MYR' => ['PROVIDER_ID' => 5, 'PROVIDER_TYPE' => 10],
            // 官方 IDR §5 Withdraw
            'IDR' => ['PROVIDER_ID' => 55, 'PROVIDER_TYPE' => 10],
            // SGD 代付走 V1（同 DgPay.php 2026-07-03 更正，勿配 V2）
            'SGD' => ['PROVIDER_ID' => 10, 'PROVIDER_TYPE' => 10],
        ],
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Affin Bank', 'code' => 'AFIN'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'ALLI'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HOLB'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(MYR) - CIMB Bank (Corporate Account)', 'code' => 'CIMB_BIZ'],
                    ['name' => '(MYR) - Maybank (Corporate Account)', 'code' => 'MAYB_BIZ'],
                    ['name' => '(MYR) - Maybank', 'code' => 'MAYB'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBBB'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHBB'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'code' => 'SINA'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'MYIS'],
                    ['name' => '(MYR) - Bank of America', 'code' => 'AAAA'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => 'AGRO'],
                    ['name' => '(MYR) - Bangkok Bank Malaysia', 'code' => 'BAKO'],
                    ['name' => '(MYR) - BNP Paribas Malaysia', 'code' => 'BNPB'],
                    ['name' => '(MYR) - Bank of China', 'code' => 'CCCC'],
                    ['name' => '(MYR) - Citibank', 'code' => 'CITI'],
                    ['name' => '(MYR) - Deutsche Bank', 'code' => 'DEUT'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(MYR) - ICBC', 'code' => 'ICBC'],
                    ['name' => '(MYR) - J.P. Morgan Chase Bank', 'code' => 'JPMB'],
                    ['name' => '(MYR) - Kuwait Finance House', 'code' => 'KFHB'],
                    ['name' => '(MYR) - MBSB Bank', 'code' => 'MBSB'],
                    ['name' => '(MYR) - China Construction Bank Malaysia', 'code' => 'MCCB'],
                    ['name' => '(MYR) - Mizuho Bank', 'code' => 'MIZU'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'MUAM'],
                    ['name' => '(MYR) - MUFG Bank', 'code' => 'MUFG'],
                    ['name' => '(MYR) - AmBank', 'code' => 'MYAB'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(MYR) - Alrajhi Bank', 'code' => 'RAJH'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'RAKY'],
                    ['name' => '(MYR) - Ryt Bank', 'code' => 'RYTB'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'STCB'],
                    ['name' => '(MYR) - Sumitomo Mitsui Banking', 'code' => 'SUMB'],
                    ['name' => '(MYR) - Touch n Go', 'code' => 'TNGP'],
                    ['name' => '(MYR) - GX Bank', 'code' => 'GXBK'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'UNOB'],
                ],
            ],
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(SGD) - DBS Bank', 'code' => 'DBS'],
                    ['name' => '(SGD) - United Overseas Bank', 'code' => 'UOB'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(SGD) - CIMB Bank', 'code' => 'SCIMB'],
                    ['name' => '(SGD) - HSBC Bank', 'code' => 'SHSBC'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => 'SSCB'],
                    ['name' => '(SGD) - Maybank', 'code' => 'SMBB'],
                    ['name' => '(SGD) - RHB Bank', 'code' => 'RHBSG'],
                    ['name' => '(SGD) - Citibank', 'code' => 'CITISG'],
                    ['name' => '(SGD) - ICBC Bank', 'code' => 'ICBCSG'],
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXSB'],
                    ['name' => '(SGD) - Mari Bank', 'code' => 'MARB'],
                    ['name' => '(SGD) - ANEXT Bank', 'code' => 'ANBK'],
                    ['name' => '(SGD) - Match Move Pay', 'code' => 'MPP'],
                ],
            ],
            [
                'currency' => 'IDR',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(IDR) - Bank BRI', 'code' => '002'],
                    ['name' => '(IDR) - Bank Mandiri', 'code' => '008'],
                    ['name' => '(IDR) - Bank BNI', 'code' => '009'],
                    ['name' => '(IDR) - Bank Danamon', 'code' => '011'],
                    ['name' => '(IDR) - Bank Permata', 'code' => '013'],
                    ['name' => '(IDR) - Bank BCA', 'code' => '014'],
                    ['name' => '(IDR) - BII Maybank', 'code' => '016'],
                    ['name' => '(IDR) - Bank Panin', 'code' => '019'],
                    ['name' => '(IDR) - CIMB Niaga', 'code' => '022'],
                    ['name' => '(IDR) - Bank UOB Indonesia', 'code' => '023'],
                    ['name' => '(IDR) - Bank OCBC NISP', 'code' => '028'],
                    ['name' => '(IDR) - Citibank', 'code' => '031'],
                    ['name' => '(IDR) - Bank Windu Kentjana International', 'code' => '036'],
                    ['name' => '(IDR) - Bank Artha Graha', 'code' => '037'],
                    ['name' => '(IDR) - Bank TOKYO MITSUBISHI UFJ', 'code' => '042'],
                    ['name' => '(IDR) - Bank DBS', 'code' => '046'],
                    ['name' => '(IDR) - Standard Chartered', 'code' => '050'],
                    ['name' => '(IDR) - Bank Capital', 'code' => '054'],
                    ['name' => '(IDR) - ANZ Indonesia', 'code' => '061'],
                    ['name' => '(IDR) - Bank of China', 'code' => '069'],
                    ['name' => '(IDR) - Bank Bumi Arta', 'code' => '076'],
                    ['name' => '(IDR) - Bank HSBC', 'code' => '087'],
                    ['name' => '(IDR) - Bank Antardaerah', 'code' => '088'],
                    ['name' => '(IDR) - Bank Rabobank', 'code' => '089'],
                    ['name' => '(IDR) - Bank JTrust Indonesia', 'code' => '095'],
                    ['name' => '(IDR) - Bank Mayapada', 'code' => '097'],
                    ['name' => '(IDR) - Bank Jawa Barat', 'code' => '110'],
                    ['name' => '(IDR) - Bank DKI', 'code' => '111'],
                    ['name' => '(IDR) - Bank BPD DIY', 'code' => '112'],
                    ['name' => '(IDR) - Bank Jateng', 'code' => '113'],
                    ['name' => '(IDR) - Bank Jatim', 'code' => '114'],
                    ['name' => '(IDR) - Bank Jambi', 'code' => '115'],
                    ['name' => '(IDR) - Bank Aceh', 'code' => '116'],
                    ['name' => '(IDR) - Bank Aceh Syariah', 'code' => '116'],
                    ['name' => '(IDR) - Bank Sumut', 'code' => '117'],
                    ['name' => '(IDR) - Bank Nagari', 'code' => '118'],
                    ['name' => '(IDR) - Bank Riau', 'code' => '119'],
                    ['name' => '(IDR) - Bank Riau Syariah', 'code' => '119'],
                    ['name' => '(IDR) - Bank Sumsel Babel', 'code' => '120'],
                    ['name' => '(IDR) - Bank Lampung', 'code' => '121'],
                    ['name' => '(IDR) - Bank Kalsel', 'code' => '122'],
                    ['name' => '(IDR) - Bank Kalbar', 'code' => '123'],
                    ['name' => '(IDR) - Bank BPD Kaltim', 'code' => '124'],
                    ['name' => '(IDR) - Bank BPD Kalteng', 'code' => '125'],
                    ['name' => '(IDR) - Bank Sulselbar', 'code' => '126'],
                    ['name' => '(IDR) - Bank Sulut', 'code' => '127'],
                    ['name' => '(IDR) - Bank NTB', 'code' => '128'],
                    ['name' => '(IDR) - Bank NTB Syariah', 'code' => '128'],
                    ['name' => '(IDR) - Bank BPD Bali', 'code' => '129'],
                    ['name' => '(IDR) - Bank NTT', 'code' => '130'],
                    ['name' => '(IDR) - Bank Maluku', 'code' => '131'],
                    ['name' => '(IDR) - Bank BPD Papua', 'code' => '132'],
                    ['name' => '(IDR) - Bank Sulteng', 'code' => '134'],
                    ['name' => '(IDR) - Bank Sultra', 'code' => '135'],
                    ['name' => '(IDR) - Bank Banten', 'code' => '137'],
                    ['name' => '(IDR) - Bank Nusantara Parahyangan', 'code' => '145'],
                    ['name' => '(IDR) - Bank of India Indonesia', 'code' => '146'],
                    ['name' => '(IDR) - Bank Muamalat', 'code' => '147'],
                    ['name' => '(IDR) - Bank Mestika', 'code' => '151'],
                    ['name' => '(IDR) - Bank Shinhan', 'code' => '152'],
                    ['name' => '(IDR) - Bank Sinarmas', 'code' => '153'],
                    ['name' => '(IDR) - Bank Maspion', 'code' => '157'],
                    ['name' => '(IDR) - Bank Ganesha', 'code' => '161'],
                    ['name' => '(IDR) - Bank ICBC', 'code' => '164'],
                    ['name' => '(IDR) - Bank QNB indonesia', 'code' => '167'],
                    ['name' => '(IDR) - Bank BTN', 'code' => '200'],
                    ['name' => '(IDR) - Bank Woori Saudara', 'code' => '212'],
                    ['name' => '(IDR) - Bank BTPN', 'code' => '213'],
                    ['name' => '(IDR) - Bank Victoria Syariah', 'code' => '405'],
                    ['name' => '(IDR) - Bank Jabar Banten Syariah', 'code' => '425'],
                    ['name' => '(IDR) - Bank Mega', 'code' => '426'],
                    ['name' => '(IDR) - Bank Permata Syariah', 'code' => '427'],
                    ['name' => '(IDR) - Bank Bukopin', 'code' => '441'],
                    ['name' => '(IDR) - Bank Syariah Indonesia', 'code' => '451'],
                    ['name' => '(IDR) - Bank Jasa Jakarta', 'code' => '472'],
                    ['name' => '(IDR) - Bank KEB Hana', 'code' => '484'],
                    ['name' => '(IDR) - Bank MNC International', 'code' => '485'],
                    ['name' => '(IDR) - Bank Neo Commerce', 'code' => '490'],
                    ['name' => '(IDR) - Bank Rakyat Indonesia Agroniaga', 'code' => '494'],
                    ['name' => '(IDR) - Bank SBI Indonesia (Indomonex)', 'code' => '498'],
                    ['name' => '(IDR) - Bank Royal', 'code' => '501'],
                    ['name' => '(IDR) - Bank National Nobu', 'code' => '503'],
                    ['name' => '(IDR) - Bank Mega Syariah', 'code' => '506'],
                    ['name' => '(IDR) - Bank INA', 'code' => '513'],
                    ['name' => '(IDR) - Bank Panin Syariah', 'code' => '517'],
                    ['name' => '(IDR) - Prima Master Bank', 'code' => '520'],
                    ['name' => '(IDR) - Bank Syariah Bukopin', 'code' => '521'],
                    ['name' => '(IDR) - Bank Sahabat Sampoerna', 'code' => '523'],
                    ['name' => '(IDR) - Bank Dinar', 'code' => '526'],
                    ['name' => '(IDR) - Bank Seabank Indonesia', 'code' => '535'],
                    ['name' => '(IDR) - Bank BCA Syariah', 'code' => '536'],
                    ['name' => '(IDR) - Bank ARTOS/ Bank Jago', 'code' => '542'],
                    ['name' => '(IDR) - Bank BTPN Syariah', 'code' => '547'],
                    ['name' => '(IDR) - Bank Multiarta Sentosa', 'code' => '548'],
                    ['name' => '(IDR) - Bank Mayora', 'code' => '553'],
                    ['name' => '(IDR) - Bank Index', 'code' => '555'],
                    ['name' => '(IDR) - CNB', 'code' => '559'],
                    ['name' => '(IDR) - Bank Mantap', 'code' => '564'],
                    ['name' => '(IDR) - Bank Victoria Intl', 'code' => '566'],
                    ['name' => '(IDR) - Harda', 'code' => '567'],
                    ['name' => '(IDR) - BPR KS', 'code' => '688'],
                    ['name' => '(IDR) - IBK', 'code' => '945'],
                    ['name' => '(IDR) - Bank CTBC Indonesia', 'code' => '949'],
                    ['name' => '(IDR) - Bank Commonwealth', 'code' => '950'],
                    ['name' => '(IDR) - LinkAja', 'code' => 'linkaja'],
                    ['name' => '(IDR) - Dana', 'code' => 'dana'],
                    ['name' => '(IDR) - Gopay', 'code' => 'gopay'],
                    ['name' => '(IDR) - ShopeePay', 'code' => 'shopeepay'],
                    ['name' => '(IDR) - OVO', 'code' => 'ovo'],
                ],
            ],
        ],
    ],
];
