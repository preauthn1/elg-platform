<?php

/** @noinspection SpellCheckingInspection */

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Services\TotalBetRecordService;
use App\Services\WalletService;
use App\Vendors\Models\CrswAccount;
use App\Vendors\Models\CrswBetRecord;
use ErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * CRSW 體育 121
 */
class CrSportSwController extends BaseController
{
    protected int $vendorId = VendorIdEnum::CRSW->value;

    protected string $actionPrefix = 'CrSport_SW_request_';

    protected string $accountClass = CrswAccount::class;

    protected string $betRecordClass = CrswBetRecord::class;

    private array $result = [
        '0' => 0, // 無結果
        'L' => 1, // 輸
        'W' => 2, // 贏
        'P' => 3, // 和
        'D' => 4, // 取消
    ];

    /**
     * 單一入口
     */
    public function __invoke(Request $request)
    {
        try {
            // 驗證 IP / 請求參數
            $validated = $this->validateRequest($request);

            // 取得遊戲商戶
            $this->vendorInfo = $this->findVendorInfo($validated['AGID']);

            // 檢查商戶配置
            $config = $this->vendorInfo->info;
            $this->checkInfo($config);

            // 解密 data 字段
            $method = $validated['Method'];
            $responeId = $validated['responeid'];
            $data = $this->decryptData($validated['data'], $config['cryptKey']);
            // 紀錄請求日誌
            $validated['data'] = $data; // 將解密後的 data 放入 validated 中
            Log::info($this->actionPrefix . $method, ['vendor_request' => $validated]);

            $handlers = [
                'PlaceBet' => 'placeBet',
                'CancelBet' => 'cancelBet',
                'RevertBet' => 'revertBet',
                'SettleBet' => 'settletBet',
                'UnsettleBet' => 'unsettleBet',
            ];

            $result = [];
            if (isset($handlers[$method])) {
                // ! $data['wagerdata'] 是一個陣列可能包含多筆注單
                foreach ($data['wagerdata'] as $row) {
                    $params = [
                        'Method' => $method,
                        'responeid' => $responeId,
                    ];
                    $params = array_merge($params, $row);

                    Log::info('CRSW ' . $params['wagerid'] . ' ' . $method, $params);

                    $result[] = $this->{$handlers[$method]}($params);
                }
            } else {
                throw new ErrorException('Invalid Method', 9999);
            }

            return $this->successResponse($result);

        } catch (Throwable $e) {
            // 統一錯誤處理
            Log::emergency($this->actionPrefix . 'error: ' . $e->getMessage());
            // respcode 補零到四位
            $errorCode = str_pad((string) $e->getCode(), 4, '0', STR_PAD_LEFT);

            return $this->errorResponse($e->getMessage(), $errorCode);
        }
    }

