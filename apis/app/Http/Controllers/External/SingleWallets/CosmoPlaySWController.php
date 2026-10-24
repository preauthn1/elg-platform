<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\CosmoPlaySWAccount;
use App\Vendors\Models\CosmoPlaySWBetRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CosmoPlay Single Wallet（Seamless 模式）回調 Controller
 *
 * 路由前綴：/cosmoplay
 * 對應文件：Game Server Integration Guide v2.6（Seamless mode）
 *
 * 資安：所有請求須攜帶有效 JWT（HS256），由 verifyJwt() 驗證。
 * 金額：CosmoPlay 以整數乘以 100 表示，DecimalPlace=2。
 *       收到 BetAmount / WinAmount → 除以 vendorInfo->rate（100）轉為內部金額。
 *       回傳 Balance → 乘以 rate 後取整數（floor）。
 */
class CosmoPlaySWController extends BaseController
{
    protected int $vendorId = VendorIdEnum::COSMOPLAYSW->value;

    protected string $accountClass = CosmoPlaySWAccount::class;

    protected string $betRecordClass = CosmoPlaySWBetRecord::class;

    protected string $vendorInfoOperatorField = 'PARTNER_CODE';

    protected string $actionPrefix = 'CosmoPlaySW_';

    public function callAction($method, $parameters)
    {
        try {
            $request = request();
            $this->logRequest($request, $this->actionPrefix . basename(url()->current()));

            // IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                return $this->errorResponse(7, 'IP Not Allowed');
            }

            // 解析 PlayerID（格式：partner_code-username，僅允許一個 "-"）
            $playerId = (string) $request->input('PlayerID', '');
            [$partnerCode, $username] = $this->parsePlayerId($playerId);

            if (empty($username)) {
                return $this->errorResponse(3, 'Invalid PlayerID format');
            }

            // 依 partnerCode 查找 VendorInfo（取得 JWT_SECRET 供驗簽使用）
            $this->vendorInfo = $this->getVendorInfo($partnerCode);
            if (!$this->vendorInfo) {
                return $this->errorResponse(19, 'Partner Not Found');
            }

            // JWT 驗證：僅使用該 VendorInfo 對應的 JWT_SECRET，並驗證 claims 與請求內容一致
            if (!$this->verifyJwt($request, $this->vendorInfo)) {
                return $this->errorResponse(16, 'Unauthorized');
            }

            // 依 username 查找會員
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                return $this->errorResponse(5, 'Player Not Found');
            }

