<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use App\Models\Currency;
use App\Services\Payment\Providers\FPay\WithdrawBankList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 把 TPower 的 FPay 印尼盾代付銀行代號，從舊的一套換成 FPay 現在給這個商戶帳號的那套。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 背景（target/op_tickets#3174）
 * FPay 的銀行代號 bank_id 是「每個商戶帳號各一套」，不是全平台共用。2026-08-21 FPay 換掉了
 * TPower 這個帳號的整套印尼盾代號，我方代付設定裡的 118 個舊代號有 110 個直接不存在，
 * 送出一律被回「bank id invalid」；另外 8 個（6／8／11／12／13／16／26／27）雖然還在清單上，
 * 但在新清單裡已經是別家銀行——照舊送出就是把錢送去別家銀行，比被退回更嚴重。
 *
 * 同一批舊代號在 Yes2Win 的 FPay 帳號仍然正確，所以**共用設定檔
 * `app/Console/Commands/CashFlow/Data/providers/FPay.php` 不能改**，只能逐白標修這裡的資料。
 *
 * ## 改哪些資料
 * 1. `agent_cash_flows.info.available_banks` 的 `BankCode_Third`（代付送出時實際讀的就是這裡）：
 *    正式組 ACF 272、測試組 ACF 264，兩者是同一個 FPay 商戶帳號，必須成對修。
 * 2. 該白標 `cash_flow_banks`（cash_flow 68、印尼盾）**啟用中**那些列的 `provider_bank_code`。
 *    這張表是 `agent:add-agent-cash-flow` 重建 available_banks 的來源，不一起修，下次重建就會倒退回舊碼。
 * 3. 同一批 `cash_flow_banks` 列的 `manual_override_at` 與 `manual_override_note`（人工覆寫標記）。
 *    標記與「代號有沒有變」脫鉤：代號已經正確但沒標記的列同樣會補上，否則 `cash-flow:sync`
 *    仍會把它沖回設定檔的舊值。
 *
 * 不動銀行主檔、不動金額與限額、不動任何列的啟用狀態，也不動 `BankCode_ELG`。
 *
 * ## 觸發時機
 * 1. 跑 `agent:add-agent-cash-flow` 之後要重跑——它從 `cash_flow_banks` 重建 available_banks，
 *    而本命令對 available_banks 的改寫沒有任何標記可保護。
 * 2. 跑 `cash-flow:sync` 之後**不需要**重跑 `cash_flow_banks` 那一側：自從加了人工覆寫標記，
 *    sync 遇到標記過的列就不再改寫 `provider_bank_code`
 *    （`app/Console/Commands/CashFlow/SyncCashFlowsCommand.php` 的 `createCashFlowBankRelation()`）。
 *    ⚠️ 但**前提是該列已經被標記過**；沒跑過本命令 `--execute` 的列不在保護範圍內。
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 代號與標記都已就緒時，第二次執行判定無需變更、零寫入；
 * 若代號已經正確但標記被清掉（或由更早版本的本命令寫入），重跑只會補標記、不動代號。
 * 預設只出報表，加 `--execute` 才寫入。
 *
 * ## 防呆（任一不符整體攔截、零寫入）
 * - `cash_flows` 68 存在且 `third_party_code` 為 `W_FPAY`、幣別 IDR 存在（跑錯白標 DB 在此被擋）。
 * - 兩條目標 ACF 都存在，且代理群組、金流商、幣別與本檔記載相符。
 * - 兩條 ACF 各自向 FPay 查到的銀行清單必須一模一樣（同一個商戶帳號應得同一份清單）。
 * - 對照表 118 筆的新代號都要在 FPay 清單上，**且銀行名稱與清單回的逐字相同**——
 *   FPay 再換一次代號或改名，這裡就會擋下來，不會照著過期對照表亂寫。
 * - 現況代號不得新舊混雜、不得出現對照表以外的代號；只剩共用代號而無法判斷新舊時也擋下。
 *
 * ## rollback 底稿
 * 本檔對照表反向讀（`new` → `old`），把同一批條目改回去即可；本命令不動其他欄位。
 *
 * ## 退役條件
 * 任一達成即可刪除：FPay 改為全平台同一套代號；或共用設定檔支援逐白標覆寫代號；
 * 或 available_banks 改由 FPay 銀行清單自動維護。在那之前刪掉本命令，等於 sync 後代付再次全壞。
 *
 * ## 關聯
 * - 查 FPay 現行清單：`cash-flow:third-party-query <acf id> --raw`（FPay 的實作見
 *   `app/Services/Payment/Providers/FPay/WithdrawBankList.php`）。
 * - 三方規格：`docs/integrations/payments/FPay/api.md`「銀行列表查詢」。
 *
 * ! 本命令直接用 WithdrawBankList 而非金流商通道查詢介面：介面只帶回銀行代號、不帶銀行名稱，
 * ! 而「這個代號在 FPay 那邊還是不是同一家銀行」正是本命令最重要的防呆。
 *
 * php artisan cash-flow:remap-tpower-fpay-idr-bankcodes            # 只出報表（預設）
 * php artisan cash-flow:remap-tpower-fpay-idr-bankcodes --execute  # 實際寫入（需確認）
 *
 * @knowledge rules/payout-bank-code-mapping
 */
class RemapTpowerFpayIdrBankCodes extends Command
{
    public const int CASH_FLOW_ID = 68;

    public const string CASH_FLOW_THIRD_PARTY_CODE = 'W_FPAY';

    public const string CURRENCY_CODE = 'IDR';

    /** 現況全是舊代號，需要整批換新 */
    private const string ACTION_MIGRATE = 'MIGRATE';

    /** 現況已是新代號，不需要動 */
    private const string ACTION_ALREADY = 'ALREADY';

    /** 寫進 cash_flow_banks.manual_override_note 的出處，供日後查「這列為什麼被鎖住」 */
    private const string OVERRIDE_NOTE = 'target/op_tickets#3174 FPay 按商戶帳號發放銀行代號，此列不可由 cash-flow:sync 覆寫';

    /**
     * 目標代付設定：正式組 272（代理群組 674）與測試組 264（代理群組 688），兩者同一個 FPay 商戶帳號。
     *
     * @var list<array{acf_id: int, agent_group_id: int}>
     */
    public const array TARGETS = [
        ['acf_id' => 272, 'agent_group_id' => 674],
        ['acf_id' => 264, 'agent_group_id' => 688],
    ];

