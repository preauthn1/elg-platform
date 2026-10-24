<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\AFBSW\BetRecordStatusEnum;
use App\Vendors\Models\AfbswAccount;
use App\Vendors\Models\AfbswBetRecord;
use ErrorException;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * AFB
 *
 * @knowledge rules/vendor-afbsw
 */
class AFBSwController extends BaseController
{
    protected int $vendorId = VendorIdEnum::AFBSW->value;

    protected string $vendorInfoOperatorField = 'SITE_ID';

    protected string $actionPrefix = 'AFBSW_request_';

    protected string $accountClass = AfbswAccount::class;

    protected string $betRecordClass = AfbswBetRecord::class;

    public function __construct(private WalletService $walletService) {}

    /**
     * 單一入口
     */
    public function __invoke(Request $request)
    {
        try {

            $this->_validateRequest($request);

            $operatorId = $request['sid'] ?? '';
            $username = $request['username'] ?? '';

            $action = $this->actionPrefix . basename(url()->current());

            $this->logRequest($request, $action);

            // 驗證 IP 白名單
            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new ErrorException('IP not in whitelist', 1);
            }

            // 取得用戶
            $this->member = $this->getMemberByThirdPartyUsername($username);
            if (!$this->member) {
                throw new ErrorException('member not found', 1);
            }

            // 取得商戶
            $this->vendorInfo = $this->getVendorInfo($operatorId, $this->account);
            if (!$this->vendorInfo) {
                throw new ErrorException('vendor info not found', 1);
            }

            // 驗證三方憑證（IP 白名單之外的第二道）
            if (!$this->isApiKeyValid($request)) {
                throw new ErrorException('Invalid API key', 1);
            }

            $method = $request['method'];

            return $this->{$method}($request);

        } catch (Throwable $e) {
            // 統一錯誤處理
            Log::emergency($this->actionPrefix . 'error: ' . $e->getMessage());

            return response()->json([
                'status' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 驗證回調請求帶的 `key` 與遊戲商戶配置的 API_KEY 相同（以 hash_equals 比對）。
     *
     * AFB 的無縫錢包回調沒有簽名機制：以密鑰計算的驗證碼（HMAC）只用在我方主動呼叫 AFB 的方向
     * （docs/integrations/vendors/AFBSW/api.md「驗證方式」節）。IP 白名單擋不住白名單內來源重放
     * 先前的請求。這條路徑會直接異動玩家錢包，所以另外比對 `key`，當第二道防線。`key` 擋的是
     * 不知道金鑰的來源，擋不住帶著同一個 `key` 的重放。
     *
     * 請求本文（body）的 `key` 與 `vendor_infos.info.API_KEY` 是同一個值：TPower 與 Alfawin77
     * 各取最近 500 筆注單，比對 info 欄位裡的回調原文，1000／1000 相符、零筆缺漏。
     *
     * 🔴 API_KEY 未設定時一律拒收，禁止放行。未設定就放行的話，「設定漏填」等於無聲地拆掉
     * 這道防線。本遊戲商的 IP 白名單（`vendors.whitelist`）在 TPower 與 Alfawin77 兩站皆為空，
     * API_KEY 未設定時就是 API Key 與 IP 白名單兩道都沒有。
     *
     * 拒收不影響現有站點：實查五站（TPower／VWorld／Yes2Win／Alfawin77／Me77），實際接入的兩個
     * 遊戲商戶（TPower `vendor_infos.id` = 491、Alfawin77 `vendor_infos.id` = 67）API_KEY 皆有值。
     */
    private function isApiKeyValid(Request $request): bool
    {
        $apiKey = [REDACTED] ($this->vendorInfo->info['API_KEY'] ?? '');
        if ($apiKey === '') {
            Log::warning($this->actionPrefix . 'api_key_not_configured', [
                'vendor_info_id' => $this->vendorInfo->id,
                'reason' => 'info.API_KEY 未設定，回調一律拒收；請補上該站商戶設定',
            ]);

            return false;
        }

        return hash_equals($apiKey, (string) $request->input('key', ''));
    }

    private function _validateRequest(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'sid' => 'required|string',
            'method' => 'required|string|in:getBalance,bet,paid,betPaid,refund',
            'username' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ErrorException($validator->errors()->first(), 1);
        }

        return $validator->validated();
    }

    public function getBalance()
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $balance = $walletDto->getTotalBalance();

        $vendorBalance = (float) Common::numMul($balance, $this->vendorInfo->rate, 4);

        return response()->json([
            'status' => 0,
            'message' => 'success',
            'balance' => $vendorBalance,
        ]);
    }

    public function bet(Request $request)
    {
        $params = $request->all();

        // 交易外檢查：isMemberAbleToBet 會查表，若放在交易內、會員鎖之前，RR 快照就定在等鎖前（target/tickets#4297）
        $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) $params['gameCode']);
        if (!$ableToBet) {
            throw new ErrorException('Account could not bet yet', 1);
        }

