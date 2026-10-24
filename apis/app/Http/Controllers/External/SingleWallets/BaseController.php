<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Data\MemberWalletData;
use App\Enums\RollingChipLogTypeEnum;
use App\Exceptions\BetException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Controllers\External\SingleWallets\Concerns\ResolvesAccountAndVendorInfo;
use App\Http\Controllers\External\SingleWallets\Concerns\VerifiesIpWhitelist;
use App\Http\Controllers\External\SingleWallets\Concerns\VerifiesRequestSignature;
use App\Http\Controllers\External\SingleWallets\Contracts\RendersCallbackErrors;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldDecodeCallbackPayload;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldResolveVendorAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyIpWhitelist;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyRequestSignature;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\VendorInfo;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\AbstractBetRecord;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 遊戲商回調底座，分兩層：解析「這個回調是誰、屬於哪個商戶」的**回調共通層**，以及
 * {@see self::changeBalance()} 領頭的**單錢包扣帳層**。
 *
 * 🔴 **共通層不是單錢包專屬**：轉帳錢包的 `TransferWallets\NextSpinController`（與繼承它的 `SpadeGamingController`）
 * 也靠它驗證會員與商戶，只是完全不碰扣帳層。搬動或收窄共通層成員前要連 TransferWallets 一起改，否則會打斷那兩支。
 *
 * 新 SW Controller 繼承本類並宣告 $vendorId／$accountClass／$betRecordClass／$actionPrefix／
 * $vendorInfoOperatorField，以 setUpVendorAccount() 解析帳號與會員，
 * 再呼叫 {@see self::changeBalance()} 完成扣帳。
 *
 * ## 進 action 前的檢查：掛 marker interface，由 {@see self::callAction()} 統一跑
 *
 * 共通層依職責拆成三個 trait（`Concerns\VerifiesIpWhitelist`、`Concerns\ResolvesAccountAndVendorInfo`、
 * `Concerns\VerifiesRequestSignature`），要不要跑由子類實作 `Contracts\` 底下的 marker interface 決定：
 *
 * | marker | 做什麼 | 失敗拋 |
 * |---|---|---|
 * | ShouldVerifyIpWhitelist | 來源 IP 白名單 | IpNotAllowedException |
 * | ShouldDecodeCallbackPayload | 子類解開加密 payload（純 hook） | 子類自訂 |
 * | ShouldResolveVendorAccount（＋AllowsUnboundAccount） | 帳號→會員→商戶，並一律驗商戶歸屬；**不驗帳號與商戶 status**（停用後仍要能結算，啟用狀態由 verifyBettability() 在扣款類 action 驗） | VendorAccountException／MemberNotFoundException／VendorNotFoundException |
 * | ShouldVerifyRequestSignature | 子類提供驗簽結果 | SignatureVerificationException |
 * | RendersCallbackErrors | 接住以上全部與 action 內的 Throwable，由子類翻成該家回應 | 不拋，回 Response |
 *
 * 🔴 **沒掛 marker 的子類行為一字不變**，這是逐家遷移的前提。**掛上 marker 的同時必須刪掉子類 callAction
 * 內自己的同一項檢查**，否則 IP 白名單會重複查 vendors、帳號會重複解析。
 * 請求 log 刻意不在這份清單內：`RequestLogger` middleware 已記，遷移時把子類的 logRequest() 呼叫一併拿掉。
 *
 * ## 接新遊戲商要擋重送時：先看三方協定給什麼，不要重新發明第四種
 *
 * 四家目前各自實作、**沒有共用抽象層**。從這三種既有機制挑一種，
 * 要抽共用層前先確認新接的一家不是第四種形態：
 *
 * | 三方協定給什麼 | 判定機制 | 現有實作 |
 * |---|---|---|
 * | 有唯一交易序號 | 比對序號（**一律 strcasecmp 不分大小寫**——DB collation 是 ci 而 PHP 的 === 不是） | TTHSW 的 OriginalTransactionId、PlayTech 的 relatedTransactionCode |
 * | 沒序號，但注單狀態能表達「已處理」 | **鎖定讀**查狀態旗標 | SpadegamingSW 的 isBetAlreadyCanceled() |
 * | 兩者皆無 | 內容指紋 ＋ 時間窗 | RGSW 的 isDuplicateCorrection() |
 *
 * 🔴 **判定當下讀到的必須是最新已提交的資料**：{@see \App\Services\WalletService::getMemberLockedWallets()}
 * 之前的任何一般讀就已建立 REPEATABLE READ 快照，之後即使拿到會員鎖，一般讀看到的仍是舊值
 * ——**循序重送的測試測不到這件事，只有真正併發才會現形**。已實證兩種做法：交易內對判定查詢
 * 本身下 lockForUpdate()（SpadegamingSW、RGSW），或交易外先取同局 named lock、鎖後才開交易
 * （PlayTech 經 GameRoundLock，且**與補帳命令 bet-record-recovery:refund-unsettled 共用同一把，
 * 兩邊不共用會各退一次**，target/op_tickets#3089）。
 *
 * 🔴 **守衛必須排在餘額檢查之前**：否則第一次扣款已讓餘額變小，重送時根本問不到守衛就先被判
 * 餘額不足，一筆成功的扣帳會被當成失敗。
 *
 * ⚠️ 內容指紋**只比對「決定動到哪一局」的欄位加金額，禁止比對整包 payload**——三方哪天多帶
 * 一個會變動的欄位（trace id、時間戳）就永遠不相等，守衛靜默失效且不留訊號；失效方向必須是
 * 誤擋而不是放行。金額比對一律顯式帶 scale（Common::numCompare 預設 scale 是 10，
 * 而注單金額欄位是 decimal(18,4)）。
 *
 * ⚠️ 判成重送之後回什麼沒有共用規則，逐家看三方文件；**錯誤碼要選「行為已知」的，
 * 不是語意最貼切的**（SpadegamingSW 回 code 0，因其 api.md 明訂回 109 會讓 SG 持續重送）。
 * 三種機制都可能誤擋，所以判成重送時**必須**留 warning 並帶足「少付了誰、少付多少」，
 * 事故當下要能直接從 log 算出影響金額。RGSW 與 PlayTech 已達標；
 * SpadegamingSW（只帶 transferId／referenceId）與 TTHSW（只帶交易序號）**尚未**，
 * 動到那兩支時順手補上。
 *
 * @knowledge rules/single-wallet-callback-common-checks
 */
class BaseController extends Controller
{
    use ResolvesAccountAndVendorInfo;
    use VerifiesIpWhitelist;
    use VerifiesRequestSignature;

    protected int $vendorId;

    /**
     * 遊戲商 account, 若沒有值請在 controller 報錯
     */
    protected ?AbstractAccount $account = null;

    /**
     * 請確保 vendorInfo 欄位有值, 若沒有值請在 controller 報錯
     */
    protected ?VendorInfo $vendorInfo;

    /**
     * 請確保 member 欄位有值, 若沒有值請在 controller 報錯
     */
    protected ?Member $member;

    /**
     * 遊戲商 account class
     * override 這個欄位
     */
    protected string $accountClass;

    /**
     * 遊戲商 betRecord class
     * override 這個欄位
     *
     * @var class-string<AbstractBetRecord>
     */
    protected string $betRecordClass;

    /**
     * log prefix
     * override 這個欄位
     */
    protected string $actionPrefix;

    /**
     * vendor_info 的中的 info 中的商戶代碼欄位,  用來判斷是哪一個 vendor_info
     * override 這個欄位
     */
    protected string $vendorInfoOperatorField;

    /**
     * 回調前置檢查的樣板（Template Method）。每一步只在子類實作對應 marker interface 時執行，
     * 沒掛 marker 的子類走到這裡等於直接進 action，行為與沒有這支方法時完全相同。
     *
     * 順序：IP 白名單（不查 DB，最便宜）→ 解碼 payload（驗簽前要先解開）→ 解析帳號／會員／商戶
     * （密鑰在 vendor_infos.info，驗簽要先知道商戶）→ 驗簽。
     *
     * 失敗一律拋例外，不在這裡組回應：子類掛 {@see RendersCallbackErrors} 的，前置檢查與 action 內的任何
     * Throwable 都交給它的 renderCallbackError() 翻成該家的錯誤碼，子類因此不需要覆寫 callAction；
     * 沒掛的，例外原樣往外拋，子類仍可用 try { return parent::callAction(...) } catch 自己接。
     *
     * 刻意不加回傳型別：34 支子類覆寫時沒寫型別，父類一加 `: mixed` 會讓它們 fatal。
     *
     * 下面的 @throws 只列前置檢查會拋的；action 內拋出的例外同樣經上述路徑。trait 內「未實作介面卻被呼叫」
     * 的 LogicException 已由 instanceof 擋住，走不到。
     *
     * @throws \App\Exceptions\IpNotAllowedException ShouldVerifyIpWhitelist：來源 IP 不在白名單
     * @throws \App\Exceptions\VendorAccountException ShouldResolveVendorAccount：帳號空、查無帳號、未綁定會員
     * @throws \App\Exceptions\MemberNotFoundException ShouldResolveVendorAccount：account.member_id 指到的會員不存在
     * @throws \App\Exceptions\VendorNotFoundException ShouldResolveVendorAccount：取不到 vendor_info 或歸屬不符
     * @throws \App\Exceptions\SignatureVerificationException ShouldVerifyRequestSignature 驗簽失敗
     */
    public function callAction($method, $parameters)
    {
        try {
            $request = request();

            if ($this instanceof ShouldVerifyIpWhitelist) {
                $this->assertIpAllowed();
            }

            if ($this instanceof ShouldDecodeCallbackPayload) {
                $this->decodeCallbackPayload($request);
            }

            if ($this instanceof ShouldResolveVendorAccount) {
                $this->resolveAccountAndVendorInfo($request, (string) $method);
            }

            if ($this instanceof ShouldVerifyRequestSignature) {
                $this->assertSignatureValid($request);
            }

            return parent::callAction($method, $parameters);
        } catch (Throwable $e) {
            if ($this instanceof RendersCallbackErrors) {
                return $this->renderCallbackError($e);
            }

            throw $e;
        }
    }

    /**
     * 根據投注記錄變化調整會員餘額（禮碼 + 真金錢包）
     *
     * 此方法會自動處理禮碼與真金錢包的變動，並建立對應的交易紀錄。
     * 禮碼（rolling_chip）會自動計算，呼叫端不需手動設定。
     *
     * 餘額變動計算公式：
     * 真金錢包變動 = (派彩 - 真金投注) + bonus變化 + jackpot變化
     *
     * 字段說明：
     * - bet/win: 基礎投注與派彩金額
     * - bonus: 運營商負擔的獎金（如錦標賽獎金），直接增加用戶餘額
     * - jackpot: 彩池獎金，直接增加用戶餘額
     * - rolling_chip: 禮碼金額，會自動計算並扣除
     *
     * 使用情境說明：
     * ┌─────────────┬──────────────────┬─────────────────────────────┐
     * │   情境類型   │  beforeBetRecord │      afterBetRecord 設定      │
     * ├─────────────┼──────────────────┼─────────────────────────────┤
     * │ 新投注       │ null             │ bet=投注額, win=0, rolling=0 │
     * │ 派彩         │ 原投注記錄        │ 複製原記錄並修改 win          │
     * │ Bonus獎金    │ 原投注記錄        │ 複製原記錄並增加 bonus        │
     * │ Jackpot獎金  │ 原投注記錄        │ 複製原記錄並增加 jackpot      │
     * │ 取消投注     │ 原投注記錄        │ 複製原記錄並設定 bet=0        │
     * │ 取消派彩     │ 原投注記錄        │ 複製原記錄並設定 win=0        │
     * └─────────────┴──────────────────┴─────────────────────────────┘
     *
     * 交易類型（`transaction_logs.type`）由 `TransactionLogCreateService` fallback 為
     * `SEAMLESS_WALLET_BET_SETTLE` (27)，本方法不在此推斷 28 / 29。若 vendor 有明確
     * rollback / refund / admin-modify endpoint，應在 controller 內以
     * `increaseWalletBalance()` 的 `$transactionType` 參數顯式宣告 SEAMLESS_WALLET_CANCEL / CHANGE。
     *
     * 🔴 **SW 的餘額異動一律經本方法**（它負責禮碼先結算、真金變動公式、以及建立帳變流水），
     * 禁止在 Controller 內自行加減錢包或另寫一套扣帳流程。
     *
     * @param  Member  $member  會員物件
     * @param  MemberWalletData  $walletDto  錢包資料物件（包含主錢包與禮碼錢包資訊）
     * @param  AbstractBetRecord|null  $beforeBetRecord  變更前的投注記錄（新投注時傳 null）
     * @param  AbstractBetRecord  $afterBetRecord  變更後的投注記錄（會自動更新 rolling_chip）
     * @param  bool  $autoSave  是否自動保存 afterBetRecord 到資料庫（預設 true）
     * @return int 真金錢包的交易紀錄 ID（若無真金變動則回傳 0）
     *
     * @throws Exception
     */
    protected function changeBalance(
        Member $member,
        MemberWalletData $walletDto,
        ?AbstractBetRecord $beforeBetRecord,
        AbstractBetRecord $afterBetRecord,
        bool $autoSave = true,
    ): int {
        // ==================== 第一步：取得投注與派彩金額 ====================
        $currentBetAmount = $afterBetRecord->getBet();          // 當前投注金額
        $previousBetAmount = $beforeBetRecord?->getBet() ?? 0;  // 先前投注金額（無則為 0）
        $currentWinAmount = $afterBetRecord->getWin();          // 當前派彩金額
        $previousWinAmount = $beforeBetRecord?->getWin() ?? 0;  // 先前派彩金額（無則為 0）
        $currentBonusAmount = $afterBetRecord->getBonus();      // 當前獎金金額（運營商負擔）
        $previousBonusAmount = $beforeBetRecord?->getBonus() ?? 0;  // 先前獎金金額
        $currentJackpotAmount = $afterBetRecord->getJackpot();  // 當前彩池金額
        $previousJackpotAmount = $beforeBetRecord?->getJackpot() ?? 0;  // 先前彩池金額

        // 計算投注金額變化量（正數=增加投注，負數=減少投注）
        $betAmountChange = Common::numSub($currentBetAmount, $previousBetAmount, 4);

        // ==================== 第二步：處理禮碼變動 ====================
        // ⚠️ 重要：在調整禮碼之前，先保存原始的禮碼金額（用於後續計算真金變動）
        $originalPreviousChipPaid = $beforeBetRecord?->getRollingChip() ?? 0;

        // 只有當投注金額有變化且會員有禮碼錢包時才處理
        if (Common::numCompare($betAmountChange, '0') !== 0 && $walletDto->rollingChipWallet) {
            $previousRollingChip = $beforeBetRecord?->getRollingChip() ?? '0';
            $rollingChipService = new RollingChipService($member, $walletDto->rollingChipWallet, $this->vendorInfo?->vendor_id ?? 0);

            if (Common::numCompare($betAmountChange, '0') > 0) {
                // 情境：新增投注或增加投注金額 -> 扣除禮碼
                $chipDeductResult = $rollingChipService->sub($betAmountChange, RollingChipLogTypeEnum::BET_SETTLE);
                $actualChipDeducted = Common::numSub($chipDeductResult->beforeBalance, $chipDeductResult->afterBalance);

                // 將實際扣除的禮碼金額累加到投注記錄
                $newRollingChip = Common::numAdd($afterBetRecord->getRollingChip(), $actualChipDeducted, 4);
                $afterBetRecord->setRollingChip($newRollingChip);
            } else {
                // 情境：取消投注或減少投注金額 -> 退還禮碼
                // 退還金額不能超過該筆投注已使用的禮碼（else 分支 betAmountChange 必為負數，取絕對值）
                $betChangeAbs = Common::numMul($betAmountChange, -1);
                $rollingChip = $afterBetRecord->getRollingChip();
                $chipRefundAmount = Common::numCompare($rollingChip, $betChangeAbs) <= 0 ? $rollingChip : $betChangeAbs;
                $rollingChipService->add($chipRefundAmount, RollingChipLogTypeEnum::BET_CANCEL);

                // 從投注記錄中減去已退還的禮碼
                $newRollingChip = Common::numSub($afterBetRecord->getRollingChip(), $chipRefundAmount, 4);
                $afterBetRecord->setRollingChip($newRollingChip);
            }

            // 記錄禮碼變化日誌（便於追蹤與審計）
            $currentRollingChip = $afterBetRecord->getRollingChip();
            Log::info($this->actionPrefix . '_rolling_chip_changed', [
                'vendor_id' => $this->vendorId,
                'member_id' => $member->id,
                'member_username' => $member->username,
                'bet_record_id' => $afterBetRecord->id ?? null,
                'bet_id' => $afterBetRecord->bet_id ?? null,
                'wallet_id' => $walletDto->rollingChipWallet->id ?? null,
                'before' => $previousRollingChip,
                'after' => $currentRollingChip,
                'change' => Common::numSub($currentRollingChip, $previousRollingChip, 4),
                'bet_amount_change' => $betAmountChange,
            ]);
        }

        // 根據 autoSave 參數決定是否立即保存投注記錄
        if ($autoSave) {
            $afterBetRecord->save();
        }

        // ==================== 第三步：計算真金錢包變動 ====================
        // 真金錢包變動 = (派彩 - 真金投注) + bonus變化 + jackpot變化

        // 使用原始的禮碼支付金額（調整前的值）
        $currentChipPaid = $afterBetRecord->getRollingChip();      // 本次投注原本已使用的禮碼
        $previousChipPaid = $originalPreviousChipPaid;    // 先前已使用的禮碼

        // 計算當前與先前的真金投注金額（總投注 - 原始禮碼）
        $currentCashBet = Common::numSub($currentBetAmount, $currentChipPaid, 4);
        $previousCashBet = Common::numSub($previousBetAmount, $previousChipPaid, 4);

        // 計算當前與先前的真金淨變動（派彩 - 真金投注）
        $currentCashNetChange = Common::numSub($currentWinAmount, $currentCashBet, 4);
        $previousCashNetChange = Common::numSub($previousWinAmount, $previousCashBet, 4);

        // 基礎錢包變動 = 當前淨變動 - 先前淨變動
        $baseWalletChange = Common::numSub($currentCashNetChange, $previousCashNetChange, 4);

        // 計算 bonus 變化量（運營商負擔的獎金，直接增加用戶餘額）
        $bonusChange = Common::numSub($currentBonusAmount, $previousBonusAmount, 4);

        // 計算 jackpot 變化量（彩池獎金，直接增加用戶餘額）
        $jackpotChange = Common::numSub($currentJackpotAmount, $previousJackpotAmount, 4);

        // 最終真金錢包變動 = 基礎變動 + bonus變化 + jackpot變化
        $walletBalanceChange = Common::numAdd($baseWalletChange, $bonusChange, 4);
        $walletBalanceChange = Common::numAdd($walletBalanceChange, $jackpotChange, 4);

        // ==================== 第四步：記錄真金錢包變化日誌 ====================
        if (Common::numCompare($walletBalanceChange, 0) != 0) {
            $beforeWalletBalance = $walletDto->wallet->balance;
            $afterWalletBalance = Common::numAdd($beforeWalletBalance, $walletBalanceChange, 4);

            Log::info($this->actionPrefix . '_wallet_balance_changed', [
                'vendor_id' => $this->vendorId,
                'member_id' => $member->id,
                'member_username' => $member->username,
                'wallet_id' => $walletDto->wallet->id,
                'wallet_type' => $walletDto->isMultipleWallet ? 'multiple' : 'single',
                'before' => $beforeWalletBalance,
                'after' => $afterWalletBalance,
                'change' => $walletBalanceChange,
                'base_change' => $baseWalletChange,
                'bonus_change' => $bonusChange,
                'jackpot_change' => $jackpotChange,
                'bet_record_id' => $afterBetRecord->id ?? null,
                'bet_id' => $afterBetRecord->bet_id ?? null,
            ]);
        }

        // ==================== 第五步：更新真金錢包並建立交易紀錄 ====================
        if (isset($this->account) && $this->account->status == 0) {
            Log::info($this->account->username . ' 遊戲帳號狀態為0, changeBalance不加扣錢');

            return 0; // 活動帳號如果被關閉則不加扣錢，但還是要記帳
        }

        $transactionLogId = $this->increaseWalletBalance($member, $walletDto, $walletBalanceChange);

        // 真金無淨變動時不會建立交易紀錄（如輸錢結算派彩為 0），改取本回合進行中的那筆，
        // 讓呼叫端仍能把 total_bet_record 關聯到 transaction_log；否則該注單永遠不會出現在
        // SR14 現金紀錄 detail。
        // 以「本回合真金投注額」為準而非 walletBalanceChange：全禮碼投注的真金投注為 0、
        // 全程不涉及現金，不可掛到同 session 其他回合的現金紀錄底下。
        if ($transactionLogId === 0 && Common::numCompare($currentCashBet, 0) > 0) {
            $transactionLogId = (new TransactionLogCreateService($member, $this->vendorInfo, $walletDto->isMultipleWallet, $this->account))
                ->getGamingTransactionLog()?->id ?? 0;
        }

        return $transactionLogId;
    }

    protected function increaseWalletBalance(
        Member $member,
        MemberWalletData $walletDto,
        string $walletChange,
        ?int $transactionType = null
    ): int {
        $transactionLogId = 0;

        if (Common::numCompare($walletChange, 0) != 0) {
            $before = $walletDto->wallet->balance;
            $after = Common::numAdd($before, $walletChange, 4);
            $walletDto->wallet->update([
                'balance' => $after,
            ]);

            // 真金交易紀錄
            $transactionLogService = new TransactionLogCreateService($member, $this->vendorInfo, $walletDto->isMultipleWallet, $this->account);
            if ($transactionType !== null) {
                $transactionLogService->setType($transactionType);
            }
            $transactionLog = $transactionLogService->create($walletChange, $before, $after);
            $transactionLogId = $transactionLog->id;
        }

        return $transactionLogId;
    }

    protected function changeEventBalance(
        MemberWallet $wallet,
        ?AbstractBetRecord $beforeBetRecord,
        AbstractBetRecord $afterBetRecord,
        bool $autoSave = true,
    ): void {
        // ==================== 第一步：取得投注與派彩金額 ====================
        $currentBetAmount = $afterBetRecord->getBet();          // 當前投注金額
        $previousBetAmount = $beforeBetRecord?->getBet() ?? 0;  // 先前投注金額（無則為 0）
        $currentWinAmount = $afterBetRecord->getWin();          // 當前派彩金額
        $previousWinAmount = $beforeBetRecord?->getWin() ?? 0;  // 先前派彩金額（無則為 0）
        $currentBonusAmount = $afterBetRecord->getBonus();      // 當前獎金金額（運營商負擔）
        $previousBonusAmount = $beforeBetRecord?->getBonus() ?? 0;  // 先前獎金金額
        $currentJackpotAmount = $afterBetRecord->getJackpot();  // 當前彩池金額
        $previousJackpotAmount = $beforeBetRecord?->getJackpot() ?? 0;  // 先前彩池金額

        // 計算投注金額變化量（正數=增加投注，負數=減少投注）
        $betAmountChange = Common::numSub($currentBetAmount, $previousBetAmount, 4);

        // 根據 autoSave 參數決定是否立即保存投注記錄
        if ($autoSave) {
            $afterBetRecord->save();
        }

        // ==================== 第二步：計算刷分活動錢包變動 ====================
        // 刷分活動錢包變動 = (派彩 - 投注) + bonus變化 + jackpot變化

        // 計算當前與先前的真金淨變動（派彩 - 投注）
        $currentCashNetChange = Common::numSub($currentWinAmount, $currentBetAmount, 4);
        $previousCashNetChange = Common::numSub($previousWinAmount, $previousBetAmount, 4);

        // 基礎錢包變動 = 當前淨變動 - 先前淨變動
        $baseWalletChange = Common::numSub($currentCashNetChange, $previousCashNetChange, 4);

        // 計算 bonus 變化量（運營商負擔的獎金，直接增加用戶餘額）
        $bonusChange = Common::numSub($currentBonusAmount, $previousBonusAmount, 4);

        // 計算 jackpot 變化量（彩池獎金，直接增加用戶餘額）
        $jackpotChange = Common::numSub($currentJackpotAmount, $previousJackpotAmount, 4);

        // 最終真金錢包變動 = 基礎變動 + bonus變化 + jackpot變化
        $walletBalanceChange = Common::numAdd($baseWalletChange, $bonusChange, 4);
        $walletBalanceChange = Common::numAdd($walletBalanceChange, $jackpotChange, 4);

        // ==================== 第三步：記錄活動錢包變化日誌 ====================
        if (Common::numCompare($walletBalanceChange, 0) != 0) {
            $beforeWalletBalance = $wallet->balance;
            $afterWalletBalance = Common::numAdd($beforeWalletBalance, $walletBalanceChange, 4);

            Log::info($this->actionPrefix . '_wallet_balance_changed', [
                'vendor_id' => $this->vendorId,
                'member_id' => $wallet->member_id,
                'wallet_id' => $wallet->id,
                'before' => $beforeWalletBalance,
                'after' => $afterWalletBalance,
                'change' => $walletBalanceChange,
                'base_change' => $baseWalletChange,
                'bonus_change' => $bonusChange,
                'jackpot_change' => $jackpotChange,
                'bet_record_id' => $afterBetRecord->id ?? null,
                'bet_id' => $afterBetRecord->bet_id ?? null,
            ]);
        }

        // ==================== 第四步：更新活動錢包 ====================
        if (isset($this->account) && $this->account->status == 0) {
            Log::info($this->account->username . ' 遊戲帳號狀態為0, changeBalance不加扣錢');
        }

        $this->increaseEventWalletBalance($wallet, $walletBalanceChange);
    }

    protected function increaseEventWalletBalance(MemberWallet $wallet, string $walletChange): void
    {
        if (Common::numCompare($walletChange, 0) != 0) {
            $before = $wallet->balance;
            $after = Common::numAdd($before, $walletChange, 4);
            $wallet->update(['balance' => $after]);
        }
    }

    /**
     * @throws BetException
     */
    protected function verifyBettability(string $game): void
    {
        BetHelper::assertMemberAbleToBet($this->member, $this->vendorInfo, $game, $this, $this->account);
    }

    /**
     * 記錄三方回調的請求內容。
     *
     * 參數一律經 {@see Common::maskCredentials()} 遮罩後才落 log。回調請求內容（payload）會帶三方
     * 靜態憑證（Ace333SW 的 password、Rcb988／Imperium／AFBSW 的 key、WE 的 appSecret）。
     * 日誌通道（log channel）的可及範圍比資料庫廣。未消耗的憑證等同金錢。簽名類欄位
     * （sign／Signature／hash）刻意不在遮罩清單內，保留「從 log 重算簽名」的排查能力（見 MaskTrait 政策）。
     *
     * 不記錄原始請求本文（body，即 getContent() 取得的字串）。它與 params 是同一份內容，但字串形態
     * 套不上陣列遮罩；記下來等於讓憑證從第二個欄位漏出去。要判斷遊戲商是否送出非標準格式，看 ContentType。
     */
    protected function logRequest(Request $request, $action)
    {
        Log::info($action, [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => Common::flattenHeaders($request->header()),
            'params' => Common::maskCredentials($request->all()),
            'ContentType' => $request->getContentTypeFormat(),
            'IP' => Common::getIp(),
        ]);
    }

    /**
     * 將參數追加到 info 欄位
     *
     * ⚠️ 這裡**刻意不遮罩**（target/op_tickets#3076 裁定）。注單 info 是與遊戲商對帳、處理爭議時的
     * 原始證據，金額或狀態對不上時要靠它還原當時收到什麼；遮掉等於失去該能力。
     * 專案紅線要求遮罩的是「憑證入 log」：日誌通道（log channel）的可及範圍遠大於資料庫，兩者風險不同。
     * 憑證遮罩由 {@see logRequest()} 負責。本方法只負責把呼叫端傳入的參數不遮罩地追加到注單上；
     * 呼叫端可能先剔除部分欄位再傳入（例如 AiGamingSW 的 stripGameInfo() 剔除 gameInfo），
     * 那些欄位就不會留在注單上。
     */
    protected function appendParamsToInfo($info, $params)
    {
        $infoArr = json_decode($info, true);
        $infoArr[] = $params;

        return json_encode($infoArr);
    }

    /**
     * 投注前應檢查遊戲是否存在
     */
    protected function getGameByCode(string $gameCode): ?Game
    {
        return Game::query()
            ->where('vendor_id', $this->vendorId)
            ->where('game_type', $gameCode)
            // ->where('status', 1)
            ->first();
    }
}