    /**
     * 舊代號 → 新代號對照表（118 筆，2026-09-15 以 FPay 兩個帳號的實際清單逐筆核對）。
     *
     * - `old_name`：共用設定檔 `app/Console/Commands/CashFlow/Data/providers/FPay.php` 對該舊代號記的銀行名稱。
     * - `fpay_name`：FPay 現行清單對新代號回的銀行名稱；寫入前必須與清單逐字相同才放行。
     *
     * @var list<array{old: string, old_name: string, new: string, fpay_name: string}>
     */
    public const array MAPPINGS = [
        ['old' => '1', 'old_name' => 'Bank BRI', 'new' => '11', 'fpay_name' => 'Bank Rakyat Indonesia (BRI)'],
        ['old' => '2', 'old_name' => 'Bank Mandiri', 'new' => '12', 'fpay_name' => 'Mandiri Bank'],
        ['old' => '3', 'old_name' => 'Bank BNI', 'new' => '16', 'fpay_name' => 'Bank Negara Indonesia (BNI)'],
        ['old' => '4', 'old_name' => 'Bank Danamon', 'new' => '27', 'fpay_name' => 'Bank Danamon'],
        ['old' => '5', 'old_name' => 'Bank Permata', 'new' => '8', 'fpay_name' => 'Permata Bank'],
        ['old' => '6', 'old_name' => 'Bank Permata Syariah', 'new' => '499', 'fpay_name' => 'Bank Permata Syariah'],
        ['old' => '7', 'old_name' => 'Bank BCA', 'new' => '13', 'fpay_name' => 'Bank Central Asia (BCA)'],
        ['old' => '8', 'old_name' => 'BII Maybank', 'new' => '319', 'fpay_name' => 'Bank Maybank Indonesia'],
        ['old' => '9', 'old_name' => 'Bank Panin', 'new' => '308', 'fpay_name' => 'PT Bank Pan Indonesia'],
        ['old' => '10', 'old_name' => 'CIMB Niaga', 'new' => '6', 'fpay_name' => 'CIMB Niaga'],
        ['old' => '11', 'old_name' => 'Bank UOB Indonesia', 'new' => '309', 'fpay_name' => 'UOB Bank Indonesia'],
        ['old' => '12', 'old_name' => 'Bank OCBC NISP', 'new' => '307', 'fpay_name' => 'OCBC NISP'],
        ['old' => '13', 'old_name' => 'Citibank', 'new' => '500', 'fpay_name' => 'Citibank'],
        ['old' => '14', 'old_name' => 'Bank of America', 'new' => '501', 'fpay_name' => 'Bank of America NA'],
        ['old' => '15', 'old_name' => 'Bank Windu Kentjana International', 'new' => '502', 'fpay_name' => 'Bank Windu Kentjana International'],
        ['old' => '16', 'old_name' => 'Bank Artha Graha', 'new' => '503', 'fpay_name' => 'Bank Artha Graha'],
        ['old' => '17', 'old_name' => 'Bank TOKYO MITSUBISHI UFJ', 'new' => '504', 'fpay_name' => 'Bank Tokyo Mitsubishi UFJ'],
        ['old' => '18', 'old_name' => 'Bank DBS', 'new' => '313', 'fpay_name' => 'DBS Bank Indonesia'],
        ['old' => '19', 'old_name' => 'Bank Resona Perdania', 'new' => '505', 'fpay_name' => 'Bank Resona Perdania'],
        ['old' => '20', 'old_name' => 'Bank Mizuho Indonesia', 'new' => '506', 'fpay_name' => 'Bank Mizuho Indonesia'],
        ['old' => '21', 'old_name' => 'Standard Chartered', 'new' => '507', 'fpay_name' => 'Standard Chartered'],
        ['old' => '22', 'old_name' => 'Bank Capital', 'new' => '508', 'fpay_name' => 'Bank Capital'],
        ['old' => '23', 'old_name' => 'Bank BNP Paribas', 'new' => '509', 'fpay_name' => 'Bank BNP Paribas'],
        ['old' => '24', 'old_name' => 'ANZ Indonesia', 'new' => '510', 'fpay_name' => 'ANZ Indonesia'],
        ['old' => '25', 'old_name' => 'Bank of China', 'new' => '511', 'fpay_name' => 'Bank of China'],
        ['old' => '26', 'old_name' => 'Bank Bumi Arta', 'new' => '512', 'fpay_name' => 'Bank Bumi Arta'],
        ['old' => '27', 'old_name' => 'Bank HSBC', 'new' => '312', 'fpay_name' => 'HSBC Bank Indonesia'],
        ['old' => '28', 'old_name' => 'Bank Antardaerah', 'new' => '513', 'fpay_name' => 'Bank Antardaerah'],
        ['old' => '29', 'old_name' => 'Bank Rabobank', 'new' => '514', 'fpay_name' => 'Bank Rabobank'],
        ['old' => '30', 'old_name' => 'Bank JTrust Indonesia', 'new' => '515', 'fpay_name' => 'Bank J Trust Indonesia'],
        ['old' => '31', 'old_name' => 'Bank Mayapada', 'new' => '516', 'fpay_name' => 'Bank Mayapada'],
        ['old' => '32', 'old_name' => 'Bank Jawa Barat', 'new' => '517', 'fpay_name' => 'Bank Jawa Barat'],
        ['old' => '33', 'old_name' => 'Bank DKI', 'new' => '518', 'fpay_name' => 'Bank DKI'],
        ['old' => '34', 'old_name' => 'Bank BPD DIY', 'new' => '519', 'fpay_name' => 'BPD Yogyakarta'],
        ['old' => '35', 'old_name' => 'Bank Jateng', 'new' => '520', 'fpay_name' => 'Bank Jateng'],
        ['old' => '36', 'old_name' => 'Bank Jatim', 'new' => '521', 'fpay_name' => 'Bank Jatim'],
        ['old' => '37', 'old_name' => 'Bank Jambi', 'new' => '522', 'fpay_name' => 'Bank Jambi'],
        ['old' => '38', 'old_name' => 'Bank Jambi Syariah', 'new' => '523', 'fpay_name' => 'Bank Jambi Syariah'],
        ['old' => '39', 'old_name' => 'Bank Aceh', 'new' => '524', 'fpay_name' => 'Bank Aceh'],
        ['old' => '40', 'old_name' => 'Bank Aceh Syariah', 'new' => '525', 'fpay_name' => 'Bank Aceh Syariah'],
        ['old' => '41', 'old_name' => 'Bank Sumut', 'new' => '526', 'fpay_name' => 'Bank Sumut'],
        ['old' => '42', 'old_name' => 'Bank Nagari', 'new' => '527', 'fpay_name' => 'Bank Nagari'],
        ['old' => '43', 'old_name' => 'Bank Riau', 'new' => '528', 'fpay_name' => 'Bank Riau Kepri'],
        ['old' => '44', 'old_name' => 'Bank Riau Syariah', 'new' => '529', 'fpay_name' => 'Bank Riau Syariah'],
        ['old' => '45', 'old_name' => 'Bank Sumsel Babel', 'new' => '530', 'fpay_name' => 'Bank Sumsel Babel'],
        ['old' => '46', 'old_name' => 'Bank Sumsel Babel Syariah', 'new' => '531', 'fpay_name' => 'Bank Sumsel Babel Syariah'],
        ['old' => '47', 'old_name' => 'Bank Lampung', 'new' => '532', 'fpay_name' => 'Bank Lampung'],
        ['old' => '48', 'old_name' => 'Bank Kalsel', 'new' => '533', 'fpay_name' => 'Bank Kalsel'],
        ['old' => '49', 'old_name' => 'Bank Kalbar', 'new' => '534', 'fpay_name' => 'Bank Kalbar'],
        ['old' => '50', 'old_name' => 'Bank BPD Kaltim', 'new' => '535', 'fpay_name' => 'Bank BPD Kaltim'],
        ['old' => '51', 'old_name' => 'Bank BPD Kalteng', 'new' => '316', 'fpay_name' => 'Bank Kalteng'],
        ['old' => '52', 'old_name' => 'Bank Sulselbar', 'new' => '536', 'fpay_name' => 'Bank Sulselbar'],
        ['old' => '53', 'old_name' => 'Bank Sulut', 'new' => '537', 'fpay_name' => 'Bank Sulut'],
        ['old' => '54', 'old_name' => 'Bank NTB', 'new' => '538', 'fpay_name' => 'Bank NTB'],
        ['old' => '55', 'old_name' => 'Bank NTB Syariah', 'new' => '539', 'fpay_name' => 'Bank NTB Syariah'],
        ['old' => '56', 'old_name' => 'Bank BPD Bali', 'new' => '540', 'fpay_name' => 'Bank BPD Bali'],
        ['old' => '57', 'old_name' => 'Bank NTT', 'new' => '541', 'fpay_name' => 'Bank NTT'],
        ['old' => '58', 'old_name' => 'Bank Maluku', 'new' => '542', 'fpay_name' => 'Bank Maluku'],
        ['old' => '59', 'old_name' => 'Bank BPD Papua', 'new' => '543', 'fpay_name' => 'Bank BPD Papua'],
        ['old' => '60', 'old_name' => 'Bank Bengkulu', 'new' => '544', 'fpay_name' => 'Bank Bengkulu'],
        ['old' => '61', 'old_name' => 'Bank Sulteng', 'new' => '545', 'fpay_name' => 'Bank Sulteng'],
        ['old' => '62', 'old_name' => 'Bank Sultra', 'new' => '546', 'fpay_name' => 'Bank Sultra'],
        ['old' => '63', 'old_name' => 'Bank Banten', 'new' => '547', 'fpay_name' => 'Bank Banten'],
        ['old' => '64', 'old_name' => 'Bank Nusantara Parahyangan', 'new' => '548', 'fpay_name' => 'Bank Nusantara Parahyangan'],
        ['old' => '65', 'old_name' => 'Bank of India Indonesia', 'new' => '549', 'fpay_name' => 'Bank of India Indonesia'],
        ['old' => '66', 'old_name' => 'Bank Muamalat', 'new' => '550', 'fpay_name' => 'Bank Muamalat'],
        ['old' => '67', 'old_name' => 'Bank Mestika', 'new' => '551', 'fpay_name' => 'Bank Mestika'],
        ['old' => '68', 'old_name' => 'Bank Shinhan', 'new' => '552', 'fpay_name' => 'Bank Shinhan'],
        ['old' => '69', 'old_name' => 'Bank Sinarmas', 'new' => '305', 'fpay_name' => 'Bank Sinarmas'],
        ['old' => '70', 'old_name' => 'Bank Maspion', 'new' => '553', 'fpay_name' => 'Bank Maspion'],
        ['old' => '71', 'old_name' => 'Bank Ganesha', 'new' => '554', 'fpay_name' => 'Bank Ganesha'],
        ['old' => '72', 'old_name' => 'Bank ICBC', 'new' => '555', 'fpay_name' => 'Bank ICBC'],
        ['old' => '73', 'old_name' => 'Bank QNB indonesia', 'new' => '556', 'fpay_name' => 'Bank QNB Indonesia'],
        ['old' => '74', 'old_name' => 'Bank BTN', 'new' => '306', 'fpay_name' => 'Bank Tabungan Negara'],
        ['old' => '75', 'old_name' => 'Bank Woori Saudara', 'new' => '557', 'fpay_name' => 'Bank Woori Saudara'],
        ['old' => '76', 'old_name' => 'Bank BTPN', 'new' => '26', 'fpay_name' => 'Bank Tabungan Pensiunan Nasional'],
        ['old' => '77', 'old_name' => 'Bank Victoria Syariah', 'new' => '558', 'fpay_name' => 'Bank Victoria Syariah'],
        ['old' => '78', 'old_name' => 'Bank Jabar Banten Syariah', 'new' => '559', 'fpay_name' => 'Bank Jabar Banten Syariah'],
        ['old' => '79', 'old_name' => 'Bank Mega', 'new' => '304', 'fpay_name' => 'Bank Mega'],
        ['old' => '80', 'old_name' => 'Bank Bukopin', 'new' => '560', 'fpay_name' => 'Bank Bukopin'],
        ['old' => '81', 'old_name' => 'Bank Syariah Indonesia', 'new' => '310', 'fpay_name' => 'Bank Syariah Indonesia'],
        ['old' => '82', 'old_name' => 'Bank Jasa Jakarta', 'new' => '561', 'fpay_name' => 'Bank Jasa Jakarta'],
        ['old' => '83', 'old_name' => 'Bank KEB Hana', 'new' => '314', 'fpay_name' => 'Hana Bank Indonesia'],
        ['old' => '84', 'old_name' => 'Bank MNC International', 'new' => '315', 'fpay_name' => 'Bank MNC Internasional'],
        ['old' => '85', 'old_name' => 'Bank Neo Commerce', 'new' => '562', 'fpay_name' => 'Bank Neo Commerce'],
        ['old' => '86', 'old_name' => 'Bank Rakyat Indonesia Agroniaga', 'new' => '563', 'fpay_name' => 'Bank BRI Agroniaga'],
        ['old' => '87', 'old_name' => 'Bank SBI Indonesia (Indomonex)', 'new' => '564', 'fpay_name' => 'Bank SBI Indonesia'],
        ['old' => '88', 'old_name' => 'Bank Royal', 'new' => '565', 'fpay_name' => 'Bank Royal'],
        ['old' => '89', 'old_name' => 'Bank National Nobu', 'new' => '566', 'fpay_name' => 'Bank National Nobu'],
        ['old' => '90', 'old_name' => 'Bank Mega Syariah', 'new' => '567', 'fpay_name' => 'Bank Mega Syariah'],
        ['old' => '91', 'old_name' => 'Bank INA', 'new' => '568', 'fpay_name' => 'Bank Ina Perdana'],
        ['old' => '92', 'old_name' => 'Bank Panin Syariah', 'new' => '569', 'fpay_name' => 'Bank Panin Syariah'],
        ['old' => '93', 'old_name' => 'Prima Master Bank', 'new' => '570', 'fpay_name' => 'Prima Master Bank'],
        ['old' => '94', 'old_name' => 'Bank Syariah Bukopin', 'new' => '571', 'fpay_name' => 'Bank Syariah Bukopin'],
        ['old' => '95', 'old_name' => 'Bank Sahabat Sampoerna', 'new' => '572', 'fpay_name' => 'Bank Sahabat Sampoerna'],
        ['old' => '96', 'old_name' => 'Bank Dinar', 'new' => '573', 'fpay_name' => 'Bank Dinar'],
        ['old' => '97', 'old_name' => 'Bank AMAR', 'new' => '574', 'fpay_name' => 'Bank Amar'],
        ['old' => '98', 'old_name' => 'Bank Seabank Indonesia', 'new' => '318', 'fpay_name' => 'Seabank Indonesia'],
        ['old' => '99', 'old_name' => 'Bank BCA Syariah', 'new' => '575', 'fpay_name' => 'Bank BCA Syariah'],
        ['old' => '100', 'old_name' => 'Bank ARTOS/ Bank Jago', 'new' => '576', 'fpay_name' => 'Bank Jago'],
        ['old' => '101', 'old_name' => 'Bank BTPN Syariah', 'new' => '577', 'fpay_name' => 'Bank BTPN Syariah'],
        ['old' => '102', 'old_name' => 'Bank MULTIARTA SENTOSA', 'new' => '578', 'fpay_name' => 'Bank Multiarta Sentosa'],
        ['old' => '103', 'old_name' => 'Bank Mayora', 'new' => '579', 'fpay_name' => 'Bank Mayora'],
        ['old' => '104', 'old_name' => 'Bank Index', 'new' => '580', 'fpay_name' => 'Bank Index'],
        ['old' => '105', 'old_name' => 'CNB', 'new' => '581', 'fpay_name' => 'Central Nasional Bank'],
        ['old' => '106', 'old_name' => 'Bank MANTAP', 'new' => '582', 'fpay_name' => 'Bank Mantap'],
        ['old' => '107', 'old_name' => 'Bank Victoria Intl', 'new' => '583', 'fpay_name' => 'Bank Victoria International'],
        ['old' => '108', 'old_name' => 'HARDA', 'new' => '584', 'fpay_name' => 'Bank Harda Internasional'],
        ['old' => '109', 'old_name' => 'BPR KS', 'new' => '585', 'fpay_name' => 'BPRKS'],
        ['old' => '110', 'old_name' => 'IBK', 'new' => '586', 'fpay_name' => 'Bank IBK'],
        ['old' => '111', 'old_name' => 'Bank Aladin Syariah', 'new' => '587', 'fpay_name' => 'Bank Aladin Syariah'],
        ['old' => '112', 'old_name' => 'Bank CTBC Indonesia', 'new' => '588', 'fpay_name' => 'Bank China Trust Indonesia'],
        ['old' => '113', 'old_name' => 'Bank Commonwealth', 'new' => '311', 'fpay_name' => 'PT Bank Commonwealth'],
        ['old' => '114', 'old_name' => 'Dana', 'new' => '493', 'fpay_name' => 'DANA'],
        ['old' => '115', 'old_name' => 'Gopay', 'new' => '494', 'fpay_name' => 'GoPay'],
        ['old' => '116', 'old_name' => 'LinkAja', 'new' => '495', 'fpay_name' => 'LinkAja'],
        ['old' => '117', 'old_name' => 'Ovo Payment', 'new' => '496', 'fpay_name' => 'OVO'],
        ['old' => '118', 'old_name' => 'ShopeePay', 'new' => '497', 'fpay_name' => 'ShopeePay'],
    ];