        return DB::transaction(function () use ($params) {
            $betId = $params['transactionId'];
            $amount = (float) Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);
            // 遊戲商說 transactionId 就是時間戳，可用來當作投注時間
            $timestamp = substr($betId, 0, 10);
            $createdAt = date('Y-m-d H:i:s', (int) $timestamp);

            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if ($oldBetRecord !== null) {
                throw new Exception('Duplicate transaction', 1);
            }

            $betRecord = new $this->betRecordClass([
                'member_id' => $this->member->id,
                'username' => $params['username'],
                'bet_id' => $betId,
                'game_code' => $params['gameCode'],
                'bet' => $amount,
                'valid_bet' => $amount,
                'payout' => 0,
                'win_lose' => $amount,
                'rolling_chips' => 0,
                'bet_time' => $createdAt,
                'payout_time' => null,
                'status' => BetRecordStatusEnum::BET->value,
                'info' => [$params],
            ]);

            $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            $balance = $walletDto->getTotalBalance();
            $vendorBalance = (float) Common::numMul($balance, $this->vendorInfo->rate, 4);

            return response()->json([
                'status' => 0,
                'message' => 'success',
                'balance' => $vendorBalance,
            ]);
        });
    }

    public function paid(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $params = $request->all();

            $betId = $params['transactionId'];
            $realBet = (float) Common::numDiv($params['realBet'], $this->vendorInfo->rate, 4);
            $realPaid = (float) Common::numDiv($params['realPaid'], $this->vendorInfo->rate, 4);
            $createdAt = now();

            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                throw new Exception('Bet transaction not found', 1);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::PAID->value) {
                throw new Exception('Bet record already settled', 1);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::REFOUND->value) {
                throw new Exception('Bet record already refunded', 1);
            }

            $betRecord = clone $oldBetRecord;
            $betRecord->bet = $realBet;
            $betRecord->valid_bet = $realBet;
            $betRecord->payout = $realPaid;
            $betRecord->win_lose = Common::numSub($betRecord->payout, $betRecord->bet);
            $betRecord->payout_time = $createdAt;
            $betRecord->status = BetRecordStatusEnum::PAID->value;
            $betRecord->info = array_merge($betRecord->info, [$params]);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();
            $vendorBalance = (float) Common::numMul($balance, $this->vendorInfo->rate, 4);

            return response()->json([
                'status' => 0,
                'message' => 'success',
                'balance' => $vendorBalance,
            ]);
        });
    }

    public function refund(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $params = $request->all();

            $betId = $params['transactionId'];
            $amount = (float) Common::numDiv($params['amount'], $this->vendorInfo->rate, 4);
            $createdAt = now();

            // 取得用戶真金跟禮碼
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
            $balance = $walletDto->getTotalBalance();

            $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

            if (!$oldBetRecord) {
                throw new Exception('Bet transaction not found', 1);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::PAID->value) {
                throw new Exception('Bet record already settled', 1);
            }

            if ($oldBetRecord->status == BetRecordStatusEnum::REFOUND->value) {
                throw new Exception('Bet record already refunded', 1);
            }

            // AFB 規格（api.md §5.5）未定義部分退款；amount 與原注金不符時拒收，錢包與注單都不動，
            // 交由 AFB 重送或人工處理，避免靜默多退或少退（生產退款實例的 amount 全等於原注金）
            $placedBet = Common::numAdd('0', (string) $oldBetRecord->bet, 4);
            $refundAmount = Common::numAdd('0', (string) $amount, 4);
            if (Common::numCompare($refundAmount, $placedBet) !== 0) {
                Log::warning($this->actionPrefix . 'refund_amount_mismatch', [
                    'bet_id' => $betId,
                    'placed_bet' => $placedBet,
                    'refund_amount' => $refundAmount,
                ]);

                throw new Exception('Refund amount mismatch', 1);
            }

            // 取消投注：投注量歸零，退款注單不計入 TBR／SBR 的投注額（對齊 Ace333SW／Astar 等單一錢包）。
            // 錢包退款由 changeBalance() 以前後注單差額推得：真金部分回真金錢包、禮碼部分回禮碼錢包；
            // 不再把退款金額當派彩寫進 payout，否則投注額會留在報表與返利基數裡、禮碼投注也會被當真金退回。
            $betRecord = clone $oldBetRecord;
            $betRecord->bet = 0;
            $betRecord->valid_bet = 0;
            $betRecord->payout = 0;
            $betRecord->win_lose = 0;
            $betRecord->payout_time = $createdAt;
            $betRecord->status = BetRecordStatusEnum::REFOUND->value;
            $betRecord->info = array_merge($betRecord->info, [$params]);

            $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
            $betRecord->save();

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);

            $balance = $walletDto->getTotalBalance();
            $vendorBalance = (float) Common::numMul($balance, $this->vendorInfo->rate, 4);

            return response()->json([
                'status' => 0,
                'message' => 'success',
                'balance' => $vendorBalance,
            ]);
        });
    }
}
