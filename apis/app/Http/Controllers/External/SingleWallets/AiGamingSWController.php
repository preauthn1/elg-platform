<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\AiGamingSwAccount;
use App\Vendors\Models\AiGamingSwBetRecord;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/vendor-aigamingsw
 */
class AiGamingSWController extends BaseController implements ShouldVerifyGameBettability
{
    private const int ERROR_MEMBER_NOT_EXIST = 1;

    private const int ERROR_INVALID_IP = 2;

    private const int ERROR_USERNAME_EMPTY = 3;

    private const int ERROR_API_KEY = 4;

    private const int ERROR_INSUFFICIENT_BALANCE = 5;

    private const int ERROR_BET_NOT_EXISTS = 6;

    private const int ERROR_INTERNAL = 7;

    private const int ERROR_BET_ALREADY_SETTLED = 2001;

    private const int ERROR_DUPLICATE_TRANSACTION = 5003;

    private const int ERROR_ALREADY_RETURNED_STAKE = 5008;

    protected int $vendorId = VendorIdEnum::AIGAMINGSW->value;

    protected string $accountClass = AiGamingSwAccount::class;

    protected string $betRecordClass = AiGamingSwBetRecord::class;

    protected string $actionPrefix = 'AiGamingSW_';

    protected string $vendorInfoOperatorField = 'PROVIDER';

