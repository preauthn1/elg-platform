<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\PxplaySwAccount;
use App\Vendors\Models\PxplaySwBetRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PxplaySwController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::PXPLAYSW->value;

    protected string $accountClass = PxplaySwAccount::class;

    protected string $betRecordClass = PxplaySwBetRecord::class;

    protected string $actionPrefix = 'PxplaySW_';

    protected string $vendorInfoOperatorField = 'OC';

    // ─── PlayerAuthenticateV1  POST /api/v1/authenticate ─────────────────────

    public function authenticate(Request $request): JsonResponse
    {
        $this->logRequest($request, $this->actionPrefix . 'authenticate');

        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse(2000, 'IP not allowed', sprintf('IP %s not allowed', Common::getIp()));
        }

        $token = [REDACTED] $request->input('token', '');
        if (!$this->resolveByToken($token)) {
            return $this->errorResponse(1200, 'Invalid player token');
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(1200, 'Player account is disabled');
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return $this->successResponse($balance);
    }

    // ─── PlayerWalletV1  GET /api/v1/wallets ─────────────────────────────────

    public function wallets(Request $request): JsonResponse
    {
        $this->logRequest($request, $this->actionPrefix . 'wallets');

        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse(2000, 'IP not allowed', sprintf('IP %s not allowed', Common::getIp()));
        }

        $token = [REDACTED] $request->query('token', '');
        if (!$this->resolveByToken($token)) {
            return $this->errorResponse(1200, 'Invalid player token');
        }

        if ($this->account->status == 0) {
            return $this->errorResponse(1200, 'Player account is disabled');
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return $this->successResponse($balance);
    }

    // ─── PayoutTransactionV1  POST /api/v1/transactions ──────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $this->logRequest($request, $this->actionPrefix . 'transactions');

        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse(2000, 'IP not allowed', sprintf('IP %s not allowed', Common::getIp()));
        }

        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'gameId' => 'required|string',
            'gameCode' => 'required|string',
            'betId' => 'required|string',
            'betType' => 'required|string',
            'transactionId' => 'required|string',
            'currency' => 'required|string',
            'created' => 'required|string',
            'updated' => 'required|string',
            'betAmount' => 'required|numeric',
            'winAmount' => 'required|numeric',
            'isEndRound' => 'required|boolean',
            'walletType' => 'string|in:CASH,BONUS,FREE_GAME',
            'parentBetId' => 'nullable|string|min:1|exists:pxplaysw_bet_records,bet_id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(1800, 'Validation failed', $validator->errors()->first());
        }

        $token = [REDACTED] $request->input('token');
        if (!$this->resolveByToken($token)) {
            return $this->errorResponse(1200, 'Invalid player token');
        }

        if (!$this->isSignatureValid($request)) {
            return $this->errorResponse(2000, 'Signature verification failed');
        }

        if ($request->input('currency') !== $this->vendorInfo->currency) {
            return $this->errorResponse(1601, 'Currency mismatch');
        }

        $gameCode = (string) $request->input('gameCode');
        $transactionId = (string) $request->input('transactionId');

        // FREE_GAME / betAmount=0 為純結算派彩，跳過帳號狀態與投注資格檢查
        if (Common::numCompare((string) $request->input('betAmount', 0), 0) > 0) {
            if ($this->account->status == 0) {
                return $this->errorResponse(1200, 'Player account is disabled');
            }
            $this->verifyBettability($gameCode);
        }

        // 冪等：已處理過的交易直接回傳當前餘額
        if (PxplaySwBetRecord::where('reference_no', $transactionId)->exists()) {
            $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

            return $this->successResponse($balance);
        }

        try {
            return DB::transaction(function () use ($request, $transactionId) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $rate = $this->vendorInfo->rate;
                $betAmount = Common::numDiv((string) $request->input('betAmount', 0), $rate);
                $winAmount = Common::numDiv((string) $request->input('winAmount', 0), $rate);
                $winLose = Common::numSub($winAmount, $betAmount);

                // 餘額不足
                if (Common::numCompare($betAmount, 0) > 0) {
                    $totalBalance = $walletDto->getTotalBalance();
                    if (Common::numCompare(Common::numSub($totalBalance, $betAmount), '0') < 0) {
                        return $this->errorResponse(1602, 'Insufficient balance');
                    }
                }

                $now = now();
                $betRecord = new PxplaySwBetRecord([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'username' => $this->account->username,
                    'bet_id' => (string) $request->input('betId', ''),
                    'reference_no' => $transactionId,
                    'game_code' => (string) $request->input('gameCode', ''),
                    'bet_type' => (string) $request->input('betType', 'NORMAL'),
                    'bet' => $betAmount,
                    'valid_bet' => $betAmount,
                    'win' => $winAmount,
                    'win_lose' => $winLose,
                    'bonus' => 0,
                    'jackpot' => 0,
                    'rolling_chip' => 0,
                    'is_end_round' => $request->boolean('isEndRound') ? 1 : 0,
                    'bet_time' => $now,
                    'payout_time' => $now,
                    'raw_data' => $request->getContent(),
                ]);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                (new TotalBetRecordService(
                    memberId: $this->member->id,
                    vendorId: $this->vendorInfo->vendor_id,
                    isMultipleWallet: $walletDto->isMultipleWallet,
                ))->create($betRecord, $transactionLogId, ['reference_no' => $transactionId]);

                $newBalance = Common::numMul($walletDto->getTotalBalance(), $rate, 4);

                return $this->successResponse($newBalance);
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                // 競態條件下的重複 transactionId：回傳當前餘額
                $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

                return $this->successResponse($balance);
            }

            Log::error($this->actionPrefix . 'transactions DB error', [
                'transaction_id' => $request->input('transactionId'),
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(2000, 'Internal server error');
        } catch (\Throwable $e) {
            Log::error($this->actionPrefix . 'transactions error', [
                'transaction_id' => $request->input('transactionId'),
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(2000, 'Internal server error');
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function resolveByToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $this->member = $this->getMemberBySessionToken($token);
        if (!$this->member) {
            return false;
        }

        $this->vendorInfo = $this->getVendorInfo('', $this->account);

        return $this->vendorInfo !== null;
    }

    /**
     * 驗證回調的簽名，以下三項都成立才通過：
     * - 請求標頭（header）Authorization 含有 RG-HMAC-SHA256 字樣（大小寫須相同）。
     * - 從 Authorization 擷取 `Signature=` 後面連續的十六進位字元；擷取不到就拒收。
     * - 擷取值與預期簽名以 hash_equals 比對相同。預期簽名是用 SECRET 對 Host、X-Content-SHA256、
     *   X-Date 三個 header 依序串接後算出的 HMAC-SHA256（以密鑰計算的驗證碼），為小寫十六進位。
     *
     * 🔴 遊戲商戶配置的 SECRET 未設定時一律拒收，禁止放行。未設定就放行的話，「設定漏填」
     * 等於無聲地拆掉這道防線。
     *
     * 拒收不影響現有站點：實查五站，實際接入的只有 TPower（遊戲商戶 `vendor_infos.id` = 492），
     * SECRET 有值，該站的遊戲商 IP 白名單（`vendors.whitelist`）另有 6 個 IP。
     */
    private function isSignatureValid(Request $request): bool
    {
        $secret = [REDACTED]'SECRET'] ?? '';
        if ($secret === '') {
            Log::warning($this->actionPrefix . 'secret_not_configured', [
                'vendor_info_id' => $this->vendorInfo->id,
                'reason' => 'info.SECRET 未設定，回調一律拒收；請補上該站商戶設定',
            ]);

            return false;
        }

        $authorization = $request->header('Authorization', '');
        if (!str_contains($authorization, 'RG-HMAC-SHA256')) {
            return false;
        }

        preg_match('/Signature=([a-f0-9]+)/i', $authorization, $matches);
        $receivedSignature = $matches[1] ?? '';
        if ($receivedSignature === '') {
            return false;
        }

        $host = $request->header('Host', '');
        $xContentSha256 = $request->header('X-Content-SHA256', '');
        $xDate = $request->header('X-Date', '');

        $expectedSignature = hash_hmac('sha256', $host . $xContentSha256 . $xDate, $secret);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    private function successResponse(string $balance): JsonResponse
    {
        return response()->json([
            'id' => $this->account->username,
            'balance' => (float) $balance,
            'currency' => $this->vendorInfo->currency,
        ]);
    }

    protected function errorResponse(int $code, string $message, mixed $error = null): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];
        if ($error !== null) {
            $body['error'] = $error;
        }

        return response()->json($body);
    }
}