    protected $signature = 'cash-flow:remap-tpower-fpay-idr-bankcodes
                            {--execute : 實際寫入（預設僅出報表）}
                            {--force : 跳過執行前確認}
                            {--detail : 連同 118 筆對照逐列印出}
                            {--align-from-payout-config : 改跑校正：以代付設定的 available_banks 為準，把 cash_flow_banks 對不上的三方代號改回來}';

    protected $description = '把 TPower FPay 印尼盾代付的銀行代號換成 FPay 現行清單的代號（預設只出報表）'; // hardcode-ok: 本命令專修單一白標的資料，命令說明不指名白標就看不懂用途

    public function handle(): int
    {
        $errors = [];

        $currencyId = $this->resolveCurrencyId($errors);
        $this->assertCashFlow($errors);
        $targets = $this->resolveTargets($currencyId, $errors);

        if ($errors !== []) {
            return $this->abort($errors);
        }

        // 校正模式與換碼模式互斥：校正只比對代付設定與 cash_flow_banks，不必連 FPay
        if ($this->option('align-from-payout-config')) {
            return $this->runAlign($targets, $currencyId, $errors);
        }

        $bankNames = $this->resolveFpayBankNames($targets, $errors);

        if ($bankNames === null || $errors !== []) {
            return $this->abort($errors);
        }

        $this->assertMappingsStillValid($bankNames, $errors);

        if ($errors !== []) {
            return $this->abort($errors);
        }

        $acfPlans = $this->buildAcfPlans($targets, $errors);
        $bankRowPlan = $this->buildCashFlowBankPlan($currencyId, $errors);

        if ($errors !== [] || $bankRowPlan === null) {
            return $this->abort($errors);
        }

        $this->report($bankNames, $acfPlans, $bankRowPlan);

        $pendingChanges = $bankRowPlan['changes']
            + array_sum(array_map(static fn (array $plan): int => $plan['changes'], $acfPlans));
        // 待補標記與待換代號各自獨立：代號已經正確但沒標記的列，仍然會被 cash-flow:sync 沖回舊值
        $pendingMarks = $bankRowPlan['unmarked'];

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf(
                '🔍 只出報表，未寫入。待更新 %d 筆代號、待補 %d 筆人工覆寫標記。加 --execute 以實際寫入。',
                $pendingChanges,
                $pendingMarks,
            ));

