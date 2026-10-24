<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\Agent\Concerns\ChecksMemberScope;
use App\Services\VendorService;
use App\Vendors\Models\AbstractAccount;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 會員詳情抽屜——遊戲商資料（4274，自 API_Agent vendor/list、vendor/username 移植）。
 *
 * 帳號顯示依序：
 *  1. **舊系統後台規則**（target/op_tickets#3440，見 legacyDisplayUsername()）：下列遊戲商與
 *     API_Agent「會員遊戲帳號總覽」（VendorController::member_all_vendor_suumary）同一套顯示，
 *     前綴取自 vendor_infos.info 的設定值。
 *  2. AbstractAccount::$vendorUsernameColumn＝遊戲商側真實帳號欄位
 *     （SCR=player_id、WBET=vendor_username…，parity 測試釘住），交給該 model 的
 *     displayVendorUsername() 決定顯示字串。
 *  3. 未宣告者＝vendor_infos.account_prefix ＋ 我方 username（建號規則，見 CreateVendorAccount）。
 *
 * 🔴 第 1 條是刻意與「遊戲商真實帳號」不同的：op_tickets#3440 回報會員詳情與會員遊戲帳號
 * 顯示不一致（gff 主站實測 9 家：Joker、GreatWall99、ACE333、WorldMatch、AE Sexy、Sky1388、
 * Mario Club、V8 Poker、100Plus），經 PM 確認（2026-10-05）先以舊系統「會員遊戲帳號」的顯示為準、
 * 會員詳情對齊它，之後要統一顯示哪一種帳號再兩邊一起改。實測（以注單上遊戲商回傳的帳號為準）：舊系統的前綴
 * 多半是遊戲商「後台」看到的帳號（Joker 的 APP_ID.、RM 的 BACKEND_PREFIX_），送 API 與注單對帳
 * 用的仍是 $vendorUsernameColumn——**只改顯示，不得拿第 1 條的結果去呼叫三方或對帳**。
 * 100Plus 例外：舊系統顯示的我方 username 才是遊戲商帳號（plus100 注單以 username 對），
 * 原本第 3 條多加了 account_prefix 是錯的。
 */
class MemberDetailVendorService
{
    use ChecksMemberScope;

    /**
     * 舊系統顯示「前綴＋我方 username」的遊戲商：前綴取 vendor_infos.info 的哪個設定、後面接什麼。
     * 照抄 API_Agent VendorController 的 $prefix_required_vendors 與 getAccountPrefixByVendorInfo()。
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const LEGACY_PREFIX_RULES = [
        VendorIdEnum::JOKER->value => ['APP_ID', '.'],
        VendorIdEnum::HAOLONG->value => ['HAOLONG_USER', ''],
        VendorIdEnum::GreatWall->value => ['API_AGENT', ''],
        VendorIdEnum::AES->value => ['BACKEND_PREFIX', ''],
        VendorIdEnum::SKY1388->value => ['ID', ''],
        VendorIdEnum::RED_MARIO->value => ['BACKEND_PREFIX', '_'],
        VendorIdEnum::NPT->value => ['BACKEND_PREFIX', ''],
        VendorIdEnum::OBET33->value => ['AGENT_ID', ''],
        VendorIdEnum::SABA->value => ['OPERATOR_ID', '_'],
        VendorIdEnum::KY->value => ['AGENT', '_'],
        VendorIdEnum::LFC888->value => ['ACCOUNT_CLASS', '_'],
        VendorIdEnum::ASTAR->value => ['GAMEHALL', ''],
    ];

    /**
     * 舊系統只顯示我方 username、但 apis 的 $vendorUsernameColumn 指向別的欄位（或未宣告）
     * 的遊戲商：ACE333（player_id）、WorldMatch（user_id）、V8 Poker（username_modified）、
     * 100Plus（未宣告，原本走 account_prefix）。
     *
     * @var array<int, int>
     */
    private const LEGACY_PLAIN_USERNAME_VENDORS = [
        VendorIdEnum::ACE333->value,
        VendorIdEnum::WM->value,
        VendorIdEnum::V8->value,
        VendorIdEnum::PLUS->value,
    ];

    /**
     * 舊系統組完帳號後再轉大小寫的遊戲商（API_Agent member_all_vendor_suumary 的特例）。
     *
     * @var array<int, 'lower'|'upper'>
     */
    private const LEGACY_CASE_RULES = [
        VendorIdEnum::AES->value => 'lower',
        VendorIdEnum::OBET33->value => 'upper',
    ];

    public function __construct(
        private readonly VendorService $vendorService,
    ) {}