    /**
     * 集中前置驗證：IP 白名單 → 解析帳號（username）→ 驗簽 → 幣別 → 帳號停用檢核
     */
    public function callAction($method, $parameters): mixed
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse(self::ERROR_INVALID_IP, 'Invalid Ip');
        }

        $request = request();

        $username = (string) $request->input('username', '');
        if ($username === '') {
            return $this->errorResponse(self::ERROR_USERNAME_EMPTY, 'Username empty');
        }

        try {
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse(self::ERROR_MEMBER_NOT_EXIST, 'Member not exist');
            }

            $this->vendorInfo = $this->getVendorInfo('', $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(self::ERROR_MEMBER_NOT_EXIST, 'Member not exist');
            }
        } catch (VendorAccountException|MemberNotFoundException|VendorNotFoundException) {
            return $this->errorResponse(self::ERROR_MEMBER_NOT_EXIST, 'Member not exist');
        }

        if (!$this->isSignatureValid($request, (string) $method)) {
            return $this->errorResponse(self::ERROR_API_KEY, 'apiKey Error');
        }

        // 回調金額為廠商幣別單位，幣別不符時直接拒絕，避免以錯誤幣值入帳
        if ((string) $request->input('walletType', '') !== (string) $this->vendorInfo->currency) {
            return $this->errorResponse(self::ERROR_INTERNAL, 'Invalid walletType');
        }

        // 帳號停用檢核，涵蓋查詢餘額在內所有涉及餘額讀取或異動的回調
        if ($this->account->status == 0) {
            return $this->errorResponse(self::ERROR_MEMBER_NOT_EXIST, 'Member not exist');
        }

        return parent::callAction($method, $parameters);
    }

    public function getBalance(Request $request): JsonResponse
    {
        return $this->successResponse();
    }

    /**
     * 投注扣款：transactionId 為冪等鍵，重複 deduct 回 5003
     */
    public function deduct(Request $request): JsonResponse
    {
        try {
            $this->verifyBettability((string) $request->input('gameCode', ''));
        } catch (BetException) {
            return $this->errorResponse(self::ERROR_INTERNAL, 'Bet not allowed');
        }

        $transactionId = (string) $request->input('transactionId', '');
        $bet = Common::numDiv((string) $request->input('amount', '0'), $this->vendorInfo->rate, 4);

        if ($transactionId === '' || Common::numCompare($bet, '0') <= 0) {
            return $this->errorResponse(self::ERROR_INTERNAL, 'Invalid parameter');
        }

        try {
            return DB::transaction(function () use ($request, $transactionId, $bet) {
                // 鎖序統一為「會員 → 注單」，與其他單錢包路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                // ⚠️ 不可加 lockForUpdate()：bet_id 為 UNIQUE，對不存在的值做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 併發防線由 UNIQUE index 承擔：撞號者拋 1062，由下方 catch 回 DUPLICATE_TRANSACTION。
                $existing = $this->betRecordClass::where('bet_id', $transactionId)->first();
                if ($existing) {
                    return $this->errorResponse(self::ERROR_DUPLICATE_TRANSACTION, 'Bet With Same TransactionId Exists');
                }

                if (Common::numCompare($walletDto->getTotalBalance(), $bet) < 0) {
                    return $this->errorResponse(self::ERROR_INSUFFICIENT_BALANCE, 'Not enough balance');
                }

                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $transactionId,
                    'game_code' => (string) $request->input('gameCode', ''),
                    'bet' => $bet,
                    'valid_bet' => $bet,
                    'win' => 0,
                    'win_lose' => Common::numMul($bet, -1),
                    'bet_time' => $this->parseVendorTime((string) $request->input('betTime', '')),
                    'status' => 0,
                    'info' => Common::safeJsonEncode($this->stripGameInfo($request->all())),
                ]);

                // TBR 於結算才建（輸贏皆會收到 settle），deduct 階段僅建 BR 與扣款
                $this->changeBalance($this->member, $walletDto, null, $betRecord);

                return $this->successResponse($walletDto->getTotalBalance());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                // 競態下的重複 transactionId，冪等回覆
                return $this->errorResponse(self::ERROR_DUPLICATE_TRANSACTION, 'Bet With Same TransactionId Exists');
            }

            throw $e;
        }
    }

    /**
     * 結算派彩：payoutAmount 含本金；每個 transactionId 只 settle 一次即鎖定已結算
     * （實測 isFirst / isEnd 恆為 false，僅為 spin 顯示資訊，不可作為結算完成旗標）
     */
    public function settle(Request $request): JsonResponse
    {
        $transactionId = (string) $request->input('transactionId', '');
        $payout = Common::numDiv((string) $request->input('payoutAmount', '0'), $this->vendorInfo->rate, 4);
        $resultType = strtoupper((string) $request->input('resultType', ''));

        return DB::transaction(function () use ($request, $transactionId, $payout, $resultType) {
            // 鎖序統一為「會員 → 注單」，與其他單錢包路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $oldBetRecord = $this->betRecordClass::where('bet_id', $transactionId)->lockForUpdate()->first();
            if (!$oldBetRecord) {
                return $this->errorResponse(self::ERROR_BET_NOT_EXISTS, 'Bet not exists');
            }
            if ($oldBetRecord->status == 2) {
                return $this->errorResponse(self::ERROR_ALREADY_RETURNED_STAKE, 'Bet Already Returned Stake');
            }
            if ($oldBetRecord->status == 1) {
                return $this->errorResponse(self::ERROR_BET_ALREADY_SETTLED, 'Bet Already Settled');
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->win = Common::numAdd($betRecord->win, $payout, 4);
            $betRecord->win_lose = Common::numSub($betRecord->win, $betRecord->bet, 4);
            $betRecord->payout_time = $this->parseVendorTime((string) $request->input('resultTime', ''));
            $betRecord->status = 1;
            if ($resultType === 'CANCEL') {
                // 局取消：本金以 payoutAmount 退回，不計有效投注
                $betRecord->valid_bet = 0;
            }
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $this->stripGameInfo($request->all()));

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletDto->isMultipleWallet,
            ))->create($betRecord, $transactionLogId);

            return $this->successResponse($walletDto->getTotalBalance());
        });
    }

    /**
     * bonus game 派彩：自帶獨立 transactionId 的新單（非 deduct 的單號），純加款。
     * 實測 amount 恆等於 payoutAmount（gameInfo.betAmount=0，無額外投注），依文件
     * 「refer payoutAmount as final amount」只入 payoutAmount，amount 不參與運算。
     */
    public function bonus(Request $request): JsonResponse
    {
        $transactionId = (string) $request->input('transactionId', '');
        $payout = Common::numDiv((string) $request->input('payoutAmount', '0'), $this->vendorInfo->rate, 4);

        if ($transactionId === '' || Common::numCompare($payout, '0') < 0) {
            return $this->errorResponse(self::ERROR_INTERNAL, 'Invalid parameter');
        }

        // free spin 未中獎（payoutAmount=0）僅為過程通知：錢包零變動、無帳務資訊，不建注單；
        // 重送同樣無害，不需 bet_id 冪等防線
        if (Common::numCompare($payout, '0') === 0) {
            return $this->successResponse();
        }

        try {
            return DB::transaction(function () use ($request, $transactionId, $payout) {
                // 鎖序統一為「會員 → 注單」，與其他單錢包路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                // ⚠️ 不可加 lockForUpdate()：bet_id 為 UNIQUE，對不存在的值做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 併發防線由 UNIQUE index 承擔：撞號者拋 1062，由下方 catch 回 DUPLICATE_TRANSACTION。
                $existing = $this->betRecordClass::where('bet_id', $transactionId)->first();
                if ($existing) {
                    return $this->errorResponse(self::ERROR_DUPLICATE_TRANSACTION, 'Bet With Same TransactionId Exists');
                }

                $resultTime = $this->parseVendorTime((string) $request->input('resultTime', ''));
                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $transactionId,
                    'game_code' => (string) $request->input('gameCode', ''),
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => $payout,
                    'win_lose' => $payout,
                    'bet_time' => $resultTime,
                    'payout_time' => $resultTime,
                    'status' => 1,
                    'info' => Common::safeJsonEncode($this->stripGameInfo($request->all())),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse($walletDto->getTotalBalance());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->errorResponse(self::ERROR_DUPLICATE_TRANSACTION, 'Bet With Same TransactionId Exists');
            }

            throw $e;
        }
    }

    /**
     * 取消投注：deduct 後下注失敗的沖銷（bet/win 歸零退款）
     */
    public function rollback(Request $request): JsonResponse
    {
        $transactionId = (string) $request->input('transactionId', '');

        return DB::transaction(function () use ($request, $transactionId) {
            // 鎖序統一為「會員 → 注單」，與其他單錢包路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $oldBetRecord = $this->betRecordClass::where('bet_id', $transactionId)->lockForUpdate()->first();
            if (!$oldBetRecord) {
                return $this->errorResponse(self::ERROR_BET_NOT_EXISTS, 'Bet not exists');
            }
            if ($oldBetRecord->status == 2) {
                return $this->errorResponse(self::ERROR_ALREADY_RETURNED_STAKE, 'Bet Already Returned Stake');
            }
            if ($oldBetRecord->status == 1) {
                return $this->errorResponse(self::ERROR_BET_ALREADY_SETTLED, 'Bet Already Settled');
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win = 0;
            $betRecord->win_lose = 0;
            $betRecord->status = 2;
            $betRecord->payout_time = now();
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $this->stripGameInfo($request->all()));

            // 結算前取消，TBR 尚未建立，不需補歸零
            $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

            return $this->successResponse($walletDto->getTotalBalance());
        });
    }

    /**
     * 純派彩（無對應投注）：自帶獨立 transactionId，建立 bet=0 的已結算注單
     */
    public function cashBonus(Request $request): JsonResponse
    {
        $transactionId = (string) $request->input('transactionId', '');
        $payout = Common::numDiv((string) $request->input('payoutAmount', '0'), $this->vendorInfo->rate, 4);

        if ($transactionId === '' || Common::numCompare($payout, '0') <= 0) {
            return $this->errorResponse(self::ERROR_INTERNAL, 'Invalid parameter');
        }

        try {
            return DB::transaction(function () use ($request, $transactionId, $payout) {
                // 鎖序統一為「會員 → 注單」，與其他單錢包路徑一致，避免交錯持鎖成死鎖（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                // ⚠️ 不可加 lockForUpdate()：bet_id 為 UNIQUE，對不存在的值做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 併發防線由 UNIQUE index 承擔：撞號者拋 1062，由下方 catch 回 DUPLICATE_TRANSACTION。
                $existing = $this->betRecordClass::where('bet_id', $transactionId)->first();
                if ($existing) {
                    return $this->errorResponse(self::ERROR_DUPLICATE_TRANSACTION, 'Bet With Same TransactionId Exists');
                }

                $resultTime = $this->parseVendorTime((string) $request->input('resultTime', ''));
                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $transactionId,
                    'game_code' => (string) $request->input('gameCode', ''),
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win' => 0,
                    'win_lose' => 0,
                    // 純派彩記活動獎金欄，不入 win / win_lose；changeBalance 以 bonusChange 加款
                    'bonus' => $payout,
                    'bet_time' => $resultTime,
                    'payout_time' => $resultTime,
                    'status' => 1,
                    'info' => Common::safeJsonEncode($this->stripGameInfo($request->all())),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId);

                return $this->successResponse($walletDto->getTotalBalance());
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return $this->errorResponse(self::ERROR_DUPLICATE_TRANSACTION, 'Bet With Same TransactionId Exists');
            }

            throw $e;
        }
    }

    /**
     * 驗證回調的簽名：sign 須等於 md5(API_KEY ＋ 該端點規定的欄位串接)，雙方轉小寫後比對。
     * 各端點串接的欄位不同，見 docs/integrations/vendors/AiGamingSW/api.md「簽名（MD5）」節。
     *
     * 🔴 API_KEY 未設定時一律拒收，禁止放行。未設定就放行的話，「設定漏填」等於無聲地拆掉
     * 這道防線。本遊戲商的 IP 白名單（`vendors.whitelist`）在五站實查皆為空，API_KEY
     * 未設定時就是簽名與 IP 白名單兩道都沒有。
     *
     * 拒收不影響現有站點：實查本遊戲商尚未在任何站點建立遊戲商戶（`vendor_infos`），仍在接入中。
     */
    private function isSignatureValid(Request $request, string $method): bool
    {
        $apiKey = [REDACTED] ($this->vendorInfo->info['API_KEY'] ?? '');
        if ($apiKey === '') {
            Log::warning($this->actionPrefix . 'api_key_not_configured', [
                'vendor_info_id' => $this->vendorInfo->id,
                'reason' => 'info.API_KEY 未設定，回調一律拒收；請補上該站商戶設定',
            ]);

            return false;
        }

        $in = fn (string $key): string => (string) $request->input($key, '');

        $fields = match ($method) {
            'getBalance' => $in('timestamp') . $in('username'),
            'deduct' => $in('timestamp') . $in('username') . $in('amount')
                . $in('transactionId') . $in('betTime') . $in('gameTypeName'),
            'settle' => $in('timestamp') . $in('username') . $in('transactionId')
                . $in('payoutAmount') . $in('resultType') . $in('gameTypeName') . $in('resultTime'),
            'bonus', 'rollback', 'cashBonus' => $in('timestamp') . $in('username') . $in('amount')
                . $in('transactionId') . $in('walletType'),
            default => null,
        };

        if ($fields === null) {
            return false;
        }

        return hash_equals(strtolower(md5($apiKey . $fields)), strtolower($in('sign')));
    }

    /**
     * betTime / resultTime 為 ISO8601（含納秒）且無時區後綴，實測為 UTC+8
     * （betTime 17:39:21 對應 GCP entry 09:39:21Z），格式異常時以當下時間落帳
     */
    private function parseVendorTime(string $time): string
    {
        try {
            return Carbon::parse($time, '+08:00')->timezone('UTC')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return now()->format('Y-m-d H:i:s');
        }
    }

    /**
     * gameInfo 為廠商完整盤面 JSON（單筆可達數 KB），不落 info 欄位避免撐爆 text
     */
    private function stripGameInfo(array $params): array
    {
        unset($params['gameInfo']);

        return $params;
    }

    private function successResponse(?string $platformBalance = null): JsonResponse
    {
        $balance = $platformBalance !== null
            ? Common::numMul($platformBalance, $this->vendorInfo->rate, 4)
            // getSingleWalletBalanceByMember() 內部已乘上 vendorInfo->rate，不可再重複換算
            : (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return response()->json([
            'errorCode' => 0,
            'errorMessage' => 'success',
            'balance' => (float) $balance,
        ]);
    }

    private function errorResponse(int $code, string $message): JsonResponse
    {
        return response()->json([
            'errorCode' => $code,
            'errorMessage' => $message,
            'balance' => 0,
        ]);
    }
}
