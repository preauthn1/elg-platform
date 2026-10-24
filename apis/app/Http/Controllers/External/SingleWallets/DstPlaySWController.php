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
use App\Vendors\Models\DstPlaySwAccount;
use App\Vendors\Models\DstPlaySwBetRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DstPlaySWController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::DSTPLAYSW->value;

    protected string $accountClass = DstPlaySwAccount::class;

    protected string $betRecordClass = DstPlaySwBetRecord::class;

    protected string $actionPrefix = 'DstPlaySW_';

    protected string $vendorInfoOperatorField = 'API_ID';

    /**
     * 集中前置驗證：IP 白名單 → 解析帳號（getAuth 用 token，其餘用 user_id）→ 驗簽 → 帳號停用檢核
     */
    public function callAction($method, $parameters): mixed
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse(107, 'Unauthorized request.');
        }

        $request = request();

        try {
            if ($method === 'getAuth') {
                $token = [REDACTED] $request->input('token', '');
                if ($token === '') {
                    return $this->errorResponse(104, 'Missing Parameter.');
                }

                $this->member = $this->getMemberBySessionToken($token);
                if (!$this->member) {
                    return $this->errorResponse(101, 'Not Found.');
                }
            } else {
                $userId = (string) $request->input('user_id', '');
                if ($userId === '') {
                    return $this->errorResponse(104, 'Missing Parameter.');
                }

                $this->member = $this->getMemberByThirdPartyUsername($userId);
                if (!$this->member) {
                    return $this->errorResponse(101, 'Not Found.');
                }
            }

            $this->vendorInfo = $this->getVendorInfo('', $this->account);
            if (!$this->vendorInfo) {
                return $this->errorResponse(107, 'Unauthorized request.');
            }
        } catch (VendorAccountException|MemberNotFoundException|VendorNotFoundException) {
            return $this->errorResponse(101, 'Not Found.');
        }

        if (!$this->isSignatureValid($request)) {
            return $this->errorResponse(107, 'Unauthorized request.');
        }

        // 帳號停用檢核，涵蓋查詢餘額在內所有涉及餘額讀取或異動的回調
        if ($this->account->status == 0) {
            return $this->errorResponse(107, 'Unauthorized request.');
        }

        return parent::callAction($method, $parameters);
    }

    /**
     * 認證（僅在 gameLaunchByToken 啟動遊戲時觸發）
     */
    public function getAuth(Request $request): JsonResponse
    {
        // getSingleWalletBalanceByMember() 內部已乘上 vendorInfo->rate，不可再重複換算
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return response()->json([
            'code' => 0,
            'msg' => 'Success.',
            'balance' => (float) $balance,
            'userId' => $this->account->username,
        ]);
    }

    public function getBalance(Request $request): JsonResponse
    {
        // getSingleWalletBalanceByMember() 內部已乘上 vendorInfo->rate，不可再重複換算
        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return response()->json([
            'code' => 0,
            'msg' => 'Success',
            'balance' => (float) $balance,
        ]);
    }

    /**
     * 驗證遊戲結果：bet_amount + win_amount 合一扣款／派彩，log_id 為冪等鍵
     */
    public function gameResult(Request $request): JsonResponse
    {
        try {
            $this->verifyBettability((string) $request->input('game_code', ''));
        } catch (BetException) {
            return $this->errorResponse(102, 'Invalid Action.');
        }

        return $this->handleResultCallback($request, 'bet');
    }

    /**
     * 驗證獎金結果（僅免費旋轉獎勵遊戲觸發，win_amount 恆為正數，不扣款）
     */
    public function bonusResult(Request $request): JsonResponse
    {
        return $this->handleResultCallback($request, 'bonus');
    }

    /**
     * 驗證促銷結果（錦標賽等活動獎金，win_amount 恆為正數，不扣款）
     */
    public function promoResult(Request $request): JsonResponse
    {
        return $this->handleResultCallback($request, 'promo');
    }

    private function handleResultCallback(Request $request, string $type): JsonResponse
    {
        $logId = (string) $request->input('log_id', '');
        $gameRoundId = (string) $request->input('game_round_id', '');
        $gameCode = (string) $request->input('game_code', '');
        $rate = $this->vendorInfo->rate;
        $winAmount = Common::numDiv((string) $request->input('win_amount', '0'), $rate, 4);
        $betAmount = $type === 'bet'
            ? Common::numDiv((string) $request->input('bet_amount', '0'), $rate, 4)
            : '0';

        if ($logId === '' || $gameRoundId === '' || $gameCode === '') {
            return $this->errorResponse(104, 'Missing Parameter.');
        }

        try {
            return DB::transaction(function () use ($logId, $gameRoundId, $gameCode, $betAmount, $winAmount, $type, $request, $rate) {
                // 交易內第一個 SQL 必須是鎖會員列：下面的冪等查詢是一般讀，若放在鎖前，RR 快照就定在等鎖之前，
                // 拿到鎖後仍看不到對手剛 commit 的注單與現金紀錄（target/tickets#4297）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                // 冪等：相同 log_id 已處理過，直接回傳目前餘額，不重複扣款／派彩
                // ⚠️ 不可加 lockForUpdate()：reference_no 為 UNIQUE，對不存在的值做鎖定讀會取 gap lock，
                // 而 log_id 單調遞增使所有併發請求都落在同一個 supremum gap，
                // 後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 真正的併發防線是 UNIQUE index：撞號者拋 1062，由下方 catch 整筆 rollback 後回傳餘額。
                $existing = $this->betRecordClass::where('reference_no', $logId)->first();
                if ($existing) {
                    // 回傳三方幣值：錢包餘額乘上 vendorInfo->rate
                    $balance = Common::numMul($walletDto->getTotalBalance(), $rate, 4);

                    return response()->json([
                        'code' => 0,
                        'msg' => 'Success.',
                        'beginBalance' => (float) $balance,
                        'endBalance' => (float) $balance,
                    ]);
                }

                $beginBalance = $walletDto->getTotalBalance();

                if ($type === 'bet' && Common::numCompare($beginBalance, $betAmount) < 0) {
                    return $this->errorResponse(103, 'Insufficient Balance.');
                }

                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => $gameRoundId,
                    'reference_no' => $logId,
                    'game_code' => $gameCode,
                    'type' => $type,
                    'bet' => $betAmount,
                    'valid_bet' => $betAmount,
                    // api.md：win_amount 本身即淨輸贏（步驟：扣 bet_amount 後「加」win_amount，可為負），
                    // 非毛彩金；win（毛彩金）需反推 = win_amount + bet_amount，供 changeBalance 的 (win-bet) 公式使用
                    'win' => Common::numAdd($winAmount, $betAmount, 4),
                    'win_lose' => $winAmount,
                    'bet_time' => now(),
                    'payout_time' => now(),
                    'status' => 1,
                    'info' => Common::safeJsonEncode($request->all()),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                (new TotalBetRecordService(
                    vendorId: $this->vendorInfo->vendor_id,
                    memberId: $this->member->id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId, ['reference_no' => $logId]);

                $endBalance = $walletDto->getTotalBalance();

                return response()->json([
                    'code' => 0,
                    'msg' => 'Success.',
                    'beginBalance' => (float) Common::numMul($beginBalance, $rate, 4),
                    'endBalance' => (float) Common::numMul($endBalance, $rate, 4),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                // 競態條件下的重複 log_id：回傳當前餘額（冪等）
                // getSingleWalletBalanceByMember() 內部已乘上 vendorInfo->rate，不可再重複換算
                $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

                return response()->json([
                    'code' => 0,
                    'msg' => 'Success.',
                    'beginBalance' => (float) $balance,
                    'endBalance' => (float) $balance,
                ]);
            }

            throw $e;
        }
    }

    /**
     * 退款：依 refund_log_id 找回原 gameResult 注單並全額沖銷（bet/win 歸零）
     */
    public function refund(Request $request): JsonResponse
    {
        $refundLogId = (string) $request->input('refund_log_id', '');

        if ($refundLogId === '') {
            return $this->errorResponse(104, 'Missing Parameter.');
        }

        return DB::transaction(function () use ($refundLogId) {
            $beforeBetRecord = $this->betRecordClass::where('reference_no', $refundLogId)
                ->lockForUpdate()
                ->first();

            // 原文：找不到 refund_log_id 對應的 gameResult 也回傳代碼 0
            if (!$beforeBetRecord) {
                return response()->json(['code' => 0, 'msg' => 'Success.']);
            }

            // 已退款過，冪等回傳成功，不重複調整餘額
            if ($beforeBetRecord->status == 2) {
                return response()->json(['code' => 0, 'msg' => 'Success.']);
            }

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $afterBetRecord = clone $beforeBetRecord;
            $afterBetRecord->bet = 0;
            $afterBetRecord->valid_bet = 0;
            $afterBetRecord->win = 0;
            $afterBetRecord->win_lose = 0;
            $afterBetRecord->status = 2;
            $afterBetRecord->payout_time = now();

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $afterBetRecord);

            (new TotalBetRecordService(
                vendorId: $this->vendorInfo->vendor_id,
                memberId: $this->member->id,
                isMultipleWallet: $walletDto->isMultipleWallet,
            ))->create($afterBetRecord, $transactionLogId, ['reference_no' => $afterBetRecord->reference_no]);

            return response()->json(['code' => 0, 'msg' => 'Success.']);
        });
    }

    /**
     * 驗證回調帶的 api_id 與簽名：api_id 須等於遊戲商戶配置的 API_ID，sign 須等於
     * md5(API_ID + timestamp + API_KEY)。
     *
     * 🔴 API_KEY 未設定時一律拒收，禁止放行。未設定就放行的話，「設定漏填」等於無聲地拆掉
     * 這道防線。本遊戲商的 IP 白名單（`vendors.whitelist`）在五站實查皆為空，API_KEY
     * 未設定時就是簽名與 IP 白名單兩道都沒有。
     *
     * 拒收不影響現有站點：實查實際接入的只有 TPower（遊戲商戶 `vendor_infos.id` = 506），
     * API_KEY 有值。
     */
    private function isSignatureValid(Request $request): bool
    {
        $info = $this->vendorInfo->info;
        $apiId = (string) ($info['API_ID'] ?? '');
        $apiKey = [REDACTED] ($info['API_KEY'] ?? '');

        if ($apiKey === '') {
            Log::warning($this->actionPrefix . 'api_key_not_configured', [
                'vendor_info_id' => $this->vendorInfo->id,
                'reason' => 'info.API_KEY 未設定，回調一律拒收；請補上該站商戶設定',
            ]);

            return false;
        }

        if ((string) $request->input('api_id', '') !== $apiId) {
            return false;
        }

        $timestamp = (string) $request->input('timestamp', '');
        $sign = (string) $request->input('sign', '');

        return hash_equals(md5($apiId . $timestamp . $apiKey), $sign);
    }

    private function errorResponse(int $code, string $msg): JsonResponse
    {
        return response()->json(['code' => $code, 'msg' => $msg]);
    }
}