    /**
     * 遊戲商下拉（啟用中）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function vendorList(): array
    {
        return Vendor::query()
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Vendor $vendor) => [
                'id' => (int) $vendor->id,
                'name' => (string) $vendor->name,
            ])
            ->all();
    }

    /**
     * 會員在指定遊戲商底下「所有」啟用中的帳號（op_tickets#3236 重新設計）。
     *
     * 會員在同一遊戲商可能同時有多筆有效帳號（正式線、活動V2線各一筆），
     * 兩者都是有效帳號、不是「猜一筆對的」，所以全部回傳、不用 first()；
     * 依 vendor_infos.type 排序（0測試/1正式/2活動v2）供顯示順序穩定，
     * 由前端依各筆的 vendor_info_type 分別標示（活動線附註「(活動用)」）。
     * 查無帳號回空陣列（前端顯示「帳號不存在」）。
     *
     * 主路徑以 vendors.tname 直查 {tname}_accounts（與舊 API_Agent 行為一致，
     * 不依賴 driver——vendors 表存在但 config/vendor.php 無 driver 的歷史商戶
     * 也能查）；AbstractAccount 契約僅作「顯示欄位」加值層，解析不到就退回
     * 「前綴＋我方 username」。
     *
     * 🔴 {tname}_accounts 常是多個 vendors 列共用同一張表（例：evo_accounts
     * 被 40+ 個「Titan」子廠牌共用、pgssw_accounts 被 vendors 50/91/145 共用），
     * 一定要用 vendor_infos.vendor_id 篩選，否則會連其他遊戲商的帳號一起撈出來
     * （實測 member 47559 的 evo_accounts 就橫跨 40+ 個不同 vendor_id）。
     * 少數歷史商戶的 {tname}_accounts 沒有 vendor_info_id／status 欄位，用
     * Schema::hasColumn 動態偵測後略過對應篩選——此時無法依 vendor_id 篩，
     * 只能回傳 member_id 命中的列（歷史包袱，維持原行為，不強行補資料）。
     *
     * @return array<int, array{username: string, vendor_info_type: int|null}>
     */
    public function vendorUsername(Agent $agent, int $memberId, int $vendorId): array
    {
        $member = $this->findMemberInScope($agent, $memberId);

        $vendor = Vendor::query()->find($vendorId);
        if ($vendor === null || blank($vendor->tname)) {
            throw new ApiException('vendor not found');
        }

        $table = $vendor->tname . '_accounts';
        $hasVendorInfo = Schema::hasColumn($table, 'vendor_info_id');

        $accountModel = $this->accountModel($table);

        // join 之後兩表同名欄位（id、status、created_at…）會被 vendor_infos 蓋掉，
        // 故明確只選帳號表的欄位，並一律以 qualifyColumn() 限定表名
        $query = $accountModel->newQuery()
            ->select($accountModel->qualifyColumn('*'))
            ->where($accountModel->qualifyColumn('member_id'), $member->id);

        if (Schema::hasColumn($table, 'status')) {
            $query->where($accountModel->qualifyColumn('status'), 1);
        }

        if ($hasVendorInfo) {
            $query->join('vendor_infos', 'vendor_infos.id', '=', $accountModel->qualifyColumn('vendor_info_id'))
                ->where('vendor_infos.vendor_id', $vendorId)
                ->where('vendor_infos.status', 1)
                ->orderBy('vendor_infos.type')
                ->addSelect([
                    'vendor_infos.type as vendor_info_type',
                    'vendor_infos.account_prefix as vendor_account_prefix',
                    // 舊系統規則的前綴在 info 裡（可能加密，金鑰取自 created_at），隨 join 一併選出，
                    // 不逐列查 vendor_infos
                    'vendor_infos.info as vendor_info_info',
                    'vendor_infos.created_at as vendor_info_created_at',
                ]);
        }

        $driverAccountModel = $this->driverAccountModel($vendorId);

        return $query->get()
            ->map(fn (AbstractAccount $account) => [
                'username' => $this->legacyDisplayUsername($vendorId, $account)
                    ?? $this->displayUsername($driverAccountModel, $account),
                'vendor_info_type' => $hasVendorInfo ? (int) $account->vendor_info_type : null,
            ])
            ->all();
    }

    /**
     * {tname}_accounts 的 Model。
     *
     * 表名執行期才知道，且不是每個 vendors 列都有 driver（無 driver 的歷史商戶照樣
     * 出現在 vendorList() 下拉選單，走 getDriver() 會拋 VendorNotFoundException），
     * 所以不取 driver 的 getAccountModel()，改以 AbstractAccount 匿名子類 setTable()
     * 取得 Model。
     */
    private function accountModel(string $table): AbstractAccount
    {
        return (new class extends AbstractAccount {})->setTable($table);
    }