            return self::SUCCESS;
        }

        if ($pendingChanges === 0 && $pendingMarks === 0) {
            $this->newLine();
            $this->info('✅ 現況已是新代號且都已標記，無需變更（冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(
                sprintf('確認更新 %d 筆代付銀行代號、補 %d 筆人工覆寫標記？', $pendingChanges, $pendingMarks),
                false,
            )) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $written = $this->write($acfPlans, $bankRowPlan);

        $this->newLine();
        $this->info(sprintf(
            '✅ 完成：代付設定 %d 筆條目、cash_flow_banks %d 列換碼／%d 列補標記（rollback 底稿＝本命令對照表反向讀）。',
            $written['acf_entries'],
            $written['bank_rows'],
            $written['bank_marks'],
        ));

        return self::SUCCESS;
    }

    /**
     * 幣別以代碼查，查不到即視為跑錯白標 DB。
     *
     * @param  list<string>  $errors
     */
    private function resolveCurrencyId(array &$errors): int
    {
        $currencyId = (int) Currency::query()->where('code', self::CURRENCY_CODE)->value('id');

        if ($currencyId <= 0) {
            $errors[] = sprintf('查無幣別 %s，請確認 DB_CONNECTION 指向 TPower', self::CURRENCY_CODE); // hardcode-ok: 本命令專修單一白標，提示不指名要連哪個 DB 就失去意義
        }

        return $currencyId;
    }

    /**
     * 金流商必須是 FPay 代付，避免代號被寫到別家金流商的設定上。
     *
     * @param  list<string>  $errors
     */
    private function assertCashFlow(array &$errors): void
    {
        $cashFlow = CashFlow::query()->find(self::CASH_FLOW_ID);

        if ($cashFlow === null) {
            $errors[] = sprintf('查無 cash_flow %d，請確認 DB_CONNECTION 指向 TPower', self::CASH_FLOW_ID); // hardcode-ok: 同上，跑錯白標時要直接指出該連哪個 DB

            return;
        }

        if ((string) $cashFlow->third_party_code !== self::CASH_FLOW_THIRD_PARTY_CODE) {
            $errors[] = sprintf(
                'cash_flow %d 的 third_party_code=%s ≠ 預期 %s',
                self::CASH_FLOW_ID,
                (string) $cashFlow->third_party_code,
                self::CASH_FLOW_THIRD_PARTY_CODE,
            );
        }
    }

    /**
     * 逐一取出目標代付設定並核對群組、金流商、幣別。
     *
     * @param  list<string>  $errors
     * @return list<array{acf: AgentCashFlow, agent_group_id: int}>
     */
    private function resolveTargets(int $currencyId, array &$errors): array
    {
        $targets = [];

        foreach (self::TARGETS as $target) {
            $agentCashFlow = AgentCashFlow::query()->find($target['acf_id']);

            if ($agentCashFlow === null) {
                $errors[] = sprintf('ACF %d：DB 無此列', $target['acf_id']);

                continue;
            }

            if ((int) $agentCashFlow->agent_group_id !== $target['agent_group_id']) {
                $errors[] = sprintf(
                    'ACF %d：agent_group_id=%d ≠ 預期 %d',
                    $target['acf_id'],
                    (int) $agentCashFlow->agent_group_id,
                    $target['agent_group_id'],
                );

                continue;
            }

            if ((int) $agentCashFlow->cash_flow_id !== self::CASH_FLOW_ID) {
                $errors[] = sprintf(
                    'ACF %d：cash_flow_id=%d ≠ 預期 %d',
                    $target['acf_id'],
                    (int) $agentCashFlow->cash_flow_id,
                    self::CASH_FLOW_ID,
                );

                continue;
            }

            if ($currencyId > 0 && (int) $agentCashFlow->currency_id !== $currencyId) {
                $errors[] = sprintf(
                    'ACF %d：currency_id=%d ≠ %s 的 %d',
                    $target['acf_id'],
                    (int) $agentCashFlow->currency_id,
                    self::CURRENCY_CODE,
                    $currencyId,
                );

                continue;
            }

            $targets[] = ['acf' => $agentCashFlow, 'agent_group_id' => $target['agent_group_id']];
        }

        return $targets;
    }

    /**
     * 向 FPay 查每個目標帳號的銀行清單，兩份必須一致（同一個商戶帳號應得同一份）。
     *
     * @param  list<array{acf: AgentCashFlow, agent_group_id: int}>  $targets
     * @param  list<string>  $errors
     * @return array<array-key, string>|null 銀行代號 => FPay 銀行名稱
     */
    private function resolveFpayBankNames(array $targets, array &$errors): ?array
    {
        $byAcf = [];

        foreach ($targets as $target) {
            $names = $this->fetchBankNames($target['acf'], $errors);

            if ($names === null) {
                return null;
            }

            $byAcf[(int) $target['acf']->id] = $names;
        }

        if ($byAcf === []) {
            $errors[] = '沒有可查詢的目標代付設定';

            return null;
        }

        $first = null;
        $firstAcfId = 0;

        foreach ($byAcf as $acfId => $names) {
            if ($first === null) {
                $first = $names;
                $firstAcfId = $acfId;

                continue;
            }

            if ($names !== $first) {
                $errors[] = sprintf('ACF %d 與 ACF %d 查到的 FPay 銀行清單不一致，轉人工確認', $firstAcfId, $acfId);

                return null;
            }
        }

        return $first;
    }

    /**
     * 取單一代付設定的 FPay 銀行清單；缺代號或缺名稱的列一律視為異常，不靜默略過。
     *
     * ! 鍵是銀行代號。PHP 會把 '11' 這種純數字字串鍵存成整數鍵，本檔所有代號對照因此都標 array-key，
     * ! 不標 string；查表照樣查得到（查的時候同樣會轉成整數鍵）。
     *
     * @param  list<string>  $errors
     * @return array<array-key, string>|null
     */
    private function fetchBankNames(AgentCashFlow $agentCashFlow, array &$errors): ?array
    {
        try {
            $rows = (new WithdrawBankList($agentCashFlow))->handle();
        } catch (Throwable $e) {
            $errors[] = sprintf('ACF %d：查 FPay 銀行清單失敗：%s', (int) $agentCashFlow->id, $e->getMessage());

            return null;
        }

        $names = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $name = $row['bank_name'] ?? null;

            if ((!is_string($id) && !is_int($id)) || (string) $id === '' || !is_string($name) || $name === '') {
                $errors[] = sprintf('ACF %d：FPay 清單有缺代號或缺銀行名稱的列', (int) $agentCashFlow->id);

                return null;
            }

            $names[(string) $id] = $name;
        }

        if ($names === []) {
            $errors[] = sprintf('ACF %d：FPay 回的銀行清單是空的', (int) $agentCashFlow->id);

            return null;
        }

        return $names;
    }

    /**
     * 對照表的新代號必須還在 FPay 清單上，而且銀行名稱逐字相同。
     *
     * @param  array<array-key, string>  $bankNames
     * @param  list<string>  $errors
     */
    private function assertMappingsStillValid(array $bankNames, array &$errors): void
    {
        foreach (self::MAPPINGS as $mapping) {
            $actual = $bankNames[$mapping['new']] ?? null;

            if ($actual === null) {
                $errors[] = sprintf(
                    '對照表的新代號 %s（%s）不在 FPay 現行清單上，FPay 可能又換過代號',
                    $mapping['new'],
                    $mapping['fpay_name'],
                );

                continue;
            }

            if ($actual !== $mapping['fpay_name']) {
                $errors[] = sprintf(
                    '新代號 %s 在 FPay 清單是「%s」，與對照表記的「%s」不同，轉人工確認',
                    $mapping['new'],
                    $actual,
                    $mapping['fpay_name'],
                );
            }
        }
    }

    /**
     * 逐一判定代付設定要不要換代號。
     *
     * @param  list<array{acf: AgentCashFlow, agent_group_id: int}>  $targets
     * @param  list<string>  $errors
     * @return list<array{acf_id: int, agent_group_id: int, action: string, entries: int, changes: int}>
     */
    private function buildAcfPlans(array $targets, array &$errors): array
    {
        $plans = [];

        foreach ($targets as $target) {
            $agentCashFlow = $target['acf'];
            $acfId = (int) $agentCashFlow->id;
            $availableBanks = $agentCashFlow->info['available_banks'] ?? null;

            if (!is_array($availableBanks) || $availableBanks === []) {
                $errors[] = sprintf('ACF %d：info.available_banks 缺失或格式錯誤', $acfId);

                continue;
            }

            $codes = [];

            foreach ($availableBanks as $entry) {
                $codes[] = is_array($entry) ? (string) ($entry['BankCode_Third'] ?? '') : '';
            }

            $action = $this->classifyCodes($codes, sprintf('ACF %d', $acfId), $errors);

            if ($action === null) {
                continue;
            }

            $plans[] = [
                'acf_id' => $acfId,
                'agent_group_id' => $target['agent_group_id'],
                'action' => $action,
                'entries' => count($availableBanks),
                'changes' => $action === self::ACTION_MIGRATE ? count($availableBanks) : 0,
            ];
        }

        return $plans;
    }

    /**
     * 判定 cash_flow_banks 啟用中的列要不要換代號；停用中的列只統計、不動。
     *
     * unmarked ＝啟用中還沒補人工覆寫標記的列數。它與「代號要不要換」各自獨立：
     * 代號已經換好、但沒標記的列，下一次 cash-flow:sync 照樣會沖回設定檔的舊值。
     *
     * @param  list<string>  $errors
     * @return array{action: string, rows: int, changes: int, unmarked: int, disabled_rows: int}|null
     */
    private function buildCashFlowBankPlan(int $currencyId, array &$errors): ?array
    {
        $activeCodes = CashFlowBank::query()
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->pluck('provider_bank_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $disabledRows = CashFlowBank::query()
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $currencyId)
            ->where('status', '!=', 1)
            ->count();

        $unmarked = CashFlowBank::query()
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->whereNull('manual_override_at')
            ->count();

        if ($activeCodes === []) {
            return [
                'action' => self::ACTION_ALREADY,
                'rows' => 0,
                'changes' => 0,
                'unmarked' => 0,
                'disabled_rows' => $disabledRows,
            ];
        }

        $action = $this->classifyCodes($activeCodes, 'cash_flow_banks（啟用中）', $errors);

        if ($action === null) {
            return null;
        }

        return [
            'action' => $action,
            'rows' => count($activeCodes),
            'changes' => $action === self::ACTION_MIGRATE ? count($activeCodes) : 0,
            'unmarked' => $unmarked,
            'disabled_rows' => $disabledRows,
        ];
    }

    /**
     * 判定一組代號屬於「全是舊碼」還是「全是新碼」；混雜、含表外代號、或只剩新舊共用代號時擋下。
     *
     * @param  list<string>  $codes
     * @param  list<string>  $errors
     */
    private function classifyCodes(array $codes, string $label, array &$errors): ?string
    {
        $oldCodes = array_column(self::MAPPINGS, 'old');
        $newCodes = array_column(self::MAPPINGS, 'new');
        $oldOnly = array_diff($oldCodes, $newCodes);
        $newOnly = array_diff($newCodes, $oldCodes);

        $unknown = array_values(array_unique(array_diff($codes, $oldCodes, $newCodes)));

        if ($unknown !== []) {
            $errors[] = sprintf(
                '%s：有 %d 個代號不在對照表內（%s），轉人工確認',
                $label,
                count($unknown),
                implode('、', array_slice($unknown, 0, 10)),
            );

            return null;
        }

        $hasOldOnly = array_intersect($codes, $oldOnly) !== [];
        $hasNewOnly = array_intersect($codes, $newOnly) !== [];

        if ($hasOldOnly && $hasNewOnly) {
            $errors[] = sprintf('%s：新舊代號混雜，轉人工確認', $label);

            return null;
        }

        if ($hasOldOnly) {
            return self::ACTION_MIGRATE;
        }

        if ($hasNewOnly) {
            return self::ACTION_ALREADY;
        }

        $errors[] = sprintf('%s：現有代號全是新舊共用的那幾個，判不出換過沒有，轉人工確認', $label);

        return null;
    }

    /**
     * @param  array<array-key, string>  $bankNames
     * @param  list<array{acf_id: int, agent_group_id: int, action: string, entries: int, changes: int}>  $acfPlans
     * @param  array{action: string, rows: int, changes: int, unmarked: int, disabled_rows: int}  $bankRowPlan
     */
    private function report(array $bankNames, array $acfPlans, array $bankRowPlan): void
    {
        $this->info(sprintf(
            'FPay 現行清單 %d 家；對照表 %d 筆新代號全部在清單上，銀行名稱逐字相同。',
            count($bankNames),
            count(self::MAPPINGS),
        ));
        $this->newLine();

        $rows = array_map(static fn (array $plan): array => [
            sprintf('ACF %d（群組 %d）', $plan['acf_id'], $plan['agent_group_id']),
            $plan['entries'],
            $plan['action'] === self::ACTION_MIGRATE ? '換成新代號' : '已是新代號',
            $plan['changes'],
        ], $acfPlans);

        $rows[] = [
            'cash_flow_banks（啟用中）',
            $bankRowPlan['rows'],
            $bankRowPlan['action'] === self::ACTION_MIGRATE ? '換成新代號' : '已是新代號',
            $bankRowPlan['changes'],
        ];

        $this->table(['對象', '筆數', '判定', '待更新'], $rows);

        if ($bankRowPlan['disabled_rows'] > 0) {
            $this->line(sprintf(
                '　cash_flow_banks 另有 %d 列停用中，代付不會讀到，本命令不動。',
                $bankRowPlan['disabled_rows'],
            ));
        }

        if ($bankRowPlan['unmarked'] > 0) {
            $this->line(sprintf(
                '　cash_flow_banks 啟用中有 %d 列還沒有人工覆寫標記，本次會補上；補上後 cash-flow:sync 不再覆寫該列的三方代號。',
                $bankRowPlan['unmarked'],
            ));
        }

        $this->reportReusedCodes($bankNames);

        if ($this->option('detail')) {
            $this->newLine();
            $this->table(
                ['舊代號', '舊設定的銀行', '新代號', 'FPay 現行銀行名稱'],
                array_map(static fn (array $mapping): array => [
                    $mapping['old'],
                    $mapping['old_name'],
                    $mapping['new'],
                    $mapping['fpay_name'],
                ], self::MAPPINGS),
            );
        }
    }

    /**
     * 新舊共用的代號在 FPay 已換成別家銀行，照舊送出會把錢送到別家銀行——報表一定要看得到。
     *
     * @param  array<array-key, string>  $bankNames
     */
    private function reportReusedCodes(array $bankNames): void
    {
        $oldCodes = array_column(self::MAPPINGS, 'old');
        $reused = array_values(array_intersect($oldCodes, array_column(self::MAPPINGS, 'new')));

        if ($reused === []) {
            return;
        }

        $rows = [];

        foreach ($reused as $code) {
            foreach (self::MAPPINGS as $mapping) {
                if ($mapping['old'] === $code) {
                    $rows[] = [$code, $mapping['old_name'], $bankNames[$code] ?? '-'];

                    break;
                }
            }
        }

        $this->newLine();
        $this->warn(sprintf('⚠️ 下列 %d 個代號還在 FPay 清單上，但已經換成別家銀行（照舊送出＝送到別家銀行）：', count($rows)));
        $this->table(['代號', '我方舊設定當它是', 'FPay 現在當它是'], $rows);
    }

    /**
     * 寫入（單一交易）：逐條 ACF 與逐列 cash_flow_banks 鎖定後重驗，對不上就整筆交易回滾。
     *
     * @param  list<array{acf_id: int, agent_group_id: int, action: string, entries: int, changes: int}>  $acfPlans
     * @param  array{action: string, rows: int, changes: int, unmarked: int, disabled_rows: int}  $bankRowPlan
     * @return array{acf_entries: int, bank_rows: int, bank_marks: int}
     */
    private function write(array $acfPlans, array $bankRowPlan): array
    {
        $codeMap = array_column(self::MAPPINGS, 'new', 'old');

        return DB::transaction(function () use ($acfPlans, $bankRowPlan, $codeMap): array {
            $acfEntries = 0;

            foreach ($acfPlans as $plan) {
                if ($plan['action'] !== self::ACTION_MIGRATE) {
                    continue;
                }

                $acfEntries += $this->rewriteAvailableBanks($plan['acf_id'], $codeMap);
            }

            // 不論判定是換碼還是「已是新代號」都要進來：後者仍可能缺標記，
            // 缺標記的列下一次 cash-flow:sync 照樣會被沖回設定檔的舊值。
            // 換不換代號則一律依報表那側算出的判定，不在寫入端重新推斷（理由見該方法說明）。
            $bankResult = $this->rewriteCashFlowBanks($codeMap, $bankRowPlan['action']);

            Log::info('FPay 印尼盾代付銀行代號重映射', [
                'cash_flow_id' => self::CASH_FLOW_ID,
                'acf_ids' => array_column($acfPlans, 'acf_id'),
                'acf_entries' => $acfEntries,
                'cash_flow_bank_rows' => $bankResult['changed'],
                'cash_flow_bank_marks' => $bankResult['marked'],
                'ticket' => 'target/op_tickets#3174',
            ]);

            return [
                'acf_entries' => $acfEntries,
                'bank_rows' => $bankResult['changed'],
                'bank_marks' => $bankResult['marked'],
            ];
        });
    }

    /**
     * @param  array<array-key, string>  $codeMap
     */
    private function rewriteAvailableBanks(int $acfId, array $codeMap): int
    {
        /** @var AgentCashFlow $agentCashFlow */
        $agentCashFlow = AgentCashFlow::query()->lockForUpdate()->findOrFail($acfId);

        $info = $agentCashFlow->info;
        $availableBanks = $info['available_banks'] ?? null;

        if (!is_array($availableBanks) || $availableBanks === []) {
            throw new RuntimeException(sprintf('ACF %d：available_banks 在鎖定後讀到空值，已回滾', $acfId));
        }

        $changed = 0;

        foreach ($availableBanks as $index => $entry) {
            $current = is_array($entry) ? (string) ($entry['BankCode_Third'] ?? '') : '';
            $new = $codeMap[$current] ?? null;

            if ($new === null) {
                throw new RuntimeException(sprintf('ACF %d：代號 %s 不在對照表內，已回滾', $acfId, $current));
            }

            if ($new === $current) {
                continue;
            }

            $availableBanks[$index]['BankCode_Third'] = $new;
            $changed++;
        }

        $info['available_banks'] = $availableBanks;
        $agentCashFlow->info = $info;
        $agentCashFlow->save();

        return $changed;
    }

    /**
     * 改寫啟用中的三方代號，並為每一列補上人工覆寫標記。
     *
     * 🔴 換不換代號一律依傳進來的 $action，**禁止在這裡逐列推斷代號是舊的還是新的**。
     * 有 8 個代號在新舊兩套裡都有效（例 11 舊指 UOB、新指 BRI），逐列查舊→新對照表必然命中，
     * 於是把已經正確的列二次換碼、指到別家銀行。2026-09-17 正式環境即因此寫壞 7 列
     * （target/op_tickets#3174）：當時報表用整組判定算出「待更新 0」、寫入端卻自己推斷，兩邊結論不一致。
     * 單看一列分不出新舊，只有 classifyCodes() 從整組代號判得出來，判定必須由呼叫端傳進來。
     *
     * 標記則與 $action 無關：已經是新代號的列同樣要補標記，否則 cash-flow:sync
     * 下一次仍會把它沖回設定檔的舊值——那正是本命令要防的事。
     *
     * @param  array<array-key, string>  $codeMap  舊代號 → 新代號
     * @param  string  $action  classifyCodes() 對這整組代號的判定（ACTION_MIGRATE／ACTION_ALREADY）
     * @return array{changed: int, marked: int}
     */
    private function rewriteCashFlowBanks(array $codeMap, string $action): array
    {
        $currencyId = (int) Currency::query()->where('code', self::CURRENCY_CODE)->value('id');

        $rows = CashFlowBank::query()
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->lockForUpdate()
            ->get();

        $changed = 0;
        $marked = 0;

        foreach ($rows as $row) {
            $current = (string) $row->provider_bank_code;
            $dirty = false;

            if ($action === self::ACTION_MIGRATE) {
                // 判定為「整組都還是舊代號」，所以查不到對照就是表外代號，整筆交易回滾。
                // 表外代號在 classifyCodes() 就會先攔下，這裡是鎖定後的重驗。
                $new = $codeMap[$current] ?? null;

                if ($new === null) {
                    throw new RuntimeException(sprintf('cash_flow_banks %d：代號 %s 不在對照表內，已回滾', (int) $row->id, $current));
                }

                if ($new !== $current) {
                    $row->provider_bank_code = $new;
                    $changed++;
                    $dirty = true;
                }
            }

            if ($row->manual_override_at === null) {
                $row->manual_override_at = now();
                $row->manual_override_note = self::OVERRIDE_NOTE;
                $marked++;
                $dirty = true;
            }

            if ($dirty) {
                $row->save();
            }
        }

        return ['changed' => $changed, 'marked' => $marked];
    }

    /**
     * 校正模式：以代付設定的 available_banks 為準，把 cash_flow_banks 對不上的三方代號改回來。
     *
     * 為什麼需要另一個模式：換碼與校正是兩件事。換碼靠的是「舊代號到新代號」對照表，只處理得了
     * 「整組都還是舊代號」的情形；一旦某列被寫成別家銀行的新代號，它本身就是一個合法的新代號，
     * 換碼模式會判定「已經是新代號」而不動它，救不回來。2026-09-17 正式環境正好出現這種列
     * （target/op_tickets#3174），只能拿權威來源逐列比對修正。
     *
     * 權威取代付設定而不是 FPay 清單：代付實際讀的就是代付設定的 available_banks
     * （灰度名單 payout_bank_lookup_providers 為空時），cash_flow_banks 是要跟它保持一致的那一份。
     * 也因此本模式不必連 FPay，離線可跑。
     *
     * @param  list<array{acf: AgentCashFlow, agent_group_id: int}>  $targets
     * @param  list<string>  $errors
     */
    private function runAlign(array $targets, int $currencyId, array $errors): int
    {
        $authority = $this->resolvePayoutConfigCodes($targets, $errors);

        if ($authority === null || $errors !== []) {
            return $this->abort($errors);
        }

        $plan = $this->buildAlignPlan($authority, $currencyId);

        $this->reportAlignPlan($plan);

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf('🔍 只出報表，未寫入。待校正 %d 列。加 --execute 以實際寫入。', count($plan['changes'])));

            return self::SUCCESS;
        }

        if ($plan['changes'] === []) {
            $this->newLine();
            $this->info('✅ cash_flow_banks 與代付設定已經一致，無需校正（冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認把 %d 列的三方代號改回代付設定的值？', count($plan['changes'])), false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $changed = DB::transaction(fn (): int => $this->alignCashFlowBanks($authority, $currencyId));

        Log::info('FPay 印尼盾 cash_flow_banks 以代付設定校正', [
            'cash_flow_id' => self::CASH_FLOW_ID,
            'changed' => $changed,
            'ticket' => 'target/op_tickets#3174',
        ]);

        $this->newLine();
        $this->info(sprintf('✅ 完成：%d 列的三方代號已改回代付設定的值。', $changed));

        return self::SUCCESS;
    }

    /**
     * 取代付設定的「我方銀行代號 => 三方代號」對映；所有目標設定必須給出同一份。
     *
     * 不一致就中止：這幾條設定共用同一個 FPay 商戶帳號，對映理當相同，
     * 不同代表其中一條已經被動過，此時沒有可信的權威可用，寧可轉人工也不要拿錯的去校正。
     *
     * @param  list<array{acf: AgentCashFlow, agent_group_id: int}>  $targets
     * @param  list<string>  $errors
     * @return array<string, string>|null
     */
    private function resolvePayoutConfigCodes(array $targets, array &$errors): ?array
    {
        $authority = null;
        $authorityAcfId = 0;

        foreach ($targets as $target) {
            $agentCashFlow = $target['acf'];
            $acfId = (int) $agentCashFlow->id;
            $entries = $agentCashFlow->info['available_banks'] ?? null;

            if (!is_array($entries) || $entries === []) {
                $errors[] = sprintf('ACF %d：info.available_banks 缺失或格式錯誤', $acfId);

                return null;
            }

            $map = [];

            foreach ($entries as $entry) {
                $target = is_array($entry) ? (string) ($entry['BankCode_ELG'] ?? '') : '';
                $third = is_array($entry) ? (string) ($entry['BankCode_Third'] ?? '') : '';

                if ($target === '' || $third === '') {
                    $errors[] = sprintf('ACF %d：available_banks 有條目缺少銀行代號', $acfId);

                    return null;
                }

                $map[$target] = $third;
            }

            if ($authority === null) {
                $authority = $map;
                $authorityAcfId = $acfId;

                continue;
            }

            if ($authority !== $map) {
                $errors[] = sprintf(
                    'ACF %d 與 ACF %d 的銀行代號對映不一致，沒有可信的權威來源，轉人工確認',
                    $authorityAcfId,
                    $acfId,
                );

                return null;
            }
        }

        if ($authority === null) {
            $errors[] = '沒有可用的代付設定，無法校正';
        }

        return $authority;
    }

    /**
     * 比對啟用中的列與權威對映，分出「要改的」與「代付設定裡查無對應條目的」。
     *
     * @param  array<string, string>  $authority
     * @return array{rows: int, changes: list<array{id: int, bank_code: string, current: string, expected: string}>, orphans: list<array{id: int, bank_code: string, current: string}>}
     */
    private function buildAlignPlan(array $authority, int $currencyId): array
    {
        $rows = CashFlowBank::query()
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->orderBy('id')
            ->get();

        $changes = [];
        $orphans = [];

        foreach ($rows as $row) {
            $bankCode = (string) $row->bank_code;
            $current = (string) $row->provider_bank_code;
            $expected = $authority[$bankCode] ?? null;

            if ($expected === null) {
                $orphans[] = ['id' => (int) $row->id, 'bank_code' => $bankCode, 'current' => $current];

                continue;
            }

            if ($expected !== $current) {
                $changes[] = [
                    'id' => (int) $row->id,
                    'bank_code' => $bankCode,
                    'current' => $current,
                    'expected' => $expected,
                ];
            }
        }

        return ['rows' => $rows->count(), 'changes' => $changes, 'orphans' => $orphans];
    }

    /**
     * @param  array{rows: int, changes: list<array{id: int, bank_code: string, current: string, expected: string}>, orphans: list<array{id: int, bank_code: string, current: string}>}  $plan
     */
    private function reportAlignPlan(array $plan): void
    {
        $this->info(sprintf(
            '校正模式：以代付設定的 available_banks 為準，比對 cash_flow_banks 啟用中的 %d 列。',
            $plan['rows'],
        ));
        $this->newLine();

        if ($plan['changes'] === []) {
            $this->line('　沒有對不上的列。');
        } else {
            $this->warn(sprintf('⚠️ 下列 %d 列的三方代號與代付設定不一致（以代付設定為準）：', count($plan['changes'])));
            $this->table(
                ['cash_flow_banks id', '我方銀行代號', '現在是', '應改為'],
                array_map(static fn (array $change): array => [
                    $change['id'],
                    $change['bank_code'],
                    $change['current'],
                    $change['expected'],
                ], $plan['changes']),
            );
        }

        if ($plan['orphans'] !== []) {
            $this->newLine();
            $this->line(sprintf(
                '　另有 %d 列的我方銀行代號在代付設定裡查無對應條目，無從判斷對錯，本命令不動：',
                count($plan['orphans']),
            ));
            $this->table(
                ['cash_flow_banks id', '我方銀行代號', '現在是'],
                array_map(static fn (array $orphan): array => [
                    $orphan['id'],
                    $orphan['bank_code'],
                    $orphan['current'],
                ], $plan['orphans']),
            );
        }
    }

    /**
     * 鎖定後重新比對再寫入；順手補上缺的人工覆寫標記，免得校正完又被同步命令沖掉。
     *
     * @param  array<string, string>  $authority
     */
    private function alignCashFlowBanks(array $authority, int $currencyId): int
    {
        $rows = CashFlowBank::query()
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->lockForUpdate()
            ->get();

        $changed = 0;

        foreach ($rows as $row) {
            $expected = $authority[(string) $row->bank_code] ?? null;

            if ($expected === null || $expected === (string) $row->provider_bank_code) {
                continue;
            }

            $row->provider_bank_code = $expected;

            if ($row->manual_override_at === null) {
                $row->manual_override_at = now();
                $row->manual_override_note = self::OVERRIDE_NOTE;
            }

            $row->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * @param  list<string>  $errors
     */
    private function abort(array $errors): int
    {
        $this->error(sprintf('現況與預期不符（%d 項），整體攔截、未寫入任何資料：', count($errors)));

        foreach ($errors as $error) {
            $this->line('  - ' . $error);
        }

        return self::FAILURE;
    }
}
