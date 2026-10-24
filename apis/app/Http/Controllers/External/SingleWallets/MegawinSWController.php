<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\BetException;
use App\Exceptions\MemberNotFoundException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorNotFoundException;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Http\Requests\External\MegawinSW\AuthenticateTokenRequest;
use App\Http\Requests\External\MegawinSW\GetBalanceRequest;
use App\Http\Requests\External\MegawinSW\PlaceBetRequest;
use App\Http\Requests\External\MegawinSW\SettleBetRequest;
use App\Http\Requests\External\MegawinSW\UnsettleBetRequest;
use App\Models\Member;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\MegawinSW\Datas\MegaWinSWVendorInfoDetailData;
use App\Vendors\MegawinSW\Datas\MegaWinSWWagerData;
use App\Vendors\MegawinSW\Enums\BetStatusEnum;
use App\Vendors\MegawinSW\Enums\ErrorCodeEnum;
use App\Vendors\MegawinSW\Externals\CipherService;
use App\Vendors\Models\MegawinswAccount;
use App\Vendors\Models\MegawinswBetRecord;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MegawinSWController extends BaseController implements ShouldVerifyGameBettability
{
    protected int $vendorId = VendorIdEnum::MEGAWINSW->value;

    protected string $accountClass = MegawinswAccount::class;

    protected string $betRecordClass = MegawinswBetRecord::class;

    protected string $actionPrefix = 'MegaWin_SW_request_';

    protected string $vendorInfoOperatorField = 'MW_CODE';

    private array $requestInfos = [];

    public function callAction($method, $parameters)
    {
        $response = null;
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

            if (!Common::verifyIPWhitelistByVendorId(VendorIdEnum::MEGAWINSW->value)) {
                throw new Exception("IP {$ip} not allow", ErrorCodeEnum::PARAM_ERROR);
            }

            $response = parent::callAction($method, $parameters);
        } catch (MemberNotFoundException $e) {
            $response = $this->errorResponse(ErrorCodeEnum::USER_DISABLED, $e->getMessage());
        } catch (VendorNotFoundException $e) {
            $response = $this->errorResponse(ErrorCodeEnum::SYSTEM_BUSY, $e->getMessage());
        } catch (Throwable $e) {
            $response = $this->errorResponse(ErrorCodeEnum::SYSTEM_BUSY, $e->getMessage());
        } finally {
            return $response;
        }
    }

    /**
     * @throws VendorAccountException|MemberNotFoundException|VendorNotFoundException
     */
    public function authenticateToken(AuthenticateTokenRequest $request): JsonResponse
    {
        $this->setUpVendorAccount(thirdPartyUsername: $request->uid, whereConditions: [
            ['user_token', $request->utoken],
        ]);

        $vendorInfoDetail = app(MegaWinSWVendorInfoDetailData::class);

        $userData = [
            'uid' => $this->account->username,
            'userName' => config('app.name') . '_' . $this->account->username,
            'userEnable' => $this->account->isEnabled() ? 'Y' : 'N',
            'userTest' => $vendorInfoDetail->IS_TEST,
            'currency' => $vendorInfoDetail->CURRENCY,
        ];

        $responseData = app(CipherService::class)->encrypt([
            'ret' => ErrorCodeEnum::SUCCESS,
            'msg' => '',
            'userData' => $userData,
        ]);

        return response()->json($responseData);
    }

    /**
     * @throws VendorAccountException|MemberNotFoundException|VendorNotFoundException
     */
    public function getBalance(GetBalanceRequest $request): JsonResponse
    {
        $this->setUpVendorAccount(thirdPartyUsername: $request->uid, whereConditions: [
            ['user_token', $request->utoken],
        ]);

        if (!BetHelper::isMemberFlowLimitAllowed($this->member, VendorIdEnum::MEGAWINSW->value)) {
            throw new Exception('Account could not bet yet', ErrorCodeEnum::USER_DISABLED);
        }

        $balance = (new WalletService)->getSingleWalletBalanceByMember($this->vendorInfo, $this->member);

        $vendorInfoDetail = app(MegaWinSWVendorInfoDetailData::class);
        $responseData = app(CipherService::class)->encrypt([
            'uid' => $request->uid,
            'userGold' => $balance,
            'currency' => $vendorInfoDetail->CURRENCY,
            'ret' => ErrorCodeEnum::SUCCESS,
            'msg' => '',
        ]);

        return response()->json($responseData);
    }

    public function placeBet(PlaceBetRequest $request): JsonResponse
    {
        $ret = ['wagerDataResp' => []];
        $cipherService = null;

        foreach ($request->wagerData as $data) {
            $dto = MegaWinSWWagerData::from($data);

            try {
                $this->setUpVendorAccount(thirdPartyUsername: $dto->uid, whereConditions: [
                    ['user_token', $dto->utoken],
                ]);
                $cipherService ??= app(CipherService::class);

                $result = $this->placeBetWager($dto);

                $ret['wagerDataResp'][] = [
                    'wagerId' => $dto->wagerId,
                    'ret' => ErrorCodeEnum::SUCCESS,
                    'msg' => '',
                    'beginGold' => $result['before'],
                    'endGold' => $result['after'],
                ];
            } catch (Exception $e) {
                $code = $e->getCode() === 0 ? ErrorCodeEnum::SYSTEM_BUSY : $e->getCode();
                $ret['wagerDataResp'][] = ['wagerId' => $dto->wagerId, 'ret' => $code, 'msg' => $e->getMessage()];
                Log::error('MegaWin_SW_placeBet_failed', [
                    'wagerData' => $data,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        }

        Log::info('MegaWin_SW_PlaceBet_result', $ret);

        return $cipherService
            ? response()->json($cipherService->encrypt($ret))
            : response()->json($ret);
    }

    public function settleBet(SettleBetRequest $request): JsonResponse
    {
        $ret = ['wagerDataResp' => []];
        $cipherService = null;

        foreach ($request->wagerData as $data) {
            $dto = MegaWinSWWagerData::from($data);

            try {
                $this->setUpVendorAccount(thirdPartyUsername: $dto->uid, whereConditions: [
                    ['user_token', $dto->utoken],
                ]);
                $cipherService ??= app(CipherService::class);

                $result = $this->settleBetWager($dto);

                $ret['wagerDataResp'][] = [
                    'wagerId' => $dto->wagerId,
                    'ret' => ErrorCodeEnum::SUCCESS,
                    'msg' => '',
                    'beginGold' => $result['before'],
                    'endGold' => $result['after'],
                ];
            } catch (Exception $e) {
                $code = $e->getCode() === 0 ? ErrorCodeEnum::SYSTEM_BUSY : $e->getCode();
                $ret['wagerDataResp'][] = ['wagerId' => $dto->wagerId, 'ret' => $code, 'msg' => $e->getMessage()];
                Log::error('MegaWin_SW_settleBet_failed', [
                    'wagerData' => $data,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        }

        Log::info('MegaWin_SW_SettleBet_result', $ret);

        return $cipherService
            ? response()->json($cipherService->encrypt($ret))
            : response()->json($ret);
    }

    public function unsettleBet(UnsettleBetRequest $request): JsonResponse
    {
        $ret = ['wagerDataResp' => []];
        $cipherService = null;

        foreach ($request->wagerData as $data) {
            $dto = MegaWinSWWagerData::from($data);

            try {
                $this->setUpVendorAccount(thirdPartyUsername: $dto->uid, whereConditions: [
                    ['user_token', $dto->utoken],
                ]);
                $cipherService ??= app(CipherService::class);

                $result = $this->unsettleBetWager($dto);

                $ret['wagerDataResp'][] = [
                    'wagerId' => $dto->wagerId,
                    'ret' => ErrorCodeEnum::SUCCESS,
                    'msg' => '',
                    'beginGold' => $result['before'],
                    'endGold' => $result['after'],
                ];
            } catch (Exception $e) {
                $code = $e->getCode() === 0 ? ErrorCodeEnum::SYSTEM_BUSY : $e->getCode();
                $ret['wagerDataResp'][] = ['wagerId' => $dto->wagerId, 'ret' => $code, 'msg' => $e->getMessage()];
                Log::error('MegaWin_SW_unsettleBet_failed', [
                    'wagerData' => $data,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        }

        Log::info('MegaWin_SW_UnsettleBet_result', $ret);

        return $cipherService
            ? response()->json($cipherService->encrypt($ret))
            : response()->json($ret);
    }

    /**
     * @throws Throwable
     */
    private function placeBetWager(MegaWinSWWagerData $dto): array
    {
        try {
            $this->verifyBettability((string) $dto->gameId);

            return DB::transaction(function () use ($dto) {
                $this->member = Member::lockForUpdate()->find($this->member->id);

                $amount = Common::numDiv((string) $dto->gold, (string) $this->vendorInfo->rate);

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $beforeBalance = $walletDto->getTotalBalance();

                if (Common::numCompare(Common::numSub($beforeBalance, $amount), '0') < 0) {
                    throw new Exception('Wallet balance not enough', ErrorCodeEnum::INSUFFICIENT_BALANCE);
                }

                $betRecord = new MegawinswBetRecord([
                    'bet_id' => $dto->wagerId,
                    'username' => $dto->uid,
                    'game_code' => (string) $dto->gameId,
                    'status' => BetStatusEnum::NO_RESULT,
                    'infos' => json_encode($dto),
                    'bet' => $amount,
                    'valid_bet' => $amount,
                    'win_lose' => Common::numMul($amount, '-1'),
                    'rolling_chips' => '0',
                    'bet_time' => $dto->addDate,
                    'payout_time' => $dto->resultDate,
                ]);

                $this->changeBalance($this->member, $walletDto, null, $betRecord);

                return [
                    'before' => Common::numMul($beforeBalance, (string) $this->vendorInfo->rate, 4),
                    'after' => Common::numMul($walletDto->getTotalBalance(), (string) $this->vendorInfo->rate, 4),
                ];
            });
        } catch (BetException $e) {
            throw new Exception($e->getMessage(), ErrorCodeEnum::USER_DISABLED);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Bet record duplicated', ErrorCodeEnum::ORDER_EXISTS);
            }
            throw $e;
        }
    }

    private function settleBetWager(MegaWinSWWagerData $dto): array
    {
        try {
            return DB::transaction(function () use ($dto) {
                $this->member = Member::lockForUpdate()->find($this->member->id);

                $validBetAmount = Common::numDiv((string) $dto->vgold, (string) $this->vendorInfo->rate);
                $winAmount = Common::numDiv((string) $dto->winGold, (string) $this->vendorInfo->rate);

                $beforeBetRecord = MegawinswBetRecord::lockForUpdate()
                    ->where('bet_id', $dto->wagerId)
                    ->first();

                if (!is_object($beforeBetRecord)) {
                    throw new Exception("Wager id {$dto->wagerId} not found", ErrorCodeEnum::ORDER_NOT_FOUND);
                }

                if ($beforeBetRecord->status !== BetStatusEnum::NO_RESULT) {
                    throw new Exception("Wager id {$dto->wagerId} already has result", ErrorCodeEnum::ORDER_EXISTS);
                }

                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
                $beforeBalance = $walletDto->getTotalBalance();

                $afterBetRecord = clone $beforeBetRecord;
                $afterBetRecord->status = $dto->result;
                $afterBetRecord->bet = $validBetAmount;
                $afterBetRecord->valid_bet = $validBetAmount;
                $afterBetRecord->win_lose = Common::numSub($winAmount, $validBetAmount);
                $afterBetRecord->payout_time = $dto->resultDate;
                $afterBetRecord->result_infos = json_encode($dto);

                $transactionLogId = $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $afterBetRecord);

                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->setErrorCode(ErrorCodeEnum::SYSTEM_BUSY);
                $totalBetRecordService->create($afterBetRecord, $transactionLogId);

                return [
                    'before' => Common::numMul($beforeBalance, (string) $this->vendorInfo->rate, 4),
                    'after' => Common::numMul($walletDto->getTotalBalance(), (string) $this->vendorInfo->rate, 4),
                ];
            });
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                throw new Exception('Settle record duplicated', ErrorCodeEnum::ORDER_EXISTS);
            }
            throw $e;
        }
    }

    private function unsettleBetWager(MegaWinSWWagerData $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $this->member = Member::lockForUpdate()->find($this->member->id);

            $beforeBetRecord = MegawinswBetRecord::lockForUpdate()
                ->where('bet_id', $dto->wagerId)
                ->first();

            if (!is_object($beforeBetRecord)) {
                throw new Exception("Wager id {$dto->wagerId} not found", ErrorCodeEnum::ORDER_NOT_FOUND);
            }

            if ($beforeBetRecord->status !== BetStatusEnum::NO_RESULT) {
                throw new Exception("Wager id {$dto->wagerId} already has result", ErrorCodeEnum::ORDER_EXISTS);
            }

            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
            $beforeBalance = $walletDto->getTotalBalance();

            $afterBetRecord = clone $beforeBetRecord;
            $afterBetRecord->win_lose = '0';
            $afterBetRecord->status = $dto->result;
            $afterBetRecord->payout_time = $dto->resultDate;
            $afterBetRecord->result_infos = json_encode($dto);

            $this->changeBalance($this->member, $walletDto, $beforeBetRecord, $afterBetRecord);

            return [
                'before' => Common::numMul($beforeBalance, (string) $this->vendorInfo->rate, 4),
                'after' => Common::numMul($walletDto->getTotalBalance(), (string) $this->vendorInfo->rate, 4),
            ];
        });
    }

    private function errorResponse(int $errorCode, string $errorMessage): JsonResponse
    {
        $responseData = [
            'ret' => $errorCode,
            'msg' => $errorMessage,
        ];

        Log::error("MegaWin_SW_error_$errorCode", [
            'requestData' => $this->requestInfos,
            'responseData' => $responseData,
        ]);

        try {
            return response()->json(app(CipherService::class)->encrypt($responseData));
        } catch (Throwable) {
            // fall through to plain response if cipher not available
        }

        return response()->json($responseData);
    }
}
