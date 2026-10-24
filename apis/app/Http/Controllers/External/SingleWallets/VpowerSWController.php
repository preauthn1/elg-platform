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
use App\Http\Requests\External\VpowerSW\BetRequest;
use App\Http\Requests\External\VpowerSW\GetBalanceRequest;
use App\Http\Requests\External\VpowerSW\SettleRequest;
use App\Models\Member;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\Models\VpowerswBetRecord;
use App\Vendors\VpowerSW\Datas\BetInfoData;
use App\Vendors\VpowerSW\Datas\VendorInfoDetailData;
use App\Vendors\VpowerSW\Enums\BetTypeEnum;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VpowerSWController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::VPOWERSW->value;

    protected string $accountClass = VpowerswAccount::class;

    protected string $betRecordClass = VpowerswBetRecord::class;

    protected string $actionPrefix = 'Vpower_SW_request_';

    protected string $vendorInfoOperatorField = 'APP_ID';

    private array $requestInfos = [];

    public function callAction($method, $parameters): mixed
    {
        try {
            $request = request();
            $ip = Common::getIp();

            $this->requestInfos = [
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
            ];

            Log::info($this->actionPrefix . basename(url()->current()), [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'headers' => Common::flattenHeaders($request->headers->all()),
                'params' => $request->all(),
                'ContentType' => $request->getContentTypeFormat(),
                'Content' => $request->getContent(),
                'IP' => $ip,
            ]);

            if (!Common::verifyIPWhitelistByVendorId(VendorIdEnum::VPOWER->value)) {
                throw new Exception("IP {$ip} not allow", ErrorCodeEnum::OTHER_EXCEPTIONS->value);
            }

            $this->setUpVendorAccount(thirdPartyUsername: $request->input('username', ''));

            $this->verifySign($request);

            $response = parent::callAction($method, $parameters);
        } catch (VendorAccountException|MemberNotFoundException $e) {
            $response = $this->errorLogAndResponse(ErrorCodeEnum::USER_NOT_FOUND->value, $e->getMessage());
        } catch (VendorNotFoundException $e) {
            $response = $this->errorLogAndResponse(ErrorCodeEnum::OTHER_EXCEPTIONS->value, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error($this->actionPrefix . 'error', [
                'message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $response = $this->errorLogAndResponse($e->getCode() ?: ErrorCodeEnum::OTHER_EXCEPTIONS->value, $e->getMessage());
        } finally {
            return $response;
        }
    }

    public function balance(GetBalanceRequest $request): JsonResponse
    {
        if ($this->account->status == 0) {
            return $this->errorLogAndResponse(ErrorCodeEnum::OTHER_EXCEPTIONS->value, 'Account is disabled');
        }

        if (!BetHelper::isMemberFlowLimitAllowed($this->member, $this->vendorId)) {
            return $this->errorLogAndResponse(ErrorCodeEnum::OTHER_EXCEPTIONS->value, 'Account could not bet yet');
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'balance' => $balance,
        ]);
    }

    public function bet(BetRequest $request): JsonResponse
    {
        $betInfoData = BetInfoData::from($request->all());

        $balance = $betInfoData->completed
            ? $this->settlement($betInfoData)
            : $this->placeBet($betInfoData);

        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'balance' => $balance,
        ]);
    }

    public function settle(SettleRequest $request): JsonResponse
    {
        $betInfoData = BetInfoData::from($request->all());

        $balance = $betInfoData->type === BetTypeEnum::REFUND->value
            ? $this->refund($betInfoData)
            : $this->settlement($betInfoData);

        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'balance' => $balance,
        ]);
    }

    private function placeBet(BetInfoData $dto): string
    {
        try {
            return DB::transaction(function () use ($dto) {
                $this->member = Member::lockForUpdate()->find($this->member->id);

                $this->verifyBettability($dto->gameId);

                $amount = Common::numDiv($dto->balance, $this->vendorInfo->rate);
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                if (Common::numCompare(Common::numSub($walletDto->getTotalBalance(), $amount), '0') < 0) {
                    throw new Exception('Wallet balance not enough', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
                }

                $betRecord = new VpowerswBetRecord([
                    'bet_id' => $dto->gameRoundId,
                    'username' => $dto->username,
                    'game_code' => $dto->gameId,
                    'status' => 0,
                    'infos' => $dto->infos,
                    'type' => $dto->type,
                    'bet_time' => $dto->datetime,
                    'payout_time' => $dto->datetime,
                    'bet' => $amount,
                    'valid_bet' => $amount,
                    'win_lose' => Common::numMul($amount, -1),
                    'rolling_chips' => 0,
                ]);

                $this->changeBalance($this->member, $walletDto, null, $betRecord);

                return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Bet record duplicated', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
            }
            throw $e;
        }
    }

    private function settlement(BetInfoData $dto): string
    {
        try {
            return DB::transaction(function () use ($dto) {
                $this->member = Member::lockForUpdate()->find($this->member->id);

                $amount = Common::numDiv($dto->balance, $this->vendorInfo->rate);

                $placeBetRecord = VpowerswBetRecord::lockForUpdate()
                    ->where('bet_id', $dto->gameRoundId)
                    ->first();

                if (!is_object($placeBetRecord)) {
                    throw new Exception('Bet record not found', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
                }

                $settleBetRecord = new VpowerswBetRecord([
                    'bet_id' => $dto->transactionId,
                    'reference_bet_id' => $dto->gameRoundId,
                    'username' => $dto->username,
                    'game_code' => $dto->gameId,
                    'status' => 1,
                    'infos' => $dto->infos,
                    'type' => $dto->type,
                    'bet' => 0,
                    'valid_bet' => 0,
                    'win_lose' => $amount,
                    'bet_time' => $dto->datetime,
                    'payout_time' => $dto->datetime,
                ]);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, null, $settleBetRecord);

                $placeBetRecord->status = (int) $dto->completed;
                $placeBetRecord->save();

                (new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet))
                    ->createOrUpdate($placeBetRecord, $settleBetRecord, $transactionLogId, 'vpowersw');

                return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Bet record duplicated', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
            }
            throw $e;
        }
    }

    private function refund(BetInfoData $dto): string
    {
        return DB::transaction(function () use ($dto) {
            $this->member = Member::lockForUpdate()->find($this->member->id);

            $beforeBetRecord = VpowerswBetRecord::lockForUpdate()
                ->where('bet_id', $dto->gameRoundId)
                ->where('status', 0)
                ->first();

            if (!is_object($beforeBetRecord)) {
                throw new Exception(
                    "Game round id {$dto->gameRoundId} not found or already has result",
                    ErrorCodeEnum::OTHER_EXCEPTIONS->value
                );
            }

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            $afterBetRecord = clone $beforeBetRecord;
            $afterBetRecord->bet = 0;
            $afterBetRecord->valid_bet = 0;
            $afterBetRecord->win_lose = 0;
            $afterBetRecord->status = 1;
            $afterBetRecord->type = $dto->type;
            $afterBetRecord->payout_time = $dto->datetime;
            $afterBetRecord->infos = json_encode([
                json_decode($beforeBetRecord->infos ?? '[]', true),
                json_decode($dto->infos ?? '[]', true),
            ]);

            $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $afterBetRecord);

            return Common::numMul($walletDto->getTotalBalance(), $this->vendorInfo->rate, 4);
        });
    }

    private function verifySign(Request $request): void
    {
        $sign = $request->header('X-Sign', '');
        if (empty($sign)) {
            throw new Exception('Missing sign in header', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }

        $infoDetail = VendorInfoDetailData::from($this->vendorInfo->info);
        $content = json_encode(json_decode($request->getContent()));

        if (md5($content . $infoDetail->APP_SECRET) !== $sign) {
            throw new Exception('Sign error', ErrorCodeEnum::OTHER_EXCEPTIONS->value);
        }
    }

    private function errorLogAndResponse(int $errorCode, string $errorMessage = ''): JsonResponse
    {
        $responseData = [
            'code' => $errorCode,
            'msg' => $errorMessage,
        ];

        Log::error("Vpower_SW_error_$errorCode", [
            'requestData' => $this->requestInfos,
            'responseData' => $responseData,
        ]);

        return response()->json($responseData);
    }
}