            return parent::callAction($method, $parameters);
        } catch (\Throwable $e) {
            Log::error($this->actionPrefix . 'callAction_error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return $this->errorResponse(13, 'Internal Server Error');
        }
    }

    /**
     * Authentication API hook（/auth-check）
     * 驗證玩家 AuthToken + GameID，回傳餘額供遊戲顯示。
     */
    public function authCheck(Request $request): JsonResponse
    {
        $authToken = [REDACTED] $request->input('AuthToken', '');
        $gameId = (string) $request->input('GameID', '');

        if (empty($authToken) || empty($gameId)) {
            return $this->errorResponse(3, 'Missing AuthToken or GameID');
        }

        // 驗證 AuthToken
        if ($this->account->session_token !== $authToken) {
            return $this->errorResponse(16, 'Invalid AuthToken');
        }

        $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

        return response()->json([
            'GameID' => $gameId,
            'IsWalletIntegrated' => false,
            'Balance' => $this->toVendorBalance($balance),
            'DecimalPlace' => 2,
        ]);
    }

    /**
     * Bet Result API hook（/bet-result）
     * 玩家投注：扣除 BetAmount 並建立注單記錄（status=0）。
     */
    public function betResult(Request $request): JsonResponse
    {
        $params = $request->validate([
            'SpinID' => ['required', 'string', 'max:50'],
            'RoundID' => ['required', 'string', 'max:50'],
            'GameID' => ['required', 'string', 'max:50'],
            'BetAmount' => ['required', 'integer', 'min:0'],
        ]);

        if ($this->account->status == 0) {
            return $this->errorResponse(7, 'Player Inactive');
        }

        $spinId = $params['SpinID'];
        $roundId = $params['RoundID'];
        $gameId = $params['GameID'];
        $bet = Common::numDiv(Common::numDiv((string) $params['BetAmount'], '100', 4), (string) $this->vendorInfo->rate, 4);
        $username = $this->account->username;

        if (!BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $gameId)) {
            return $this->errorResponse(7, 'Player Not Allowed To Bet');
        }

        try {
            return DB::transaction(function () use ($spinId, $roundId, $gameId, $bet, $username, $request) {
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
                $balance = $walletDto->getTotalBalance();

                // 冪等：SpinID 已存在且已處理，直接回傳餘額
                // ⚠️ 不可加 lockForUpdate()：bet_id 為 UNIQUE，對不存在的值做鎖定讀會取 gap lock，
                // 併發的不同玩家都落在同一個 gap，後續 INSERT 的 insert intention lock 互等即成死鎖（1213）。
                // 真正的併發防線是 UNIQUE index：撞號者拋 1062，由下方 catch 整筆 rollback 後回傳餘額。
                $existing = $this->betRecordClass::query()->where('bet_id', $spinId)->first();
                if ($existing) {
                    return response()->json(['Balance' => $this->toVendorBalance($walletDto->getTotalBalance())]);
                }

                // 餘額不足：完全不足（balance<=0 或 balance<bet）→ code 17；可降低注額繼續時帶餘額
                if (Common::numCompare($balance, $bet) < 0) {
                    if (Common::numCompare($balance, '0') <= 0) {
                        return $this->errorResponse(17, 'Not Enough Balance');
                    }

                    return response()->json([
                        'Balance' => $this->toVendorBalance($balance),
                        'code' => 17,
                        'message' => 'Not Enough Balance',
                    ]);
                }

                $betRecord = new $this->betRecordClass([
                    'vendor_info_id' => $this->vendorInfo->id,
                    'member_id' => $this->member->id,
                    'bet_id' => $spinId,
                    'round_id' => $roundId,
                    'username' => $username,
                    'game_code' => $gameId,
                    'bet' => $bet,
                    'valid_bet' => $bet,
                    'win' => 0,
                    'win_lose' => Common::numSub('0', $bet, 4),
                    'rolling_chip' => 0,
                    'is_round_end' => 0,
                    'status' => 0,
                    'bet_time' => Carbon::now(),
                    'payout_time' => '1970-01-01 00:00:01',
                    'info' => '[]',
                ]);
                $betRecord->info = $this->appendParamsToInfo($betRecord->info, $request->all());

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $betRecord);

                $betRecord->save();

                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                return response()->json(['Balance' => $this->toVendorBalance($walletDto->getTotalBalance())]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            // 競態條件下的重複 SpinID：交易已整筆回滾，回傳當前餘額（冪等）
            $balance = (new WalletService)->getMemberWallets($this->member, $this->vendorInfo)->getTotalBalance();

            return response()->json(['Balance' => $this->toVendorBalance($balance)]);
        }
    }

    /**
     * Win Result API hook（/win-result）
     * 派彩：加入 WinAmount，更新注單狀態為 settled（status=1）。
     * WinAmount=0 表示玩家輸，仍需結算。
     */
    public function winResult(Request $request): JsonResponse
    {
        $params = $request->validate([
            'SpinID' => ['required', 'string', 'max:50'],
            'RoundID' => ['required', 'string', 'max:50'],
            'GameID' => ['required', 'string', 'max:50'],
            'IsRoundEnd' => ['required', 'boolean'],
            'WinAmount' => ['required', 'integer', 'min:0'],
        ]);

        $spinId = $params['SpinID'];
        $win = Common::numDiv(Common::numDiv((string) $params['WinAmount'], '100', 4), (string) $this->vendorInfo->rate, 4);
        $isRoundEnd = (bool) $params['IsRoundEnd'];

        return DB::transaction(function () use ($spinId, $win, $isRoundEnd, $request) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            $betRecord = $this->betRecordClass::query()->where('bet_id', $spinId)->lockForUpdate()->first();
            if (!$betRecord) {
                return $this->errorResponse(5, 'Bet Record Not Found');
            }

            // 冪等：已結算，直接回傳餘額
            if ($betRecord->status == 1) {
                return response()->json(['Balance' => $this->toVendorBalance($walletDto->getTotalBalance())]);
            }

            // 已取消的注單不允許派彩
            if ($betRecord->status == 2) {
                return $this->errorResponse(5, 'Bet Record Already Cancelled');
            }

            $oldRecord = clone $betRecord;
            $betRecord->win = $win;
            $betRecord->win_lose = Common::numSub($win, $betRecord->bet, 4);
            $betRecord->is_round_end = $isRoundEnd ? 1 : 0;
            $betRecord->payout_time = Carbon::now();
            $betRecord->status = 1;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $request->all());

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldRecord, $betRecord);

            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            return response()->json(['Balance' => $this->toVendorBalance($walletDto->getTotalBalance())]);
        });
    }

    /**
     * Cancel Result API hook（/cancel-result）
     * 回滾投注：退還 BetAmount，更新注單狀態為 cancelled（status=2）。
     * 觸發時機：bet-result 多次重試失敗後，或 win-result 含 FreeSpin 發生錯誤時。
     */
    public function cancelResult(Request $request): JsonResponse
    {
        $params = $request->validate([
            'SpinID' => ['required', 'string', 'max:50'],
            'RoundID' => ['required', 'string', 'max:50'],
            'GameID' => ['required', 'string', 'max:50'],
        ]);

        $spinId = $params['SpinID'];

        return DB::transaction(function () use ($spinId, $request) {
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

            $betRecord = $this->betRecordClass::query()->where('bet_id', $spinId)->lockForUpdate()->first();

            // 注單不存在或已取消 → 冪等成功
            if (!$betRecord || $betRecord->status == 2) {
                return response()->json(['Balance' => $this->toVendorBalance($walletDto->getTotalBalance())]);
            }

            // 已結算的注單不允許取消
            if ($betRecord->status == 1) {
                return $this->errorResponse(9, 'Bet Already Settled');
            }

            $oldRecord = clone $betRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->win = 0;
            $betRecord->win_lose = 0;
            $betRecord->payout_time = Carbon::now();
            $betRecord->status = 2;
            $betRecord->info = $this->appendParamsToInfo($betRecord->info, $request->all());

            $this->changeBalance($this->member, $walletDto, $oldRecord, $betRecord);

            $betRecord->save();

            return response()->json(['Balance' => $this->toVendorBalance($walletDto->getTotalBalance())]);
        });
    }

    /**
     * 將內部餘額（4 位小數）轉換為 CosmoPlay 整數格式（×100，floor 取整）
     */
    private function toVendorBalance(string $balance): int
    {
        return (int) Common::numFloor(Common::numMul(Common::numMul($balance, '100', 4), (string) $this->vendorInfo->rate, 4), 0);
    }

    /**
     * 驗證請求中的 JWT（AuthorizationV2: Bearer {token}）
     * CosmoPlay 使用 HS256，secret 存於 vendorInfo->info['JWT_SECRET']
     * 規格要求 claims 須由完整 request payload 產生，因此同時驗證 claims 與實際請求內容一致，避免 JWT 被重放到其他請求上
     *
     * 實測 CosmoPlay 請求中標準 Authorization header 會被中間 gateway 改寫成不相干的內部 token，
     * 真正符合規格（claims = request payload）的 JWT 實際帶在 AuthorizationV2 header。
     *
     * 實測 CosmoPlay 部分請求（如 bet-result）會在 URL 帶 cache-busting 用的 query string（如 nocache），
     * 但簽 JWT 時的 payload 僅限 JSON body，因此比對須排除 query string，改用 $request->json()->all()
     * 而非 $request->all()（後者會合併 query string，導致誤判 claims 不一致）。
     */
    private function verifyJwt(Request $request, VendorInfo $vendorInfo): bool
    {
        $authorization = $request->header('AuthorizationV2', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return false;
        }

        $token = [REDACTED], 7);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $secret = [REDACTED]'JWT_SECRET'] ?? '';
        if (empty($secret)) {
            return false;
        }

        if (!$this->verifyHs256($parts[0] . '.' . $parts[1], $parts[2], $secret)) {
            return false;
        }

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);

        return is_array($claims) && $claims == $request->json()->all();
    }

    private function verifyHs256(string $signingInput, string $signature, string $secret): bool
    {
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, $secret, true)), '+/', '-_'), '=');

        return hash_equals($expected, $signature);
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * 解析 PlayerID（格式：partner_code-username，僅允許一個 "-"）
     * 回傳 [partnerCode, username]
     */
    private function parsePlayerId(string $playerId): array
    {
        $pos = strpos($playerId, '-');
        if ($pos === false) {
            return ['', ''];
        }

        return [
            substr($playerId, 0, $pos),
            substr($playerId, $pos + 1),
        ];
    }

    /**
     * 依 CosmoPlay 規格的 Error Handling 表，將內部錯誤碼對應為文件規定的 HTTP Status
     */
    protected function errorResponse(int $code, string $message = ''): JsonResponse
    {
        $httpStatus = match ($code) {
            1 => 408,
            2, 13, 15 => 500,
            3, 9, 11 => 400,
            4 => 504,
            5 => 404,
            6, 10 => 409,
            7 => 403,
            8 => 429,
            12 => 501,
            14, 18 => 503,
            16, 19 => 401,
            17 => 200,
            default => 200,
        };

        return response()->json([
            'code' => $code,
            'message' => $message,
        ], $httpStatus);
    }
}