    private function validateRequest(Request $request): array
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            throw new ErrorException('IP not in whitelist', 9999);
        }

        $validator = Validator::make($request->all(), [
            'AGID' => 'required|string|max:4',
            'data' => 'required|string',
            'responeid' => 'required|string',
            'Method' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ErrorException($validator->errors()->first(), 1);
        }

        return $validator->validated();
    }

    protected function findVendorInfo(string $agid): VendorInfo
    {
        $vendorInfos = VendorInfo::query()
            ->where('vendor_id', $this->vendorId)
            ->where('status', 1)
            ->get();

        foreach ($vendorInfos as $vendorInfo) {
            if ($vendorInfo->info['agid'] == $agid) {
                return $vendorInfo;
            }
        }

        throw new ErrorException('AGID not found', 1); // 0001 無效的參數
    }

    protected function checkInfo(array $config = []): void
    {
        if (empty($config)) {
            throw new ErrorException('VendorInfo detail is empty, use setVendorInfo() to set the value', 9999);
        }

        if (empty($config['apiUrl'])) {
            throw new ErrorException("VendorInfo detail's apiUrl is empty, please check the value", 9999);
        }

        if (empty($config['cryptKey'])) {
            throw new ErrorException("VendorInfo detail's cryptKey is empty, please check the value", 9999);
        }

        if (empty($config['token'])) {
            throw new ErrorException("VendorInfo detail's token  is empty, please check the value", 9999);
        }

        if (empty($config['username'])) {
            throw new ErrorException("VendorInfo detail's username  is empty, please check the value", 9999);
        }

        if (empty($config['password'])) {
            throw new ErrorException("VendorInfo detail's password  is empty, please check the value", 9999);
        }

        if (empty($config['agid'])) {
            throw new ErrorException("VendorInfo detail's agid  is empty, please check the value", 9999);
        }
    }

    protected function decryptData(string $string, string $cryptKey): array
    {
        $string = base64_decode($string);
        $decrypted = openssl_decrypt($string, 'AES-128-ECB', $cryptKey, OPENSSL_RAW_DATA);

        if ($decrypted === false) {
            throw new ErrorException('Decryption failed: ' . openssl_error_string(), 10); // 0010 加解密失敗
        }

        return json_decode($decrypted, true);
    }

    /**
     * 錯誤信息格式
     */
    protected function errorResponse(string $errorMessage, string $errorCode): JsonResponse
    {
        return response()->json([
            'data' => [],
            'status' => 'failed',
            'respcode' => $errorCode,
            'message' => $errorMessage,
            'Method' => request()->Method ?? '',
            'timestamp' => time(),
        ]);
    }

    /**
     * 正確信息格式
     */
    protected function successResponse(array $data = []): JsonResponse
    {
        return response()->json([
            'data' => [
                'wagerdataresp' => $data,
            ],
            'status' => 'success',
            'respcode' => '0000',
            'Method' => request()->Method ?? '',
            'timestamp' => time(),
        ]);
    }

    /**
     * 下注 (建立注單，扣錢)
     */
    private function placeBet(array $params)
    {
        try {
            // 會員解析與可投注檢查都是一般讀，放在交易外；進交易第一個 SQL 才能是鎖會員列（target/tickets#4297）
            $this->member = $this->getMemberByThirdPartyUsername($params['username']);

            if ($this->account->status == 0) {
                throw new ErrorException('member not found');
            }

            $ableToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, (string) 'sport');
            if (!$ableToBet) {
                throw new ErrorException('Account could not bet yet');
            }

            return DB::transaction(function () use ($params) {
                // 初始化所有變數
                $username = $params['username'];
                $betId = $params['wagerid'];
                $betAmount = Common::numDiv($params['degold'], $this->vendorInfo->rate, 4);
                $winAmount = 0;
                $winLose = Common::numSub($winAmount, $betAmount, 4);

                // 取得用戶真金跟禮碼
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
                $balance = $walletDto->getTotalBalance();

                // 檢查餘額
                if (Common::numCompare($balance, $betAmount) < 0) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '5102', // 餘額不足
                    ];
                }

                // 查詢注單
                $oldBetRecord = $this->betRecordClass::query()->where('bet_id', $betId)->first();

                if ($oldBetRecord) {
                    $respcode = ($oldBetRecord->status == 1) ? '0008' : '0011'; // 單號重複

                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => $respcode,
                    ];
                }

                // 建立注單
                $betRecord = new $this->betRecordClass([
                    'bet_id' => $betId,
                    'username' => $username,
                    'game_code' => 'sport',
                    'result' => $this->result[$params['result']],
                    'status' => 1, // 下注狀態
                    'bet' => $betAmount,
                    'valid_bet' => $betAmount,
                    'de_bet' => $betAmount,
                    'win_lose' => $winLose,
                    'rolling_chips' => 0,
                    'bet_time' => Carbon::parse($params['adddate'])->toSystemTime()->toDateTimeString(),
                    'result_date' => '1970-01-01 00:00:00',
                    'settle_date' => Carbon::parse($params['settle_date'])->toSystemTime()->toDateTimeString(),
                    'order_date' => Carbon::parse($params['orderdate'])->toSystemTime()->toDateTimeString(),
                    'infos' => json_encode($params),
                    // 'infos' => $this->appendParamsToInfo($betRecord->infos, $params),
                ]);
                // 更新用戶餘額
                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
                $betRecord->save();

                // 寫入全部遊戲紀錄
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                return [
                    'status' => 'success',
                    'wagerid' => $betId,
                    'respcode' => '0000',
                ];
            });
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . __FUNCTION__ . ' error: ' . $e->getMessage(), $params);

            return [
                'status' => 'failed',
                'wagerid' => $params['wagerid'],
                'respcode' => '9999',
            ];
        }
    }

    /**
     * 取消下注 (返還投注金額)
     */
    private function cancelBet(array $params)
    {
        try {
            // 會員解析是一般讀，放在交易外；進交易第一個 SQL 才能是鎖會員列（target/tickets#4297）
            $this->member = $this->getMemberByThirdPartyUsername($params['username']);

            return DB::transaction(function () use ($params) {
                // 初始化所有變數
                $username = $params['username'];
                $betId = $params['wagerid'];

                // 取得用戶真金跟禮碼（交易內第一個 SQL：鎖會員列）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

                // 查詢注單（加鎖，避免重複處理）
                try {
                    $oldBetRecord = $this->betRecordClass::lockForUpdate()->where('bet_id', $betId)->first();
                } catch (Throwable $e) {
                    // 拿不到鎖，返回處理中
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0008', // 處理中
                    ];
                }

                if (!$oldBetRecord) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0003', // 無效的注單 id
                    ];
                }

                // ! 也許應該只有 status == 1 的注單可以取消，需跟三方確認
                if ($oldBetRecord->status >= 4) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0011', // 已結算
                    ];
                }

                // 更動注單
                $betRecord = clone $oldBetRecord;
                $betRecord->bet = 0;
                $betRecord->valid_bet = 0;
                $betRecord->de_bet = 0;
                $betRecord->win_lose = 0;
                $betRecord->status = 2;
                // $betRecord->result_date = Carbon::parse($params['resultdate'])->toBackendTime()->toDateTimeString();
                $betRecord->settle_date = Carbon::parse($params['settle_date'])->toSystemTime()->toDateTimeString();
                $betRecord->order_date = Carbon::parse($params['adddate'])->toSystemTime()->toDateTimeString();
                $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);
                // 更新用戶餘額
                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

                $betRecord->save();

                // 寫入全部遊戲紀錄
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                return [
                    'status' => 'success',
                    'wagerid' => $betId,
                    'respcode' => '0000',
                ];
            });
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . __FUNCTION__ . ' error: ' . $e->getMessage(), $params);

            return [
                'status' => 'failed',
                'wagerid' => $params['wagerid'],
                'respcode' => '9999',
            ];
        }
    }

    /**
     * 還原下注 (檢查餘額，扣錢，更新注單)
     */
    private function revertBet(array $params)
    {
        try {
            // 會員解析是一般讀，放在交易外；進交易第一個 SQL 才能是鎖會員列（target/tickets#4297）
            $this->member = $this->getMemberByThirdPartyUsername($params['username']);

            return DB::transaction(function () use ($params) {
                // 初始化所有變數
                $username = $params['username'];
                $betId = $params['wagerid'];
                $betAmount = Common::numDiv($params['degold'], $this->vendorInfo->rate, 4);
                $winAmount = 0;
                $winLose = Common::numSub($winAmount, $betAmount, 4);

                // 取得用戶真金跟禮碼（交易內第一個 SQL：鎖會員列）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
                $balance = $walletDto->getTotalBalance();

                // 檢查餘額
                if (Common::numCompare($balance, $betAmount) < 0) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '5102', // 餘額不足
                    ];
                }

                // 查詢注單（加鎖，避免重複處理）
                try {
                    $oldBetRecord = $this->betRecordClass::lockForUpdate()->where('bet_id', $betId)->first();
                } catch (Throwable $e) {
                    // 拿不到鎖，返回處理中
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0008', // 處理中
                    ];
                }

                if (!$oldBetRecord) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0003', // 無效的注單 id
                    ];
                }

                // ! 也許應該只有 status == 2 的注單可以還原，需跟三方確認
                if ($oldBetRecord->status >= 4) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0011', // 已結算
                    ];
                }

                // 更動注單
                $betRecord = clone $oldBetRecord;
                $betRecord->bet = $betAmount;
                $betRecord->valid_bet = $betAmount;
                $betRecord->de_bet = $betAmount;
                $betRecord->win_lose = $winLose;
                $betRecord->status = 3;
                // $betRecord->result_date = Carbon::parse($params['resultdate'])->toSystemTime()->toDateTimeString();
                $betRecord->settle_date = Carbon::parse($params['settle_date'])->toSystemTime()->toDateTimeString();
                $betRecord->order_date = Carbon::parse($params['adddate'])->toSystemTime()->toDateTimeString();
                $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);
                // 更新用戶餘額
                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);

                $betRecord->save();

                // 寫入全部遊戲紀錄
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                return [
                    'status' => 'success',
                    'wagerid' => $betId,
                    'respcode' => '0000',
                ];
            });
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . __FUNCTION__ . ' error: ' . $e->getMessage(), $params);

            return [
                'status' => 'failed',
                'wagerid' => $params['wagerid'],
                'respcode' => '9999',
            ];
        }
    }

    /**
     * 結算派彩
     */
    private function settletBet(array $params)
    {
        try {
            // 會員解析是一般讀，放在交易外；進交易第一個 SQL 才能是鎖會員列（target/tickets#4297）
            $this->member = $this->getMemberByThirdPartyUsername($params['username']);

            return DB::transaction(function () use ($params) {
                // 初始化所有變數
                $username = $params['username'];
                $betId = $params['wagerid'];
                $betAmount = Common::numDiv($params['degold'], $this->vendorInfo->rate, 4);
                $winAmount = Common::numDiv($params['wingold'], $this->vendorInfo->rate, 4);
                $winLose = Common::numSub($winAmount, $betAmount, 4);

                // 取得用戶真金跟禮碼（交易內第一個 SQL：鎖會員列）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

                // 查詢注單（加鎖，避免重複處理）
                try {
                    $oldBetRecord = $this->betRecordClass::lockForUpdate()->where('bet_id', $betId)
                        ->whereIn('result', [1, 2, 3])
                        ->first();
                } catch (Throwable $e) {
                    // 拿不到鎖，返回處理中
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0008', // 處理中
                    ];
                }

                if (!$oldBetRecord) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0003', // 無效的注單 id
                    ];
                }

                if ($oldBetRecord->status >= 4) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0011', // 已結算
                    ];
                }

                // 更動注單
                $betRecord = clone $oldBetRecord;
                $betRecord->bet = $betAmount;
                $betRecord->valid_bet = $betAmount;
                $betRecord->de_bet = $betAmount;
                $betRecord->win_lose = $winLose;
                $betRecord->status = 4;
                $betRecord->result_date = Carbon::parse($params['resultdate'])->toSystemTime()->toDateTimeString();
                $betRecord->settle_date = Carbon::parse($params['settle_date'])->toSystemTime()->toDateTimeString();
                $betRecord->order_date = Carbon::parse($params['adddate'])->toSystemTime()->toDateTimeString();
                $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);
                // 更新用戶餘額
                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
                $betRecord->save();

                // 寫入全部遊戲紀錄
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                return [
                    'status' => 'success',
                    'wagerid' => $betId,
                    'respcode' => '0000',
                ];
            });
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . __FUNCTION__ . ' error: ' . $e->getMessage(), $params);

            return [
                'status' => 'failed',
                'wagerid' => $params['wagerid'],
                'respcode' => '9999',
            ];
        }
    }

    /**
     * 重新結算派彩
     */
    private function unsettleBet(array $params)
    {
        try {
            // 會員解析是一般讀，放在交易外；進交易第一個 SQL 才能是鎖會員列（target/tickets#4297）
            $this->member = $this->getMemberByThirdPartyUsername($params['username']);

            return DB::transaction(function () use ($params) {
                // 初始化所有變數
                $username = $params['username'];
                $betId = $params['wagerid'];
                $betAmount = Common::numDiv($params['degold'], $this->vendorInfo->rate, 4);
                $winAmount = Common::numDiv($params['wingold'], $this->vendorInfo->rate, 4);
                $winLose = Common::numSub($winAmount, $betAmount, 4);

                // 取得用戶真金跟禮碼（交易內第一個 SQL：鎖會員列）
                $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);

                // 查詢注單（加鎖，避免重複處理）
                try {
                    $oldBetRecord = $this->betRecordClass::lockForUpdate()->where('bet_id', $betId)
                        ->whereIn('result', [1, 2, 3])
                        ->first();
                } catch (Throwable $e) {
                    // 拿不到鎖，返回處理中
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0008', // 處理中
                    ];
                }

                if (!$oldBetRecord) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0003', // 無效的注單 id
                    ];
                }

                // ! 僅能調整已結算的注單
                if ($oldBetRecord->status < 4) {
                    return [
                        'status' => 'failed',
                        'wagerid' => $betId,
                        'respcode' => '0014', // 查無已有結果注單
                    ];
                }

                // 更動注單
                $betRecord = clone $oldBetRecord;
                $betRecord->bet = $betAmount;
                $betRecord->valid_bet = $betAmount;
                $betRecord->de_bet = $betAmount;
                $betRecord->win_lose = $winLose;
                $betRecord->status = 4;
                $betRecord->result_date = Carbon::parse($params['resultdate'])->toSystemTime()->toDateTimeString();
                $betRecord->settle_date = Carbon::parse($params['settle_date'])->toSystemTime()->toDateTimeString();
                $betRecord->order_date = Carbon::parse($params['adddate'])->toSystemTime()->toDateTimeString();
                $betRecord->infos = $this->appendParamsToInfo($betRecord->infos, $params);
                // 更新用戶餘額
                $transactionLogId = $this->changeBalance($this->member, $walletDto, $oldBetRecord, $betRecord);
                $betRecord->save();

                // 寫入全部遊戲紀錄
                $totalBetRecordService = new TotalBetRecordService($this->vendorId, $this->member->id, $walletDto->isMultipleWallet);
                $totalBetRecordService->create($betRecord, $transactionLogId);

                return [
                    'status' => 'success',
                    'wagerid' => $betId,
                    'respcode' => '0000',
                ];
            });
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . __FUNCTION__ . ' error: ' . $e->getMessage(), $params);

            return [
                'status' => 'failed',
                'wagerid' => $params['wagerid'],
                'respcode' => '9999',
            ];
        }
    }
}