    /**
     * driver 宣告的具體 account model（帶該遊戲商的帳號欄位與顯示規則）；
     * 無 driver 配置的歷史商戶回 null。
     *
     * 🔴 顯示規則只在具體 model 上：例如 LbbAccount（i1betnow）覆寫
     * displayVendorUsername() 去掉 ACCESS_ID 前綴。查詢用的 accountModel() 是匿名
     * 子類，只繼承「回原值」的預設版，拿它來顯示會讓 i1betnow 靜默變回帶前綴。
     *
     * 結果只由 vendorId 決定，一定要在逐列組裝「前」解析一次：getDriver() 會實際
     * 建構 driver（帶 Vendor 查詢與 Context 寫入），放進迴圈等同每一列重做一次。
     */
    private function driverAccountModel(int $vendorId): ?AbstractAccount
    {
        try {
            $driver = $this->vendorService->getDriver($vendorId);

            return App::make($driver->getAccountModel());
        } catch (Throwable) {
            // 無 driver 配置的歷史商戶：照舊走前綴＋username
            return null;
        }
    }

    /**
     * 舊系統（API_Agent）「會員遊戲帳號總覽」的顯示規則，op_tickets#3440。
     *
     * 不在舊規則範圍內的遊戲商回 null，交給 displayUsername()。前綴設定讀不到時（info 解不開、
     * 沒有那個 key）也回 null 退回原規則——舊系統這時會印出「Error_Parsing_Fail」或整家不顯示。
     * 前綴設成空字串則照舊系統只顯示我方 username（仍套用大小寫規則），見 vendorInfoSetting()。
     */
    private function legacyDisplayUsername(int $vendorId, AbstractAccount $account): ?string
    {
        $username = (string) ($account->username ?? '');

        if (in_array($vendorId, self::LEGACY_PLAIN_USERNAME_VENDORS, true)) {
            return $username;
        }

        $rule = self::LEGACY_PREFIX_RULES[$vendorId] ?? null;
        if ($rule === null) {
            return null;
        }

        [$key, $separator] = $rule;
        $prefix = $this->vendorInfoSetting($account, $key);
        if ($prefix === null) {
            return null;
        }

        // 前綴設成空字串時不接分隔符號：舊系統會印出「.pro00120256」這種半截帳號，這裡只顯示我方 username
        $display = $prefix === '' ? $username : $prefix . $separator . $username;

        return match (self::LEGACY_CASE_RULES[$vendorId] ?? null) {
            'lower' => strtolower($display),
            'upper' => strtoupper($display),
            default => $display,
        };
    }

    /**
     * 帳號列 join 進來的 vendor_infos.info 的某個設定值；info 解不開或沒有這個 key 回 null。
     *
     * 🔴 key 存在但值是空字串時回 ''、不視同沒有：舊系統照樣拿空字串當前綴組帳號並套用大小寫規則
     * （dev 的 AE Sexy vendor_info 527 就是 BACKEND_PREFIX=''，總覽顯示小寫的整串我方 username），
     * 當成沒有會退回原規則、少了轉小寫，兩頁又不一致。
     *
     * 解密沿用 VendorInfo 的 info accessor（明文 JSON 或以 created_at 為金鑰的 AES）：以 join
     * 選出的原始值建一個未存檔的 VendorInfo，不另查 DB。
     */
    private function vendorInfoSetting(AbstractAccount $account, string $key): ?string
    {
        $raw = $account->vendor_info_info ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $info = (new VendorInfo)->setRawAttributes([
            'info' => $raw,
            'created_at' => $account->getRawOriginal('vendor_info_created_at'),
        ])->info;

        if (!is_array($info) || !array_key_exists($key, $info)) {
            return null;
        }

        return is_scalar($info[$key]) ? (string) $info[$key] : null;
    }

    /**
     * 遊戲商側帳號顯示：優先取 AbstractAccount 契約宣告的真實帳號欄位
     * （driver 可解析時），並交給該具體 model 的 displayVendorUsername() 決定顯示字串；
     * 否則以「帳號列自帶 vendor_info 的前綴＋我方 username」重組（與建號規則一致）。
     *
     * 前綴取自 vendorUsername() join vendor_infos 時一併選出的 vendor_account_prefix，
     * 不在此另外查 DB；沒有 vendor_info_id 欄位的歷史商戶不 join，前綴為空。
     * 不要改讀 vendorInfo 關聯：這裡沒預載它，一碰就觸發延遲載入（非正式環境在帳號
     * 2 筆以上時拋 LazyLoadingViolationException，正式環境記 log 後逐列多查一次）。
     */
    private function displayUsername(?AbstractAccount $driverAccountModel, AbstractAccount $account): string
    {
        $column = $driverAccountModel?->getVendorUsernameColumn();

        if ($column !== null && filled($account->{$column} ?? null)) {
            return $driverAccountModel->displayVendorUsername((string) $account->{$column});
        }

        return (string) ($account->vendor_account_prefix ?? '')
            . (string) ($account->username ?? '');
    }
}
