<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\MemberAllWalletData;
use App\Data\MemberWalletData;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Jobs\Wallet\SyncMemberBalanceToWalletJob;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\VendorInfo;
use App\Vendors\Models\AbstractAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 會員錢包餘額的載體解析與上鎖（本 Service 只負責「取到哪一列、有沒有鎖」，實際寫入由呼叫端執行）。
 *
 * 🔴 改餘額前必須先在同一個外層 DB 交易內取得帶鎖的列，取鎖與寫入必須同交易——
 * **禁止未上鎖就 update 餘額、禁止另建第三張餘額載體**。唯讀顯示用途走
 * {@see self::queryMemberWallet()}（不建列、可能回 null）或 {@see self::getMemberWallets()}；
 * 任何會導致寫入的路徑都必須改用帶鎖版本。
 *
 * 餘額到底放哪由本 Service 決定，**呼叫端不自行判斷**：主餘額在 members.balance；
 * 會員 `is_event_wallet`（Member accessor，活動或新手挑戰進行中）為真時主錢包改為
 * member_wallets 的 CODE_EVENT_WALLET；禮碼恆在 member_wallets。
 * `agents.is_multi_wallet=1` 已隨 type=2 遊戲錢包廢除，命中即 LogicException 顯性中止
 * （見 {@see self::getMemberLockedWallets()}），不是還活著的第三條分支。
 * 新錢包種類加一個 WalletEnum case 即可。
 *
 * ⚠️ 鎖順序見 {@see self::getMemberLockedWallets()} 的 docblock 與 .claude/rules/php.md
 * 的「一般讀看的是快照、不是現在的資料」一條。餘額異動的憑證列屬
 * {@see TransactionLogCreateService}，禮碼的加減與流水屬 {@see RollingChipService}。
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class WalletService
{
    /** 幣別 sentinel：非貨幣錢包與解析不到幣別時寫 0（設計決策 17：不用 NULL——NULL 進不了唯一鍵） */
    private const int CURRENCY_NONE = 0;

    public function getSingleWalletBalanceByMember(VendorInfo $vendorInfo, Member $member): string
    {
        $memberWallets = $this->getMemberWallets($member, $vendorInfo->rolling_chip_status);

        $balance = 0.00;
        $balance = Common::numAdd($balance, $memberWallets->wallet->balance);
        $balance = Common::numAdd($balance, $memberWallets->rollingChipWallet?->balance ?? 0.00);
        $balance = Common::numMul($balance, $vendorInfo->rate, 4);

        return $balance;
    }

    /**
     * 取得**上鎖**會員錢包: 主錢包(遊戲錢包) + 禮碼
     *
     * 第一個 SQL 必須是鎖 `members` 主鍵列，之後才讀 agent／活動狀態。RR 下快照定在本交易
     * 第一次一般讀那一刻，若先 lazy load `$member->agent`（會查表）再排隊等鎖，拿到鎖後所有
     * 一般讀仍是等鎖前的舊照片——看不到對手剛 commit 的注單與現金紀錄 `point`
     * （target/op_tickets#3075、target/tickets#4294）。鎖定讀不建快照，快照改由拿到鎖之後的第一次
     * 一般讀建立，讀到的就是現值。呼叫端進交易後也應以本方法為第一個 DB 動作，
     * 否則快照仍會在更早的一般讀定住（見 ClotPlayController 的 callAction：會員在交易外解出）。
     *
     * @param  VendorInfo|\App\Models\EventVendorInfo|bool|int|null  $isRollingChip  是否取得禮碼錢包, 如果提供 VendorInfo 則會使用 VendorInfo 的 rolling_chip_status 來決定是否取得禮碼錢包
     * @param  AbstractAccount|null  $account  遊戲帳號
     */
    public function getMemberLockedWallets(Member $member, VendorInfo|\App\Models\EventVendorInfo|bool|int|null $isRollingChip = null, ?AbstractAccount $account = null): MemberWalletData
    {
        $lockedMember = Member::lockForUpdate()->find($member->id);

        $agent = $lockedMember->agent;
        $isMultipleWallet = $agent->is_multi_wallet;

        // 是否有參加活動
        if ($lockedMember->is_event_wallet) {
            $mainWallet = $this->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
        } elseif ($isMultipleWallet) {
            // type=2 已於多幣別錢包第一期廢除（決議⑬）。2026-09-03 六站實查：代理實際引用的
            // 88 個設定群組多錢包開關皆讀到 0（6008 自訂列 149 筆全 0、預設值 0），此為死路徑；
            // 若被重新開啟，顯性中止而不是拿 null 當主錢包。
            throw new \LogicException(
                "多錢包模式（is_multi_wallet）已隨 type=2 遊戲錢包廢除，不得重新開啟，member_id={$member->id}"
            );
        } else {
            $mainWallet = $lockedMember;
        }

        if (isset($account) && $account->status == 0) {
            Log::info($account->username . ' 遊戲帳號狀態為0, 不更新錢包');
            $mainWallet->lockBalance = true; // 只要遊戲帳號狀態為0, 就不更新錢包
        }

        $rollingChipWallet = null;
        if (!is_null($isRollingChip) && $lockedMember->is_event_wallet === false) {
            if ($isRollingChip instanceof VendorInfo) {
                $isRollingChip = $isRollingChip->rolling_chip_status;
            }

            if ($isRollingChip) {
                $rollingChipWallet = $this->getLockedMemberWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);
            }
        }

        return new MemberWalletData($mainWallet, $rollingChipWallet, $isMultipleWallet);
    }

    /**
     * 取得或建立會員錢包並上鎖（`lockForUpdate`）
     *
     * 與 getOrCreateWallet() 同為錢包建立的唯一入口，差別只在有沒有上鎖。
     * 要改餘額一律走這支——不上鎖直接改會在併發下互蓋。
     *
     * ⚠️ 前置條件：呼叫端必須已在交易內。方法內的 DB::transaction 在無外層交易時
     * 會於返回當下 commit、行鎖同步釋放，拿回去的是已解鎖的 model；
     * $attempts = 3 的死鎖重試也只在無外層交易時生效——巢狀死鎖直接拋
     * DeadlockException 不重試（vendor ManagesTransactions::handleTransactionException）。
     * 2026-09-01 圓桌逐一追過全部呼叫端，皆在外層交易內。
     *
     * 先 SELECT ... FOR UPDATE 直取排他鎖（錢包已存在是生產常態，一條語句、併發乾淨排隊），
     * 查無才走 createOrFirst 防併發建立。不可改回「直接 createOrFirst」：那會讓每次呼叫
     * 都先送一條註定失敗的 INSERT（事務內實測 5 條語句對 1 條），撞唯一鍵時先取共享鎖、
     * 接著 FOR UPDATE 要升級排他鎖，兩個併發交易同走此路就是死鎖。
     *
     * 已廢除類型（isDeprecated()）只查不建：存量列照樣帶鎖讀到，查無回 null——
     * 這是全服務唯一的「帶鎖唯讀」路徑，供過渡期讀 type=2 存量（如遊戲錢包轉回主錢包）。
     *
     * @param  int|null  $currencyId  幣別，僅在建立新錢包時寫入——查詢仍走「會員＋類型」
     *                                兩元組，語意同 getOrCreateWallet()（詳見其 docblock）。
     */
    public function getLockedWallet(int $memberId, WalletEnum $walletType, ?int $currencyId = null): ?MemberWallet
    {
        return DB::transaction(function () use ($memberId, $walletType, $currencyId) {
            $wallet = MemberWallet::query()
                ->where('member_id', $memberId)
                ->where('wallet_type_id', $walletType->value)
                ->lockForUpdate()
                ->first();

            if ($wallet !== null || $walletType->isDeprecated()) {
                return $wallet;
            }

            return MemberWallet::lockForUpdate()
                ->createOrFirst(
                    [
                        'member_id' => $memberId,
                        'wallet_type_id' => $walletType->value,
                    ],
                    [
                        'balance' => '0',
                        'first_deposit' => null,
                        'status' => 1,
                        'admin' => 0,
                        'currency_id' => $this->resolveCurrencyId($memberId, $walletType, $currencyId),
                    ]
                );
        }, 3);
    }

    /**
     * getLockedWallet() 的舊名別名。
     *
     * 保留是為了不動既有呼叫端；新代碼一律用 getLockedWallet()。
     *
     * @deprecated 改用 getLockedWallet()
     */
    public function getLockedMemberWallet(int $memberId, WalletEnum $walletType): ?MemberWallet
    {
        return $this->getLockedWallet($memberId, $walletType);
    }

    /**
     * 取得會員錢包: 主錢包(遊戲錢包) + 禮碼
     *
     * @param  VendorInfo|bool|int|null  $isRollingChip  是否取得禮碼錢包, 如果提供 VendorInfo 則會使用 VendorInfo 的 rolling_chip_status 來決定是否取得禮碼錢包
     * @param  bool  $isEventV2  是否取得活動錢包
     */
    public function getMemberWallets(Member $member, VendorInfo|bool|int|null $isRollingChip = null, bool $isEventV2 = true): MemberWalletData
    {
        $agent = $member->agent;
        $isMultipleWallet = $agent->is_multi_wallet;

        // 是否有參加活動
        if ($isEventV2 && $member->is_event_wallet) {
            $mainWallet = $this->getOrCreateWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
        } elseif ($isMultipleWallet) {
            // type=2 已於多幣別錢包第一期廢除（決議⑬）；死路徑守衛，理由同 getMemberLockedWallets()
            throw new \LogicException(
                "多錢包模式（is_multi_wallet）已隨 type=2 遊戲錢包廢除，不得重新開啟，member_id={$member->id}"
            );
        } else {
            $mainWallet = Member::find($member->id);
        }

        $rollingChipWallet = null;
        if (!is_null($isRollingChip) && $member->is_event_wallet === false) {
            if ($isRollingChip instanceof VendorInfo) {
                $isRollingChip = $isRollingChip->rolling_chip_status;
            }

            if ($isRollingChip) {
                $rollingChipWallet = $this->getOrCreateWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);
            }
        }

        return new MemberWalletData($mainWallet, $rollingChipWallet, $isMultipleWallet);
    }

    public function getMemberAllWallets(Member $member): MemberAllWalletData
    {
        $agent = $member->agent;
        $isMultipleWallet = $agent->is_multi_wallet;

        $mainWallet = Member::find($member->id);
        // type=2 已廢除（決議⑬）：只讀存量、不再建立。此處原本無條件建立，
        // 是存量清除後會再長回來的主要來源（實測近 30 天 TPower 新增 3,720 筆）。
        $gameWallet = $this->queryMemberWallet($member->id, WalletEnum::CODE_GAME);
        $rollingChipWallet = $this->getOrCreateWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);

        // 是否有參加活動
        if ($member->is_event_wallet) {
            $evenV2Wallet = $this->getOrCreateWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
            $walletBalance = $evenV2Wallet->balance;
        } elseif ($isMultipleWallet) {
            $walletBalance = $gameWallet?->balance ?? '0';
        } else {
            $walletBalance = $mainWallet->balance;
        }

        return new MemberAllWalletData($mainWallet, $gameWallet, $rollingChipWallet, $walletBalance, $isMultipleWallet);
    }

    public function getMemberEventWallet(Member $member): MemberWallet
    {
        // 1003 目前尚未列入 deprecatedTypes()（2026-09-03 已裁決廢除、前置未完成）、執行期恆非 null；
        // ?? throw 把這條不變式從假設變強制——1003 加進 deprecatedTypes() 後，這裡顯性中止而不是 TypeError。
        // ⚠️ RefreshRankingService 不經此入口、直呼 getOrCreateWallet()，沒有這道守衛（決議⑮前置③）
        return $this->getOrCreateWallet($member->id, WalletEnum::CODE_EVENT_RANKING)
            ?? throw new \LogicException("排行榜錢包類型已被列為廢除，member_id={$member->id}");
    }

    public function getLockedMemberEventWallet(Member $member): MemberWallet
    {
        return $this->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_RANKING)
            ?? throw new \LogicException("排行榜錢包類型已被列為廢除，member_id={$member->id}");
    }

    public function queryMemberWallet(int $memberId, WalletEnum $walletType): ?MemberWallet
    {
        return MemberWallet::query()
            ->where('member_id', $memberId)
            ->where('wallet_type_id', $walletType->value)
            ->first();
    }

    /**
     * 取得或建立會員錢包（不上鎖）
     *
     * 錢包建立的唯一入口。新代碼禁止直接呼叫
     * MemberWallet::create()／firstOrCreate()／insertOrIgnore()——那會繞過本方法，
     * 在唯一鍵改為三元組後靜默寫進錯誤的列。2026-09-01 已把 app/ 底下的直寫全數
     * 收斂歸零，由 tests/Unit/Architecture/WalletWriteEntrypointTest.php 持續守住。
     *
     * ⚠️ 範圍口徑：上述「歸零」與守衛都只涵蓋 APIs 的 app/。四個舊 repo
     * （API_External／API_Frontend／RD-Studio-01／API_Agent）加共用套件 target/support
     * 另有 222 個以上 member_wallets 寫入點（2026-09-03 重盤，單分支下界；
     * database/data/bet_records/writers.php 的 126 筆是舊口徑，待第五期重登記）。
     * 唯一鍵改三元組排在第五期「報表與錢包切換」（target/tickets#4250），動鍵前必須以
     * docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-impact-inventory.md
     * 第七節的清單為準，不能只看本檔的口徑。
     *
     * 不重複的保證來自資料庫：member_wallets 有 UNIQUE(member_id, wallet_type_id)
     * （六站實測皆有此索引）。實作是手動展開的 firstOrCreate（語意逐字等價）：
     * 先一條 SELECT 取既有列（錢包已存在是生產常態，此時零額外查詢），查無才走
     * createOrFirst 的「INSERT、撞唯一鍵改讀」防併發建立——拆開是為了在「查」與
     * 「建」之間插入已廢除型別的早退與幣別的延遲解析（都只該在建立分支發生）。
     * 沒有這個唯一索引時會插出重複列，改動索引前要留意。
     *
     * ⚠️ REPEATABLE READ 交易內：查詢吃快照，別的連線剛提交的錢包可能讀不到而
     * 走進建立路徑，撞鍵後的 fallback 亦是快照讀，極端下拋
     * UniqueConstraintViolationException（與舊寫法 firstOrCreate 行為相同，非回歸）。
     * 交易內要保證拿到列請改用 getLockedWallet()——FOR UPDATE 是當前讀，不吃快照。
     *
     * ⚠️ 幣別（第一期口徑）：**只在建立新錢包時寫入，不參與查詢**。查詢仍走
     * 「會員＋類型」兩元組——六站既有存量列的 currency_id 在欄位上線時全是 0，
     * 查詢帶幣別會 miss 全部存量列、撞兩元組唯一鍵直接拋錯（2026-09-01 以六站
     * 生產組織幣別實測推演，六站組織幣別皆非 0）。查詢帶幣別屬第五期
     * 「報表與錢包切換」（target/tickets#4250）的統一改造，須待存量列回填之後。
     *
     * 新建時明確寫 status = 1（使用中）。資料表預設是 0（未使用），但六站生產實測
     * 99.8% 的錢包都是 1，且 APIs 側沒有任何查詢以 status 過濾錢包。
     *
     * 需要在交易內鎖住錢包再改餘額時，改用 getLockedWallet()。
     *
     * @param  int|null  $currencyId  幣別；null 表示取該會員的組織幣別（經
     *                                Member::$currency_id accessor）。非貨幣類型
     *                                （WalletEnum::isCurrencyAware() === false）一律強制 0。
     *                                解析只在建立分支發生——錢包已存在（生產常態）時
     *                                零額外查詢；呼叫端已有 Member 實例時優先傳
     *                                $member->currency_id，避免建立時多查一次。
     * @param  string  $initialBalance  **僅在這次呼叫真的建立新錢包時**才生效的起始餘額。
     *                                  錢包已存在時完全不影響既有餘額。傳入前請先以
     *                                  Common::num* 正規化。預設 '0'，只有「建立時就要
     *                                  帶初始點數」的活動類需要指定。
     */
    public function getOrCreateWallet(int $memberId, WalletEnum $walletType, ?int $currencyId = null, string $initialBalance = '0'): ?MemberWallet
    {
        $wallet = $this->queryMemberWallet($memberId, $walletType);

        // 已廢除類型只查不建（決議⑬）：存量列還在時照樣讀得到，但不再長出新的
        if ($wallet !== null || $walletType->isDeprecated()) {
            return $wallet;
        }

        return MemberWallet::query()
            ->createOrFirst(
                [
                    'member_id' => $memberId,
                    'wallet_type_id' => $walletType->value,
                ],
                [
                    'balance' => $initialBalance,
                    'first_deposit' => null,
                    'status' => 1,
                    'admin' => 0,
                    'currency_id' => $this->resolveCurrencyId($memberId, $walletType, $currencyId),
                ]
            );
    }

    /**
     * 雙寫開關是否開啟
     */
    public function isDualWriteEnabled(): bool
    {
        return (bool) config('custom.multi_currency_wallet.dual_write', false);
    }

    /**
     * 派出影子同步 Job（開關關閉時什麼都不做）
     *
     * 掛點在 MemberObserver::updated（balance 變動時），單點涵蓋 APIs 側全部
     * Eloquent 寫入路徑（實例 save／update／increment 皆觸發 model events；
     * 全量掃過 app/ 無 query builder 對 members.balance 的 mass update）。
     * 蓋不到的：stored procedure 直寫與四個舊 repo 的寫入——由每日校驗排程
     * 比對兜底，差異靠覆寫語意的重同步收斂。
     *
     * 走 dispatchSafely()（DB::afterCommit ＋ rescue 包 Bus::dispatch）：
     * 交易回滾時不派（不會同步到未提交的餘額）；queue 服務異常時只記
     * warning、絕不炸穿主交易——影子同步不得與金流交易共生死。
     * ⚠️ 不可改回 static::dispatch()->afterCommit()：那條路徑會在主交易內
     * 同步取 unique cache lock、且 commit 後的 push 失敗會從 DB::commit 拋出
     * （SafelyDispatchable docblock 與圓桌審查均有記載）。
     * 漏派（rescue 吞掉的那次）由每日校驗抓到，影子期可接受。
     */
    public function dispatchShadowSync(int $memberId): void
    {
        if (!$this->isDualWriteEnabled()) {
            return;
        }

        SyncMemberBalanceToWalletJob::dispatchSafely($memberId);
    }

    /**
     * 解析錢包幣別
     *
     * 非貨幣錢包固定 0（設計決策 17：sentinel 用 0 不用 NULL）；有指定用指定值
     * （設計決策 12：入金依訂單記錄的幣別，不看會員當下狀態）；未指定時取
     * 會員的組織幣別，查無會員或組織未設幣別時回 0。
     */
    private function resolveCurrencyId(int $memberId, WalletEnum $walletType, ?int $currencyId): int
    {
        if (!$walletType->isCurrencyAware()) {
            return self::CURRENCY_NONE;
        }

        if (!is_null($currencyId)) {
            return $currencyId;
        }

        return (int) (Member::query()->find($memberId)?->currency_id ?? self::CURRENCY_NONE);
    }
}
