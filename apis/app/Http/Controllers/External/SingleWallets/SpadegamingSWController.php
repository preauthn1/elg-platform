<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\IpNotAllowedException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\SignatureVerificationException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\RendersCallbackErrors;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldResolveVendorAccount;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyIpWhitelist;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyRequestSignature;
use App\Http\Controllers\External\SingleWallets\Data\AccountIdentifier;
use App\Http\Requests\External\SpadegamingSWRequest;
use App\Models\Game;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use App\Vendors\Models\SpadegamingswAccount;
use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\ReturnStatusEnum;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use App\Vendors\SpadegamingSW\TransferDataDTO;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 前置檢查全部交給 BaseController::callAction() 依 marker 執行：IP 白名單 → 解析帳號／會員／商戶
 * （驗商戶歸屬，不驗帳號與商戶 status：停用後 payout／cancel／getBalance 仍要通）→ 比對 digest。
 * 例外由 renderCallbackError() 翻成 SG 錯誤碼。帳號與商戶停用只擋 placeBet，由 verifyBettability() 以 BetException 擋下，
 * entrance() 翻成 ACCT_SUSPEND。
 *
 * 路由方法一律 public 並掛 #[Endpoint]，其餘方法 private。
 *
 * @knowledge rules/vendor-spadegamingsw
 */
#[Group('SpadegamingSW 回調')]
class SpadegamingSWController extends BaseController implements RendersCallbackErrors, ShouldResolveVendorAccount, ShouldVerifyGameBettability, ShouldVerifyIpWhitelist, ShouldVerifyRequestSignature
{
    protected int $vendorId = VendorIdEnum::SPADEGAMINGSW->value;

    protected string $accountClass = SpadegamingswAccount::class;

    protected string $betRecordClass = SpadegamingswBetRecord::class;

    protected string $actionPrefix = 'SpadeGamingSw_request_';

    protected string $vendorInfoOperatorField = 'operator_id';

