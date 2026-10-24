<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Http\Requests\External\WcasinoSW as WcasinoSWRequest;
use App\Models\TotalBetRecord;
use App\Services\BotNotifyService;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\WcasinoswAccount;
use App\Vendors\Models\WcasinoswBetRecord;
use App\Vendors\Models\WcasinoswNotifyRecord;
use App\Vendors\Wcasino\Datas\BetInfoData;
use App\Vendors\Wcasino\Datas\VendorInfoDetailData;
use App\Vendors\Wcasino\Enums\BetRecordStatusEnum;
use App\Vendors\Wcasino\Enums\ErrorCodeEnum;
use App\Vendors\Wcasino\Enums\NotifyTypeEnum;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WcasinoSWController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::WCASINO->value;

    protected string $accountClass = WcasinoswAccount::class;

    protected string $betRecordClass = WcasinoswBetRecord::class;

    protected string $actionPrefix = 'Wcasino_SW_request_';

    protected string $vendorInfoOperatorField = 'CLIENT_ID';

    public function callAction($method, $parameters): mixed
    {
        try {
            $request = request();
            $this->setUpVendorAccount(thirdPartyUsername: $request->username);

            $this->logRequest($request, $this->actionPrefix . basename(url()->current()));

            if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
                throw new Exception(sprintf('IP %s not allow', Common::getIp()), ErrorCodeEnum::IP_NOT_ALLOW->value);
            }

            if (!$this->verifySign($request->all())) {
                throw new Exception('Request data verify failed', ErrorCodeEnum::SIGN_ERROR->value);
            }

            $response = parent::callAction($method, $parameters);
        } catch (VendorAccountException|MemberNotFoundException $e) {
            $response = $this->errorResponse(ErrorCodeEnum::USER_NOT_EXIST->value, $e->getMessage());
        } catch (VendorNotFoundException $e) {
            $response = $this->errorResponse(ErrorCodeEnum::INTERNAL_ERROR->value, $e->getMessage());
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . 'error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $response = $this->errorResponse($e->getCode(), $e->getMessage());
        } finally {
            return $response;
        }
    }

    /**
     * @throws VendorAccountException|MemberNotFoundException|VendorNotFoundException
     */
    public function balance(Request $request): JsonResponse
    {
        if (!BetHelper::isMemberFlowLimitAllowed($this->member, $this->vendorInfo->vendor_id)) {
            return $this->errorResponse(ErrorCodeEnum::USER_FORBIDDEN->value, 'Account could not bet yet');
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return response()->json([
            'result' => ErrorCodeEnum::SUCCESS->value,
            'desc' => 'OK',
            'balance' => $balance,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function bet(WcasinoSWRequest\BetRequest $request): JsonResponse
    {
        $dto = BetInfoData::from($request->all());

        $this->verifyBettability($dto->gamecode);

        $balance = $this->handleAction($dto, fn () => $this->executeBet($dto));

        return response()->json([
            'result' => ErrorCodeEnum::SUCCESS->value,
            'desc' => 'OK',
            'balance' => $balance,
            'orderno' => $request->notifyid,
        ]);
    }

    public function settle(WcasinoSWRequest\SettleRequest $request): JsonResponse
    {
        $dto = BetInfoData::from($request->all());

        $executor = match (true) {
            $dto->type === NotifyTypeEnum::CANCEL && $dto->amount >= 0 => fn () => $this->executeBetCancel($dto),
            $dto->type === NotifyTypeEnum::CANCEL && $dto->amount < 0 => fn () => $this->executeSettlementCancel($dto),
            $dto->type === NotifyTypeEnum::RESETTLE => fn () => $this->executeResettlement($dto),
            default => fn () => $this->executeSettlement($dto),
        };

        $balance = $this->handleAction($dto, $executor);

        return response()->json([
            'result' => ErrorCodeEnum::SUCCESS->value,
            'desc' => 'OK',
            'balance' => $balance,
            'orderno' => $request->notifyid,
        ]);
    }

    public function rollback(WcasinoSWRequest\RollbackRequest $request): JsonResponse
    {
        $dto = BetInfoData::from($request->all());

        $executor = match ($dto->type) {
            NotifyTypeEnum::BET => fn () => $this->executeBetCancel($dto),
            NotifyTypeEnum::CANCEL => fn () => $this->executeRollbackBetCancel($dto),
            NotifyTypeEnum::SETTLE => fn () => $this->executeSettlementCancel($dto),
            NotifyTypeEnum::RESETTLE => fn () => $this->executeRollbackResettlement($dto),
            default => throw new Exception("Invalid rollback type {$dto->type->value}", ErrorCodeEnum::INTERNAL_ERROR->value),
        };

        $balance = $this->handleRollback($dto, $executor);

        return response()->json([
            'result' => ErrorCodeEnum::SUCCESS->value,
            'desc' => 'OK',
            'balance' => $balance,
            'orderno' => $request->notifyid,
        ]);
    }

    // -------------------------------------------------------------------------
    // Business logic executors
    // -------------------------------------------------------------------------

    private function executeBet(BetInfoData $dto): string
    {
        $platformBet = Common::numMul(Common::numDiv($dto->amount, $this->vendorInfo->rate, 4), -1, 4);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        if (Common::numCompare(Common::numSub($walletDto->getTotalBalance(), $platformBet), '0') < 0) {
            throw new Exception('Wallet balance not enough', ErrorCodeEnum::INSUFFICIENT_BALANCE->value);
        }

        $betRecord = WcasinoswBetRecord::create([
            'bet_id' => $dto->serialnumber,
            'username' => $dto->username,
            'game_code' => $dto->gamecode,
            'status' => BetRecordStatusEnum::PENDING,
            'bet' => $platformBet,
            'valid_bet' => $platformBet,
            'win_lose' => Common::numMul($platformBet, -1, 4),
            'rolling_chips' => '0',
            'bet_time' => $dto->createDateTime,
            'payout_time' => $dto->createDateTime,
        ]);

        $this->changeBalance($this->member, $walletDto, null, $betRecord);

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    private function executeSettlement(BetInfoData $dto): string
    {
        $platformWin = Common::numDiv($dto->amount, $this->vendorInfo->rate, 4);
        $platformValidBet = Common::numDiv($dto->validBet, $this->vendorInfo->rate, 4);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        $beforeRecord = WcasinoswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->serialnumber)
            ->first();

        if (!is_object($beforeRecord) || empty($beforeRecord->id)) {
            throw new Exception('Bet record not found', ErrorCodeEnum::INTERNAL_ERROR->value);
        }
        if ($beforeRecord->status !== BetRecordStatusEnum::PENDING) {
            throw new Exception('Bet record already completed', ErrorCodeEnum::SUCCESS->value);
        }

        $afterRecord = clone $beforeRecord;
        $afterRecord->status = BetRecordStatusEnum::SETTLED;
        $afterRecord->valid_bet = $platformValidBet;
        $afterRecord->win_lose = Common::numAdd($beforeRecord->win_lose, $platformWin);
        $afterRecord->payout_time = $dto->datetime;

        $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeRecord, $afterRecord);

        (new TotalBetRecordService(
            vendorId: $this->vendorInfo->vendor_id,
            memberId: $this->member->id,
            isMultipleWallet: $walletDto->isMultipleWallet,
        ))->createOrUpdate($afterRecord, $afterRecord, $transactionLogId, 'wcasinosw');

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    private function executeBetCancel(BetInfoData $dto): string
    {
        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        $beforeRecord = WcasinoswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->serialnumber)
            ->first();

        if (!is_object($beforeRecord)) {
            throw new Exception("Bet id {$dto->serialnumber} not found", ErrorCodeEnum::SUCCESS->value);
        }
        if ($beforeRecord->status !== BetRecordStatusEnum::PENDING) {
            throw new Exception("Bet id {$dto->serialnumber} already has result", ErrorCodeEnum::SUCCESS->value);
        }

        $afterRecord = clone $beforeRecord;
        $afterRecord->bet = '0';
        $afterRecord->valid_bet = '0';
        $afterRecord->win_lose = '0';
        $afterRecord->status = BetRecordStatusEnum::CANCELLED;
        $afterRecord->payout_time = $dto->datetime;

        $this->changeBalance($this->member, $walletDto, $beforeRecord, $afterRecord);

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    private function executeSettlementCancel(BetInfoData $dto): string
    {
        $platformAmount = Common::numDiv($dto->amount, $this->vendorInfo->rate, 4);
        if (Common::numCompare($platformAmount, '0') > 0) {
            $platformAmount = Common::numMul($platformAmount, -1, 4);
        }

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        if (Common::numAdd($platformAmount, $walletDto->wallet->balance) < 0) {
            $this->sendAlert('SettlementCancel', $dto, $walletDto->wallet->balance, (float) $platformAmount);
        }

        $beforeRecord = WcasinoswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->serialnumber)
            ->first();

        if (!is_object($beforeRecord)) {
            throw new Exception("Bet id {$dto->serialnumber} not found", ErrorCodeEnum::INTERNAL_ERROR->value);
        }
        if ($beforeRecord->status !== BetRecordStatusEnum::SETTLED) {
            throw new Exception("Bet id {$dto->serialnumber} is not settled", ErrorCodeEnum::SUCCESS->value);
        }

        $afterRecord = clone $beforeRecord;
        $afterRecord->status = BetRecordStatusEnum::PENDING;
        $afterRecord->win_lose = Common::numAdd($beforeRecord->win_lose, $platformAmount);
        $afterRecord->payout_time = $dto->datetime;

        $this->changeBalance($this->member, $walletDto, $beforeRecord, $afterRecord);
        $this->updateTotalBetRecordAndDeleteIfSettled($afterRecord, $platformAmount);

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    private function executeResettlement(BetInfoData $dto): string
    {
        $platformAmount = Common::numDiv($dto->amount, $this->vendorInfo->rate, 4);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        if (Common::numAdd($platformAmount, $walletDto->wallet->balance) < 0) {
            $this->sendAlert('Resettlement', $dto, $walletDto->wallet->balance, (float) $platformAmount);
        }

        $beforeRecord = WcasinoswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->serialnumber)
            ->first();

        if (!is_object($beforeRecord) || empty($beforeRecord->id)) {
            throw new Exception('Bet record not found', ErrorCodeEnum::INTERNAL_ERROR->value);
        }
        if ($beforeRecord->status !== BetRecordStatusEnum::SETTLED) {
            throw new Exception('Bet record NOT settled yet', ErrorCodeEnum::INTERNAL_ERROR->value);
        }

        $afterRecord = clone $beforeRecord;
        $afterRecord->win_lose = Common::numAdd($beforeRecord->win_lose, $platformAmount);
        $afterRecord->resettlement_count = Common::numAdd($beforeRecord->resettlement_count, 1);
        $afterRecord->payout_time = $dto->datetime;

        $this->changeBalance($this->member, $walletDto, $beforeRecord, $afterRecord);
        $this->updateTotalBetRecord($afterRecord, $platformAmount);

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    private function executeRollbackBetCancel(BetInfoData $dto): string
    {
        $platformAmount = Common::numDiv($dto->amount, $this->vendorInfo->rate, 4);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        if (Common::numAdd($platformAmount, $walletDto->wallet->balance) < 0) {
            $this->sendAlert('Rollback_BetCancel', $dto, $walletDto->wallet->balance, (float) $platformAmount);
        }

        $betRecord = WcasinoswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->serialnumber)
            ->first();

        if (!is_object($betRecord)) {
            throw new Exception("Bet id {$dto->serialnumber} not found", ErrorCodeEnum::INTERNAL_ERROR->value);
        }
        if ($betRecord->status !== BetRecordStatusEnum::CANCELLED) {
            throw new Exception("Bet id {$dto->serialnumber} is not cancelled", ErrorCodeEnum::SUCCESS->value);
        }

        $betRecord->status = BetRecordStatusEnum::PENDING;
        $betRecord->win_lose = Common::numAdd($platformAmount, $betRecord->win_lose);
        $betRecord->payout_time = $dto->datetime;
        $betRecord->save();

        $this->increaseWalletBalance($this->member, $walletDto, $platformAmount);

        $totalBetRecord = TotalBetRecord::lockForUpdate()
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('bet_record_id', $betRecord->id)
            ->first();

        if (!is_object($totalBetRecord) || empty($totalBetRecord->id)) {
            throw new Exception('Total bet record not found', ErrorCodeEnum::INTERNAL_ERROR->value);
        }

        $totalBetRecord->win_lose = Common::numAdd($totalBetRecord->win_lose, $platformAmount);
        $totalBetRecord->payout_time = $betRecord->payout_time;
        $totalBetRecord->save();

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    private function executeRollbackResettlement(BetInfoData $dto): string
    {
        $platformAmount = Common::numDiv($dto->amount, $this->vendorInfo->rate, 4);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo->rolling_chip_status, $this->account);

        if (Common::numAdd($platformAmount, $walletDto->wallet->balance) < 0) {
            $this->sendAlert('Rollback_Resettlement', $dto, $walletDto->wallet->balance, (float) $platformAmount);
        }

        $betRecord = WcasinoswBetRecord::lockForUpdate()
            ->where('bet_id', $dto->serialnumber)
            ->first();

        if (!is_object($betRecord)) {
            throw new Exception("Bet id {$dto->serialnumber} not found", ErrorCodeEnum::INTERNAL_ERROR->value);
        }
        if ($betRecord->resettlement_count <= 0) {
            throw new Exception("Bet id {$dto->serialnumber} resettlement count is 0, could not rollback", ErrorCodeEnum::SUCCESS->value);
        }

        $betRecord->win_lose = Common::numAdd($platformAmount, $betRecord->win_lose);
        $betRecord->resettlement_count = Common::numSub($betRecord->resettlement_count, 1);
        $betRecord->payout_time = $dto->datetime;
        $betRecord->save();

        $this->increaseWalletBalance($this->member, $walletDto, $platformAmount);
        $this->updateTotalBetRecordAndDeleteIfSettled($betRecord, $platformAmount);

        return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate);
    }

    // -------------------------------------------------------------------------
    // Infrastructure helpers
    // -------------------------------------------------------------------------

    private function handleAction(BetInfoData $dto, \Closure $executor): string
    {
        $notifyRecord = $this->createNotifyRecord($dto);

        try {
            DB::beginTransaction();
            $result = $executor();
            $notifyRecord->update(['is_done' => true]);
            DB::commit();

            return $result;
        } catch (QueryException $e) {
            DB::rollBack();
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Duplicated notify record', ErrorCodeEnum::SUCCESS->value);
            }
            throw new Exception($e->getMessage(), ErrorCodeEnum::INTERNAL_ERROR->value);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleRollback(BetInfoData $dto, \Closure $executor): string
    {
        $rollbackNotifyRecord = $this->createRollbackNotifyRecord($dto);
        if (!is_object($rollbackNotifyRecord)) {
            return (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);
        }

        try {
            DB::beginTransaction();
            $result = $executor();
            $rollbackNotifyRecord->update(['is_done' => true]);
            DB::commit();

            return $result;
        } catch (QueryException $e) {
            DB::rollBack();
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Duplicated notify record', ErrorCodeEnum::SUCCESS->value);
            }
            throw new Exception($e->getMessage(), ErrorCodeEnum::INTERNAL_ERROR->value);
        } catch (Exception $e) {
            DB::rollBack();
            $code = $e->getCode() === 0 ? ErrorCodeEnum::INTERNAL_ERROR->value : $e->getCode();
            throw new Exception($e->getMessage(), $code);
        }
    }

    private function createNotifyRecord(BetInfoData $dto): WcasinoswNotifyRecord
    {
        try {
            $notifyRecord = WcasinoswNotifyRecord::create([
                'bet_id' => $dto->serialnumber,
                'notify_id' => $dto->notifyid,
                'type' => $dto->type,
                'amount' => $dto->amount,
                'is_rollback' => false,
                'is_done' => false,
                'infos' => $dto->infos,
            ]);
            if (!is_object($notifyRecord) || empty($notifyRecord->id)) {
                throw new Exception('Create notify record failed', ErrorCodeEnum::INTERNAL_ERROR->value);
            }

            return $notifyRecord;
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Duplicated notify record', ErrorCodeEnum::SUCCESS->value);
            }
            Log::error('Insert_notify_record_failed: ' . $e->getMessage());
            throw new Exception('Insert_notify_record_failed', ErrorCodeEnum::INTERNAL_ERROR->value);
        } catch (Exception $e) {
            Log::error('Insert_notify_record_failed: ' . $e->getMessage());
            throw new Exception('Insert_notify_record_failed', ErrorCodeEnum::INTERNAL_ERROR->value);
        }
    }

    private function createRollbackNotifyRecord(BetInfoData $dto): ?WcasinoswNotifyRecord
    {
        try {
            $targetNotifyRecord = WcasinoswNotifyRecord::where('bet_id', $dto->serialnumber)
                ->where('type', $dto->type)
                ->where('is_done', true)
                ->where('is_rollback', false)
                ->first();

            if (!is_object($targetNotifyRecord)) {
                return null;
            }

            $rollbackNotifyRecord = WcasinoswNotifyRecord::create([
                'bet_id' => $dto->serialnumber,
                'notify_id' => $dto->notifyid,
                'type' => $dto->type,
                'amount' => $dto->amount,
                'is_rollback' => true,
                'is_done' => false,
                'infos' => $dto->infos,
            ]);

            if (!is_object($rollbackNotifyRecord) || empty($rollbackNotifyRecord->id)) {
                throw new Exception('Create rollback notify record failed', ErrorCodeEnum::INTERNAL_ERROR->value);
            }

            return $rollbackNotifyRecord;
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return null;
            }
            Log::error('createRollbackNotifyRecord: ' . $e->getMessage());
            throw new Exception('createRollbackNotifyRecord failed', ErrorCodeEnum::INTERNAL_ERROR->value);
        }
    }

    private function updateTotalBetRecord(WcasinoswBetRecord $betRecord, string $amount): void
    {
        $totalBetRecord = TotalBetRecord::lockForUpdate()
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('bet_record_id', $betRecord->id)
            ->first();

        if (!is_object($totalBetRecord) || empty($totalBetRecord->id)) {
            return;
        }

        $totalBetRecord->win_lose = Common::numAdd($totalBetRecord->win_lose, $amount);
        $totalBetRecord->payout_time = $betRecord->payout_time;
        $totalBetRecord->save();
    }

    private function updateTotalBetRecordAndDeleteIfSettled(WcasinoswBetRecord $betRecord, string $amount): void
    {
        $totalBetRecord = TotalBetRecord::lockForUpdate()
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('bet_record_id', $betRecord->id)
            ->first();

        if (!is_object($totalBetRecord) || empty($totalBetRecord->id)) {
            return;
        }

        $totalBetRecord->win_lose = Common::numAdd($totalBetRecord->win_lose, $amount);
        $totalBetRecord->payout_time = $betRecord->payout_time;

        if (Common::numAdd($totalBetRecord->win_lose, $totalBetRecord->bet) == 0) {
            $totalBetRecord->delete();
        } else {
            $totalBetRecord->save();
        }
    }

    private function sendAlert(string $context, BetInfoData $dto, string $walletBalance, float $amount): never
    {
        $msg = "WCASINO_NOTICE: {$context} amount {$amount} is greater than wallet balance, \n
            request amount: {$dto->amount}, \n
            wallet balance: {$walletBalance}, \n
            notify id: {$dto->notifyid}, \n
            wcasino username: {$dto->username}, \n
            member id: {$this->member->id}\n";

        app(BotNotifyService::class)->telegram($msg);
        throw new Exception($msg, ErrorCodeEnum::BALANCE_INSUFFICIENT->value);
    }

    private function verifySign(array $data): bool
    {
        $vendorInfoDetail = VendorInfoDetailData::from($this->vendorInfo->info);
        $sign = $data['sign'] ?? '';
        unset($data['sign'], $data['bets']);
        $data = array_change_key_case($data, CASE_LOWER);
        $data = array_filter($data);
        ksort($data);
        $dataString = urldecode(http_build_query($data));
        $encryptedKey = md5($dataString . '&key=' . $vendorInfoDetail->CLIENT_SECRET);

        return $sign === $encryptedKey;
    }

    private function errorResponse(int $errorCode, string $errorMessage = ''): JsonResponse
    {
        $responseData = ['result' => $errorCode, 'desc' => $errorMessage];

        Log::info("Wcasino_SW_error_$errorCode", [
            'requestData' => [
                'headers' => Common::flattenHeaders(request()->headers->all()),
                'params' => request()->all(),
            ],
            'responseData' => $responseData,
        ]);

        return response()->json($responseData);
    }
}