    /**
     * 前置檢查與 action 內漏出的例外一律由這裡翻成 SG（Spadegaming）的錯誤碼。
     */
    public function renderCallbackError(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof IpNotAllowedException => $this->errorResponse(ReturnStatusEnum::INVALID_REQUEST, $e->getMessage()),
            $e instanceof SignatureVerificationException => $this->errorResponse(ReturnStatusEnum::INVALID_REQUEST, 'Digest verify failed'),
            $e instanceof MemberNotFoundException => $this->errorResponse(ReturnStatusEnum::ACCT_INACTIVE, 'Account inactive'),
            $e instanceof VendorAccountException => $this->errorResponse(ReturnStatusEnum::ACCT_NOT_FOUND, 'Account not found'),
            $e instanceof VendorNotFoundException => $this->errorResponse(ReturnStatusEnum::MERCHANT_NOT_FOUND, 'Merchant Not Found'),
            default => $this->systemErrorResponse($e),
        };
    }

    public function accountIdentifier(Request $request, string $method): AccountIdentifier
    {
        return AccountIdentifier::username((string) $request->input('acctId', ''));
    }

    /**
     * 驗 digest：md5(正規化後的 request body . Security_key)。
     *
     * body 先解碼再編碼，避開特殊字元造成的字面差異；算法與 SG 端一致，不可更動。
     * Security_key 住在 vendor_info，所以這一步必須排在帳號與商戶解析之後。
     */
    public function isRequestSignatureValid(Request $request): bool
    {
        $secKey = $this->vendorInfo->info['Security_key'] ?? '';
        if (empty($secKey)) {
            return false;
        }

        $requestContent = json_encode(json_decode($request->getContent()));

        return ($request->header('digest') ?? '') === md5($requestContent . $secKey);
    }

    private function errorResponse(ReturnStatusEnum $code, string $msg): JsonResponse
    {
        return response()->json([
            'code' => $code->value,
            'msg' => $msg,
        ]);
    }

    private function systemErrorResponse(Throwable $e): JsonResponse
    {
        Log::error($this->actionPrefix . 'error', [
            'message' => $e->getMessage(),
            'trace' => Common::formatTrace($e),
        ]);

        return $this->errorResponse(ReturnStatusEnum::SYSTEM_ERROR, $e->getMessage());
    }

    /**
     * SG 所有回調共用的單一入口，依 header `api` 分派：getBalance 查餘額、transfer 依 body 的 type
     * 分成下注、取消、派彩、紅利。
     */
    #[Endpoint(title: '回調入口', operationId: 'spadegamingsw.entrance')]
    public function entrance(SpadegamingSWRequest $request): JsonResponse
    {
        try {
            $responseData = [
                'code' => ReturnStatusEnum::SUCCESS->value,
                'msg' => 'success',
                'merchantCode' => $request->input('merchantCode'),
                'serialNo' => $request->input('serialNo'),
            ];

            $api = $request->header('api');
            switch ($api) {
                case 'getBalance':
                    $responseData['acctInfo'] = $this->getBalance($request);
                    break;
                case 'transfer':
                    $responseData = array_merge($responseData, $this->transfer($request));
                    break;
                default:
                    throw new Exception(
                        "API {$api} not found",
                        ReturnStatusEnum::SERVICE_INACCESSIBLE->value
                    );
            }
        } catch (BetException $e) {
            $responseData['code'] = ReturnStatusEnum::ACCT_SUSPEND->value;
            $responseData['msg'] = 'Account could not bet yet';
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            $responseData['code'] = ReturnStatusEnum::SYSTEM_ERROR->value;
            $responseData['msg'] = $info[2];
        } catch (Exception $e) {
            $code = ($e->getCode() !== 0) ? $e->getCode() : ReturnStatusEnum::SYSTEM_ERROR->value;
            $responseData['code'] = $code;
            $responseData['msg'] = $e->getMessage();
        }

        return response()->json($responseData);
    }

    private function getBalance(Request $request): array
    {
        $request->validate([
            'acctId' => 'required|string|max:50',
            'gameCode' => 'string|max:10',
        ]);

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return [
            'userName' => $this->account->username,
            'currency' => $this->vendorInfo->currency ?? '',
            'acctId' => $this->account->username,
            'balance' => (float) $balance,
        ];
    }

    private function transfer(Request $request): array
    {
        $validated = $request->validate([
            'transferId' => 'required|string|max:50',
            'acctId' => 'required|string|max:50',
            'currency' => 'required|string|max:3',
            'amount' => 'required|numeric',
            'type' => 'required|integer',
            'channel' => 'required|string|max:10',
            'gameCode' => 'required|string|max:10',
            'specialGame' => 'array',
            'transferTime' => 'required|string',
            'referenceId' => sprintf('string|max:50|required_if:type,%s,%s',
                TransferTypeEnum::CANCEL->value,
                TransferTypeEnum::PAYOUT->value
            ),
        ]);

        if ($validated['amount'] < 0) {
            throw new Exception('Amount Invalid', ReturnStatusEnum::AMOUNT_INVALID->value);
        }

        if (!Game::where('game_type', $validated['gameCode'])->exists()) {
            throw new Exception('Game not exist', ReturnStatusEnum::INVALID_REQUEST->value);
        }

        $validated['amount'] = Common::numDiv($validated['amount'], $this->vendorInfo->rate, 4);
        $dto = TransferDataDTO::from($validated);
        $infos = json_encode($validated);

        return DB::transaction(function () use ($dto, $infos) {
            return match ($dto->type) {
                TransferTypeEnum::PLACE_BET->value => $this->placeBet($dto, $infos),
                TransferTypeEnum::CANCEL->value => $this->cancel($dto, $infos),
                TransferTypeEnum::PAYOUT->value => $this->payout($dto, $infos),
                TransferTypeEnum::BONUS->value => $this->bonus($dto, $infos),
                default => throw new Exception(
                    "Transfer type {$dto->type} not available",
                    ReturnStatusEnum::SERVICE_INACCESSIBLE->value
                ),
            };
        });
    }

    private function placeBet(TransferDataDTO $dto, string $infos): array
    {
        // 先鎖會員列再做任何一般讀：verifyBettability 會查表，放在鎖前會把 RR 快照定在等鎖之前（target/tickets#4297）
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        $this->verifyBettability($dto->gameCode);

        if (SpadegamingswBetRecord::where('bet_id', $dto->transferId)->exists()) {
            return $this->buildDuplicateResponse($dto, $walletDto->getTotalBalance());
        }

        if (Common::numCompare(Common::numSub($walletDto->getTotalBalance(), $dto->amount), '0') < 0) {
            throw new Exception('Wallet balance not enough', ReturnStatusEnum::INSUFFICIENT_BALANCE->value);
        }

        $betRecord = new SpadegamingswBetRecord([
            'bet_id' => $dto->transferId,
            'username' => $this->account->username,
            'game_code' => $dto->gameCode,
            'type' => TransferTypeEnum::PLACE_BET,
            'infos' => $infos,
            'bet' => $dto->amount,
            'valid_bet' => $dto->amount,
            'win_lose' => Common::numMul($dto->amount, -1, 4),
            'rolling_chips' => 0,
            'bet_time' => $dto->transferTime,
            'payout_time' => $dto->transferTime,
        ]);

        $this->changeBalance($this->member, $walletDto, null, $betRecord);

        return $this->buildTransferResponse($dto, $walletDto->getTotalBalance());
    }

    private function payout(TransferDataDTO $dto, string $infos): array
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        if (SpadegamingswBetRecord::where('bet_id', $dto->transferId)->exists()) {
            return $this->buildDuplicateResponse($dto, $walletDto->getTotalBalance());
        }

        $placeBetRecord = SpadegamingswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->referenceId)
            ->where('type', TransferTypeEnum::PLACE_BET)
            ->first();

        if (!$placeBetRecord) {
            throw new Exception('Related id not found', ReturnStatusEnum::RELATED_ID_NOT_FOUND->value);
        }

        $payoutType = (!empty($dto->specialGame) && (int) ($dto->specialGame['sequence'] ?? 0) !== 0)
            ? TransferTypeEnum::PAYOUT_SPECIAL_GAME
            : TransferTypeEnum::PAYOUT;

        $payoutRecord = new SpadegamingswBetRecord([
            'bet_id' => $dto->transferId,
            'reference_bet_id' => $dto->referenceId,
            'username' => $this->account->username,
            'game_code' => $dto->gameCode,
            'type' => $payoutType,
            'infos' => $infos,
            'bet' => 0,
            'valid_bet' => 0,
            'win_lose' => $dto->amount,
            'rolling_chips' => 0,
            'bet_time' => $dto->transferTime,
            'payout_time' => $dto->transferTime,
        ]);

        $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $payoutRecord);

        // 輸錢（派彩 0）時真金沒有淨變動，changeBalance 不會建交易紀錄；而本家的派彩與下注是兩筆
        // 獨立注單，派彩那筆的投注恆為 0，changeBalance 內部那道以「本筆投注額」為條件的補救也不會
        // 觸發，於是 total_bet_record 關聯不到任何 transaction_log，該注單永遠不出現在 SR14 現金紀錄
        // detail。此處改以下注那一筆的真金投注判斷，取回本回合進行中的交易紀錄。
        // 全禮碼投注（真金投注為 0）刻意不補：該回合不涉及現金，掛上去會被歸到同 session 其他回合的
        // 現金紀錄底下。
        // ⚠️ 這是交易內的一般讀，看的是 getMemberLockedWallets() 第一次一般讀所定的 RR 快照。
        // 同會員另一筆投注若在本交易等鎖期間才提交，這裡看不到它剛建的進行中紀錄，補救會靜默
        // 失效（退回未修正前的漏單行為，不會算錯錢）。不改為鎖定讀：這裡只是補關聯，為它多拿一
        // 把鎖反而擴大 payout 的鎖範圍（target/tickets#4297、target/op_tickets#3075 同型）。
        if ($transactionLogId === 0) {
            $placeBetCashAmount = Common::numSub($placeBetRecord->getBet(), $placeBetRecord->getRollingChip(), 4);
            if (Common::numCompare($placeBetCashAmount, 0) > 0) {
                $transactionLogId = (new TransactionLogCreateService($this->member, $this->vendorInfo, $walletDto->isMultipleWallet, $this->account))
                    ->getGamingTransactionLog()?->id ?? 0;
            }
        }

        if (preg_match('/^C-/', $dto->gameCode)) {
            $totalWinLose = Common::numAdd($placeBetRecord->win_lose, $payoutRecord->win_lose, 4);
            $placeBetRecord->valid_bet = Common::numCompare($totalWinLose, '0') > 0
                ? '0'
                : Common::numMul($totalWinLose, -1, 4);
            $placeBetRecord->save();
        }

        $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
        if ($payoutType === TransferTypeEnum::PAYOUT_SPECIAL_GAME) {
            $totalBetRecordService->create($payoutRecord, $transactionLogId);
        } else {
            $totalBetRecordService->createOrUpdate($placeBetRecord, $payoutRecord, $transactionLogId, 'spadegamingSW');
        }

        return $this->buildTransferResponse($dto, $walletDto->getTotalBalance());
    }

    private function cancel(TransferDataDTO $dto, string $infos): array
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        if (SpadegamingswBetRecord::where('bet_id', $dto->transferId)->exists()) {
            return $this->buildDuplicateResponse($dto, $walletDto->getTotalBalance());
        }

        $placeBetRecord = SpadegamingswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->referenceId)
            ->where('type', TransferTypeEnum::PLACE_BET)
            ->first();

        if (!$placeBetRecord) {
            throw new Exception('Related id not found', ReturnStatusEnum::RELATED_ID_NOT_FOUND->value);
        }

        if ($this->isBetAlreadyCanceled((string) $dto->referenceId)) {
            Log::warning($this->actionPrefix . 'cancel_already_canceled', [
                'transferId' => $dto->transferId,
                'referenceId' => $dto->referenceId,
            ]);

            return $this->buildDuplicateResponse($dto, $walletDto->getTotalBalance());
        }

        $rollingChipRefund = $placeBetRecord->rolling_chips;
        $cancelRecord = new SpadegamingswBetRecord([
            'bet_id' => $dto->transferId,
            'reference_bet_id' => $dto->referenceId,
            'username' => $this->account->username,
            'game_code' => $dto->gameCode,
            'type' => TransferTypeEnum::CANCEL,
            'infos' => $infos,
            'bet' => 0,
            'valid_bet' => 0,
            'win_lose' => '0',
            'rolling_chips' => $rollingChipRefund,
            'bet_time' => $dto->transferTime,
            'payout_time' => $dto->transferTime,
        ]);

        $this->changeBalance($this->member, $walletDto, $placeBetRecord, $cancelRecord, autoSave: false);

        $cancelRecord->win_lose = Common::numMul($placeBetRecord->win_lose, -1, 4);
        $cancelRecord->rolling_chips = Common::numMul($rollingChipRefund, -1, 4);
        $cancelRecord->save();

        return $this->buildTransferResponse($dto, $walletDto->getTotalBalance());
    }

    private function bonus(TransferDataDTO $dto, string $infos): array
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

        if (SpadegamingswBetRecord::where('bet_id', $dto->transferId)->exists()) {
            return $this->buildDuplicateResponse($dto, $walletDto->getTotalBalance());
        }

        $bonusRecord = new SpadegamingswBetRecord([
            'bet_id' => $dto->transferId,
            'reference_bet_id' => $dto->transferId,
            'username' => $this->account->username,
            'game_code' => $dto->gameCode,
            'type' => TransferTypeEnum::BONUS,
            'infos' => $infos,
            'bet' => 0,
            'valid_bet' => 0,
            'win_lose' => $dto->amount,
            'rolling_chips' => 0,
            'bet_time' => $dto->transferTime,
            'payout_time' => $dto->transferTime,
        ]);

        $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $bonusRecord);

        $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
        $totalBetRecordService->create($bonusRecord, $transactionLogId);

        return $this->buildTransferResponse($dto, $walletDto->getTotalBalance());
    }

    /**
     * 這筆下注是否已被取消過。取消投注紀錄以 reference_bet_id 指回原下注，所以同一筆下注只要已有
     * CANCEL 列，就是已取消。
     *
     * 冪等鍵取原下注（referenceId），而不是取消投注請求自己的 transferId。SG（Spadegaming）沒收到
     * placeBet 的回應（逾時）時會重發 cancel，每次帶新的 transferId（docs/integrations/vendors/SpadegamingSW/api.md
     * 的「Type 對照」與「文件規則」，對應 SG 官方文件 4.3.2 節）。只看 transferId 的檢查會一路放行，
     * changeBalance 便以「原下注 bet → 0」的差額把真金與禮碼再退一次（target/op_tickets#3098 事故：
     * TPower 9 筆超退 1,321.00、Yes2Win 1 筆 4.00）。
     *
     * 這道閘門採鎖定讀（lockForUpdate），讀的是最新已提交的資料。一般讀讀的是交易內第一次一般讀那一刻的
     * REPEATABLE READ 快照：交易進來後、取得會員鎖之前只要有一般讀，快照就定在等鎖之前，拿到鎖之後的
     * 一般讀看不到併發的另一筆 cancel 剛 commit 的 CANCEL 列。target/op_tickets#3098 事故即此：當時
     * WalletService::getMemberLockedWallets() 先做一般讀（lazy load $member->agent）才等會員列鎖，
     * TPower 那 9 筆重複的取消投注中有 6 筆 created_at 落在同一秒。同型事故也發生在 PegasusSW
     * （target/op_tickets#3075、commit 8c86a78e1，回歸測試
     * tests/Feature/External/SingleWallets/PegasusSW/StaleSnapshotReadTest.php）。
     *
     * ⚠️ **這把 lockForUpdate 是否仍必要屬未決**，尚未有人驗證後移除。getMemberLockedWallets() 的第一個 SQL
     * 是 Member::lockForUpdate()（target/tickets#4294），快照在拿到會員鎖之後才建立，交易內的一般讀讀得到
     * 現值；同一筆下注必屬同一會員，會員鎖已序列化該會員的所有 cancel 請求。現況維持鎖定讀（代價見下，
     * 實測接近零）；要移除前必須先確認呼叫端進交易後、取得會員鎖之前確實沒有任何一般讀。
     *
     * 代價：reference_bet_id 沒有索引，FOR UPDATE 沿 type 索引取 next-key lock，等於把全站 SG 的取消投注序列化。
     * 實測 TPower type=2 僅 168 列（全表約 3,259 萬列），這個代價接近零；下注（type=1）與派彩（type=4／5）
     * 的 INSERT 落在索引的其他區段，不會被這把鎖擋住。要收斂鎖範圍須補 (type, reference_bet_id) 索引，
     * 屬大表 DDL，另案排期。
     */
    private function isBetAlreadyCanceled(string $referenceId): bool
    {
        return SpadegamingswBetRecord::query()
            ->where('type', TransferTypeEnum::CANCEL)
            ->where('reference_bet_id', $referenceId)
            ->lockForUpdate()
            ->first() !== null;
    }

    private function buildDuplicateResponse(TransferDataDTO $dto, string $balance): array
    {
        return $this->buildTransferResponse($dto, $balance);
    }

    private function buildTransferResponse(TransferDataDTO $dto, string $balance): array
    {
        return [
            'transferId' => $dto->transferId,
            'merchantTxId' => time() . rand(10, 99),
            'acctId' => $dto->acctId,
            'balance' => (float) Common::numMul($balance, $this->vendorInfo->rate, 4),
        ];
    }
}
