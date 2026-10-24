<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Enums\VendorEnum;
use App\Enums\TransactionEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\Agent;
use App\Models\TransactionLog;
use App\Models\PgsswBetRecord;
use App\Models\TotalBetRecord;
use App\Models\WalletType;
use App\Models\AgentWallet;
use App\Models\MemberWallet;
use Hash;
use DB;

class PgsController extends Controller
{
    protected $vendor_id = 0;
    protected $error = array();

    public function __construct()
    {
        $this->vendor_id = VendorEnum::PGSSW;

        $this->error['401002'] = 'member not exist';
        $this->error['403001'] = 'agent status error';
        $this->error['403203'] = 'member balance not enough';
    }

    /**
     * /players/wallet
     * -5.1.1 取得遊戲錢包金額
     *
     * @bodyParam sessionToken string token Example:xxx
     * @response {
     *  "code": "成功回應0，其他錯誤代碼自訂",
     *  "data": "錢包金額或錯誤訊息"
     * }
     */
    public function getBalance(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'header' => $request->header('X-target-IDENTITY'),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'pgssw_request_get_balance');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }
            $data = array();
            //檢查sessionToken跟identity
            $info = $this->checkSessionTokenAndIdentity(
                $param['sessionToken'],
                $request->header('X-target-IDENTITY')
            );
            if (is_array($info) && $info['code'] == 0) {
                //正確的話就回傳帳號資料
                $account = $info['data'];
                $rate = $info['rate'];
                $member = Member::find($account->member_id);
                if (is_object($member)) {
                    $agent = Agent::find($member->parent);
                    if ($member->wallet_type_id == $info['wallet_type_id']) {
                        $wallet = $this->getMemberWallet(
                            $member->id,
                            $info['wallet_type_id'],
                            $agent->cash_flow_group_id
                        );
                        if (is_object($wallet)) {
                            $code = 0;
                            $data['balance'] = $wallet->balance * $rate;
                        } else {
                            //查無玩家該錢包種類
                            $code = 401002;
                            $data['message'] = $this->error[$code];
                        }
                    } else {
                        //帳號與當前錢包類別不同
                        $code = 401002;
                        $data['message'] = $this->error[$code];
                    }
                } else {
                    $code = 401002;
                    $data['message'] = $this->error[$code];
                }
            } else {
                //錯誤的話回傳錯誤訊息
                $code = $info['code'];
                $data['message'] = $info['message'];
            }

            return response()->json([
                'code' => $code,
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * /bet/placeBet
     * -5.1.1 取得遊戲錢包金額
     *
     * @bodyParam sessionToken string token Example:xxx
     * @response {
     *  "code": "成功回應0，其他錯誤代碼自訂",
     *  "data": "錢包金額或錯誤訊息"
     * }
     */
    public function placeBet(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'header' => $request->header('X-target-IDENTITY'),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'pgs_request_placeBet');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }
            $data = array();
            //檢查sessionToken跟identity
            $info = $this->checkSessionTokenAndIdentity(
                $param['sessionToken'],
                $request->header('X-target-IDENTITY')
            );
            if (is_array($info) && $info['code'] == 0) {
                $account = $info['data']; //遊戲商帳號資訊
                $vendor_info_id = $info['vendor_info_id']; //遊戲商商戶資料ID
                $rate = $info['rate']; //平台點數與遊戲點數的比例
                $platform_point = ($param['betAmount'] / $rate); //平台點數投注金額
                $session_token = [REDACTED]'sessionToken'];
                DB::beginTransaction();
                $member = Member::lockForUpdate()->find($account->member_id);
                //確認有沒有這個玩家
                if (is_object($member)) {
                    if ($this->checkGame($member->parent, $vendor_info_id, $param['gameID'])) {
                        //檢查玩家是否為可以投注的狀態
                        if ($member->status == 1 && $member->event_lock == 0) {
                            //檢查玩家的代理是否為正常狀態
                            if ($this->checkAgentStatus($member->parent)) {
                                $agent = Agent::find($member->parent);
                                $wallet = $this->getMemberWallet(
                                    $member->id,
                                    $info['wallet_type_id'],
                                    $agent->cash_flow_group_id
                                );
                                $member_wallet = MemberWallet::lockForUpdate()->find($wallet->id);
                                $before_balance = $member_wallet->balance;
                                $sessionToken = [REDACTED]'sessionToken'];
                                //先對玩家錢包做更改
                                $updateMemberWalletBalance = $this->updateMemberWalletBalance($member_wallet, $platform_point);
                                if ($updateMemberWalletBalance) {
                                    //檢查該玩家最後一筆transaction_logs,並新增一筆pgssw_bet_records
                                    $transaction_log = TransactionLog::where('member_id', $account->member_id)
                                        ->orderBy('id', 'DESC')
                                        ->first();
                                    if (is_object($transaction_log)) {
                                        //檢查最後一筆是不是單錢包的投注
                                        if ($transaction_log->type == TransactionEnum::TYPE_SINGLE_WALLET_BET) {
                                            //判斷錢包類型是否相同
                                            if ($info['wallet_type_id'] == $transaction_log->wallet_type_id) {
                                                //如果是單錢包投注的紀錄就檢查有沒有鎖定
                                                if ($transaction_log->status == 1000) {
                                                    //已鎖定的訂單就新創一筆新的transaction_logs
                                                    $log = $this->createLogData(
                                                        $member,
                                                        $member_wallet,
                                                        $before_balance,
                                                        $rate,
                                                        $platform_point,
                                                        $session_token
                                                    );
                                                    $createTransactionLog = TransactionLog::create($log);
                                                    if (!$createTransactionLog) {
                                                        DB::rollback();
                                                        return response()->json([
                                                            "code" => 500001,
                                                            "data" => 'system error 1'
                                                        ]);
                                                    }
                                                } else {
                                                    //未鎖定的訂單就檢查session_token
                                                    if ($transaction_log->remark == $session_token) {
                                                        //一樣,直接更新transaction_logs
                                                        $new_point = $transaction_log->point + $platform_point;

                                                        $updateData = array();
                                                        $updateData['point'] = $new_point;
                                                        $updateData['vendor_point'] = $new_point * $rate;
                                                        $updateData['balance'] = $member_wallet->balance;
                                                        $updateData['vendor_balance'] = $member->balance * $rate;

                                                        $transaction_log->update($updateData);
                                                    } else {
                                                        //不一樣,把這筆訂單鎖定並且新創一筆transaction_logs
                                                        $transaction_log->update(['status' => 1000]);

                                                        $log = $this->createLogData(
                                                            $member,
                                                            $member_wallet,
                                                            $before_balance,
                                                            $rate,
                                                            $platform_point,
                                                            $session_token
                                                        );
                                                        $createTransactionLog = TransactionLog::create($log);
                                                        if (!$createTransactionLog) {
                                                            DB::rollback();
                                                            return response()->json([
                                                                "code" => 500001,
                                                                "data" => 'system error 2'
                                                            ]);
                                                        }
                                                    }
                                                }
                                            } else {
                                                //不是相同錢包類型就新創一筆新的transaction_logs
                                                $log = $this->createLogData(
                                                    $member,
                                                    $member_wallet,
                                                    $before_balance,
                                                    $rate,
                                                    $platform_point,
                                                    $session_token
                                                );
                                                $createTransactionLog = TransactionLog::create($log);
                                                if (!$createTransactionLog) {
                                                    DB::rollback();
                                                    return response()->json([
                                                        "code" => 500001,
                                                        "data" => 'system error 1'
                                                    ]);
                                                }
                                            }
                                        } else {
                                            //不是單錢包投注紀錄就檢查之前有無未鎖定的單一錢包紀錄，並創建一筆transaction_logs
                                            $last_sw_transaction_log = TransactionLog::where('member_id', $account->member_id)
                                                ->where('vendor_id', $this->vendor_id)
                                                ->where('wallet_type_id', $member_wallet->wallet_type_id)
                                                ->where('type', TransactionEnum::TYPE_SINGLE_WALLET_BET)
                                                ->where('status', 999)
                                                ->orderBy('id', 'DESC')
                                                ->first();
                                            if (is_object($last_sw_transaction_log)) {
                                                //如果有就直接改乘鎖定
                                                $last_sw_transaction_log->update(['status' => 1000]);
                                            }

                                            $log = $this->createLogData(
                                                $member,
                                                $member_wallet,
                                                $before_balance,
                                                $rate,
                                                $platform_point,
                                                $session_token
                                            );
                                            $createTransactionLog = TransactionLog::create($log);
                                            if (!$createTransactionLog) {
                                                DB::rollback();
                                                return response()->json([
                                                    'code' => 500001,
                                                    'data' => 'system error 3'
                                                ]);
                                            }
                                        }
                                    } else {
                                        //完全沒有transaction_logs,就直接新創一筆
                                        $log = $this->createLogData(
                                            $member,
                                            $member_wallet,
                                            $before_balance,
                                            $rate,
                                            $platform_point,
                                            $session_token
                                        );
                                        $createTransactionLog = TransactionLog::create($log);
                                        if (!$createTransactionLog) {
                                            DB::rollback();
                                            return response()->json([
                                                'code' => 500001,
                                                'data' => 'system error 4'
                                            ]);
                                        }
                                    }
                                    $record = $this->createBetRecordData($account->vendor_info_id, $param, $sessionToken);
                                    $pgssw_bet_record = PgsswBetRecord::create($record);
                                    if (!$pgssw_bet_record) {
                                        $code = 500001;
                                        $data['message'] = 'system error 5';
                                    } else {
                                        DB::commit();
                                        $code = 0;
                                        $data['transactionNo'] = $param['transactionNo'];
                                        $data['balance'] = $member_wallet->balance;
                                    }
                                } else {
                                    $code = 403203;
                                    $data['message'] = 'member balance not enough';
                                }
                            } else {
                                $code = 403001;
                                $data['message'] = 'agent status error';
                            }
                        } else {
                            $code = 403001;
                            $data['message'] = 'member status error';
                        }
                    } else {
                        $code = 403001;
                        $data['message'] = 'game not exist';
                    }
                } else {
                    $code = 3;
                    $data['message'] = 'member not exist';
                }
            } else {
                //錯誤的話回傳錯誤訊息
                $code = $info['code'];
                $data['message'] = $info['message'];
            }

            if ($code != 0) {
                DB::rollback();
            }

            return response()->json([
                'code' => $code,
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json(['code' => $info[0],
                'message' => $info[2]]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * /bet/settle
     * -5.1.1 遊戲結算(一般下注)
     *
     * @bodyParam sessionToken string token Example:xxx
     * @response {
     *  "code": "成功回應0，其他錯誤代碼自訂",
     *  "data": "錢包金額或錯誤訊息"
     * }
     */
    public function settle(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'header' => $request->header('X-target-IDENTITY'),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'pgs_request_settle');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }
            $data = array();
            //先由投注的ID找到是哪一筆注單
            $bet_record = PgsswBetRecord::where('bet_id', $param['transactionNo'])->first();
            if (is_object($bet_record) && $bet_record->status == 0) {
                //再由注單來找到是哪個帳號
                $account = DB::table('pgssw_accounts')->where('username', $bet_record->username)->first();
                if (is_object($account)) {
                    $info = $this->checkIdentityAndGetVendorInfo($account, $request->header('X-target-IDENTITY'));
                    if (is_array($info) && $info['code'] == 0) {
                        $rate = $info['rate']; //平台點數與遊戲點數的比例
                        $platform_point = (($param['payoutAmount'] / $rate) * -1); //平台點數投注金額
                        $session_token = [REDACTED];
                        DB::beginTransaction();
                        $member = Member::lockForUpdate()->find($account->member_id);
                        $agent = Agent::find($member->parent);
                        $wallet = $this->getMemberWallet(
                            $member->id,
                            $info['wallet_type_id'],
                            $agent->cash_flow_group_id
                        );
                        $member_wallet = MemberWallet::lockForUpdate()->find($wallet->id);
                        $before_balance = $member_wallet->balance;
                        //先對玩家錢包做更改
                        $updateMemberBalance = $this->updateMemberWalletBalance($member_wallet, $platform_point);
                        if ($updateMemberBalance) {
                            //檢查該玩家最後一筆transaction_logs,並新增一筆pgssw_bet_records
                            $transaction_log = TransactionLog::where('member_id', $account->member_id)
                                ->orderBy('id', 'DESC')
                                ->first();
                            if (is_object($transaction_log)) {
                                //檢查最後一筆是不是單錢包的投注
                                if ($transaction_log->type == TransactionEnum::TYPE_SINGLE_WALLET_BET) {
                                    //判斷錢包類型是否相同
                                    if ($info['wallet_type_id'] == $transaction_log->wallet_type_id) {
                                        //是單錢包的紀錄就檢查status
                                        if ($transaction_log->status == 1000) {
                                            //如果是鎖定就創一筆新的transaction_logs
                                            $log = $this->createLogData(
                                                $member,
                                                $member_wallet,
                                                $before_balance,
                                                $rate,
                                                $platform_point,
                                                $session_token
                                            );
                                            $createTransactionLog = TransactionLog::create($log);
                                            if (!$createTransactionLog) {
                                                DB::rollback();
                                                return response()->json([
                                                    "code" => 500001,
                                                    "data" => 'system error 1'
                                                ]);
                                            }
                                        } else {
                                            //如果不是鎖定就檢查session_token是否相同
                                            if ($transaction_log->remark == $bet_record->session_token) {
                                                //相同就直接更新
                                                $new_point = $transaction_log->point + $platform_point;

                                                $updateData = array();
                                                $updateData['point'] = $new_point;
                                                $updateData['vendor_point'] = $new_point * $rate;
                                                $updateData['balance'] = $member_wallet->balance;
                                                $updateData['vendor_balance'] = $member_wallet->balance * $rate;

                                                $transaction_log->update($updateData);
                                            } else {
                                                //沒鎖定就直接鎖定,並創一筆新的transaction_log
                                                $transaction_log->update(['status' => 1000]);

                                                $log = $this->createLogData(
                                                    $member,
                                                    $member_wallet,
                                                    $before_balance,
                                                    $rate,
                                                    $platform_point,
                                                    $session_token
                                                );
                                                $createTransactionLog = TransactionLog::create($log);
                                                if (!$createTransactionLog) {
                                                    DB::rollback();
                                                    return response()->json([
                                                        "code" => 500001,
                                                        "data" => 'system error 2'
                                                    ]);
                                                }
                                            }
                                        }
                                    } else {
                                        //如果是不同錢包類型就創一筆新的transaction_logs
                                        $log = $this->createLogData(
                                            $member,
                                            $member_wallet,
                                            $before_balance,
                                            $rate,
                                            $platform_point,
                                            $session_token
                                        );
                                        $createTransactionLog = TransactionLog::create($log);
                                        if (!$createTransactionLog) {
                                            DB::rollback();
                                            return response()->json([
                                                "code" => 500001,
                                                "data" => 'system error 1'
                                            ]);
                                        }
                                    }
                                } else {
                                    //不是單錢包投注紀錄就檢查之前有無未鎖定的單一錢包紀錄，並創建一筆transaction_logs
                                    $last_sw_transaction_log = TransactionLog::where('member_id', $account->member_id)
                                        ->where('vendor_id', $this->vendor_id)
                                        ->where('wallet_type_id', $member_wallet->wallet_type_id)
                                        ->where('status', 999)
                                        ->where('type', TransactionEnum::TYPE_SINGLE_WALLET_BET)
                                        ->orderBy('id', 'DESC')
                                        ->first();
                                    if (is_object($last_sw_transaction_log)) {
                                        //如果有就直接改乘鎖定
                                        $last_sw_transaction_log->update(['status' => 1000]);
                                    }

                                    $log = $this->createLogData(
                                        $member,
                                        $member_wallet,
                                        $before_balance,
                                        $rate,
                                        $platform_point,
                                        $session_token
                                    );
                                    $createTransactionLog = TransactionLog::create($log);
                                    if (!$createTransactionLog) {
                                        DB::rollback();
                                        return response()->json([
                                            "code" => 500001,
                                            "data" => 'system error 3'
                                        ]);
                                    }
                                }
                            } else {
                                DB::rollback();
                                return response()->json([
                                    "code" => 500001,
                                    "data" => 'system error 6'
                                ]);
                            }
                        } else {
                            DB::rollback();
                            return response()->json([
                                "code" => 403203,
                                "data" => 'member balance not enough'
                            ]);
                        }
                        $updateRecord = array();
                        $updateRecord['bet'] = $param['validAmount'];
                        $updateRecord['win'] = $param['payoutAmount'];
                        $updateRecord['win_lose'] = ($param['payoutAmount'] - $param['validAmount']);
                        $updateRecord['payout_time'] = $param['settlementTime'];
                        $bet_info = json_decode($bet_record->info, true);
                        $new_info = json_encode([$bet_info, $param]);
                        $updateRecord['info'] = $new_info;
                        $updateRecord['status'] = 1; //已結算
                        $updatePgsswBetRecord = $bet_record->update($updateRecord);

                        if (!$updatePgsswBetRecord) {
                            DB::rollback();
                            $code = 500001;
                            $data['message'] = 'system error 5';
                        } else {
                            $totalBetRecordData = $this->createTotalBetRecordData($bet_record, $member->id);
                            TotalBetRecord::create($totalBetRecordData);
                            DB::commit();
                            $code = 0;
                            $data['transactionNo'] = $param['transactionNo'];
                            $data['balance'] = $member_wallet->balance;
                        }
                    } else {
                        //錯誤的話回傳錯誤訊息
                        $code = $info['code'];
                        $data['message'] = $info['message'];
                    }
                } else {
                    $code = 403601;
                    $data['message'] = 'member not exist';
                }
            } else {
                $code = 403601;
                $data['message'] = 'transactionNo not exist or has settle';
            }

            return response()->json([
                "code" => $code,
                "data" => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json(['code' => $info[0],
                'message' => $info[2]]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * /bonus/settle
     * -5.1.1 遊戲結算(一般下注)
     *
     * @bodyParam sessionToken string token Example:xxx
     * @response {
     *  "code": "成功回應0，其他錯誤代碼自訂",
     *  "data": "錢包金額或錯誤訊息"
     * }
     */
    public function bonus(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'header' => $request->header('X-target-IDENTITY'),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'pgs_request_bonus');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }
            $data = array();
            //用playerUniqueID來找到是哪個帳號
            $account = DB::table('pgssw_accounts')->where('username', $param['playerUniqueID'])->first();
            if (is_object($account)) {
                $info = $this->checkIdentityAndGetVendorInfo($account, $request->header('X-target-IDENTITY'));
                if (is_array($info) && $info['code'] == 0) {
                    $rate = $info['rate']; //平台點數與遊戲點數的比例
                    $platform_point = (($param['payoutAmount'] / $rate) * -1); //平台點數投注金額
                    DB::beginTransaction();
                    $member = Member::lockForUpdate()->find($account->member_id);
                    $before_balance = $member->balance;
                    //先對玩家錢包做更改
                    $updateMemberBalance = $this->updateMemberBalance($member, $platform_point);
                    if ($updateMemberBalance) {
                        //檢查該玩家最後一筆transaction_logs,並新增一筆pgssw_bet_records
                        $transaction_log = TransactionLog::where('member_id', $account->member_id)
                            ->orderBy('id', 'DESC')
                            ->first();
                        if (is_object($transaction_log)) {
                            //檢查最後一筆是不是單錢包的投注
                            if ($transaction_log->type == 27) {
                                $sessionToken = [REDACTED];
                                //是單錢包的紀錄就檢查status
                                if ($transaction_log->status == 1000) {
                                    //如果是鎖定就創一筆新的transaction_logs
                                    $log = $this->createLogData(
                                        $member,
                                        $before_balance,
                                        $rate,
                                        $platform_point,
                                        $transaction_log->remark
                                    );
                                    $createTransactionLog = TransactionLog::create($log);
                                    if (!$createTransactionLog) {
                                        DB::rollback();
                                        return response()->json([
                                            "code" => 6,
                                            "data" => 'system error 1'
                                        ]);
                                    }
                                } else {
                                    //如果不是鎖定就直接更新
                                    $new_point = $transaction_log->point + $platform_point;

                                    $updateData = array();
                                    $updateData['point'] = $new_point;
                                    $updateData['vendor_point'] = $new_point * $rate;
                                    $updateData['balance'] = $member->balance;
                                    $updateData['vendor_balance'] = $member->balance * $rate;

                                    $transaction_log->update($updateData);
                                }
                            } else {
                                //不是單錢包投注紀錄就檢查之前有沒有單錢包的紀錄，並創建一筆transaction_logs
                                $last_sw_transaction_log = TransactionLog::where('member_id', $account->member_id)
                                    ->where('vendor_id', $this->vendor_id)
                                    ->where('type', 27)
                                    ->orderBy('id', 'DESC')
                                    ->first();

                                if (is_object($last_sw_transaction_log)) {
                                    $sessionToken = [REDACTED];
                                    //如果有就檢查有沒有鎖定
                                    if ($last_sw_transaction_log->status != 1000) {
                                        //沒鎖定就直接鎖定
                                        $last_sw_transaction_log->update(['status' => 1000]);
                                    }
                                } else {
                                    DB::rollback();
                                    return response()->json([
                                        "code" => 500001,
                                        "data" => 'system error 3'
                                    ]);
                                }

                                $log = $this->createLogData(
                                    $member,
                                    $before_balance,
                                    $rate,
                                    $param,
                                    $platform_point,
                                    $last_sw_transaction_log->remark
                                );
                                $createTransactionLog = TransactionLog::create($log);
                                if (!$createTransactionLog) {
                                    DB::rollback();
                                    return response()->json([
                                        "code" => 500001,
                                        "data" => 'system error 3'
                                    ]);
                                }
                            }
                        } else {
                            DB::rollback();
                            return response()->json([
                                "code" => 500001,
                                "data" => 'system error 6'
                            ]);
                        }
                    } else {
                        DB::rollback();
                        return response()->json([
                            "code" => 403203,
                            "data" => 'member balance not enough'
                        ]);
                    }
                    $record = $this->createBetRecordData($account->vendor_info_id, $param, $sessionToken, true);
                    $createPgsswBetRecord = PgsswBetRecord::create($record);
                    if (!$createPgsswBetRecord) {
                        DB::rollback();
                        $code = 500001;
                        $data['message'] = 'system error 5';
                    } else {
                        $totalBetRecordData = $this->createTotalBetRecordData($createPgsswBetRecord, $member->id);
                        TotalBetRecord::create($totalBetRecordData);
                        DB::commit();
                        $code = 0;
                        $data['transactionNo'] = $param['transactionNo'];
                        $data['balance'] = $member->balance;
                    }
                } else {
                    //錯誤的話回傳錯誤訊息
                    $code = $info['code'];
                    $data['message'] = $info['message'];
                }
            } else {
                $code = 401002;
                $data['message'] = 'member not exist';
            }

            return response()->json([
                "code" => $code,
                "data" => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json(['code' => $info[0],
                'message' => $info[2]]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * b/et/cancel
     * -5.1.1 取消投注
     *
     * @bodyParam sessionToken string token Example:xxx
     * @response {
     *  "code": "成功回應0，其他錯誤代碼自訂",
     *  "data": "錢包金額或錯誤訊息"
     * }
     */
    public function cancel(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'header' => $request->header('X-target-IDENTITY'),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'pgs_request_get_balance');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }
            $data = array();
            $bet_record = PgsswBetRecord::where('bet_id', $param['transactionNo'])->first();
            if (is_object($bet_record)) {
                $account = DB::table('pgssw_accounts')->where('username', $bet_record->username)->first();
                if (is_object($account)) {
                    $info = $this->checkIdentityAndGetVendorInfo($account, $request->header('X-target-IDENTITY'));
                    if (is_array($info) && $info['code'] == 0) {
                        $rate = $info['rate']; //平台點數與遊戲點數的比例
                        $platform_point = (($bet_record->bet / $rate) * -1); //平台點數投注金額
                        $session_token = [REDACTED];
                        DB::beginTransaction();
                        $member = Member::lockForUpdate()->find($account->member_id);
                        $before_balance = $member->balance;
                        //先對玩家錢包做更改
                        $updateMemberBalance = $this->updateMemberBalance($member, $platform_point);
                        if ($updateMemberBalance) {
                            $last_sw_transaction_log = TransactionLog::where('member_id', $account->member_id)
                                ->where('vendor_id', $this->vendor_id)
                                ->where('type', 27)
                                ->orderBy('id', 'DESC')
                                ->first();
                            if (is_object($last_sw_transaction_log)) {
                                //如果有就檢查有沒有鎖定
                                if ($last_sw_transaction_log->status != 1000) {
                                    //沒鎖定就直接鎖定
                                    $last_sw_transaction_log->update(['status' => 1000]);
                                }
                            } else {
                                DB::rollback();
                                return response()->json([
                                    "code" => 403001,
                                    "data" => 'system error 3'
                                ]);
                            }
                        } else {
                            DB::rollback();
                            return response()->json([
                                "code" => 403203,
                                "data" => 'member balance not enough'
                            ]);
                        }
                        $log = array();
                        $log['transaction_no'] = $this->getTransactionNo();
                        $log['agent_id'] = $member->parent;
                        $log['member_id'] = $member->id;
                        $log['vendor_id'] = $this->vendor_id; //pgssw
                        $log['order_id'] = 0;
                        $log['point'] = $platform_point;
                        $log['vendor_point'] = $platform_point * $rate;
                        $log['rate'] = $rate;
                        $log['before_balance'] = $before_balance;
                        $log['balance'] = $member->balance;
                        $log['vendor_balance'] = $member->balance * $rate;
                        $log['type'] = 28;//單錢包遊戲取消注單補點
                        $log['operator'] = '0';
                        $log['status'] = 1000; //未鎖定 0
                        $log['remark'] = $session_token;

                        $createTransactionLog = TransactionLog::create($log);
                        if (!$createTransactionLog) {
                            DB::rollback();
                            return response()->json([
                                "code" => 500001,
                                "data" => 'system error 2'
                            ]);
                        }
                        $bet_info = json_decode($bet_record->info, true);
                        $new_info = json_encode([$bet_info, $param]);
                        $updateBetRecord = $bet_record->update(['status' => 9, 'info' => $new_info]);
                        if (!$updateBetRecord) {
                            DB::rollback();
                            return response()->json([
                                "code" => 500001,
                                "data" => 'system error 1'
                            ]);
                        } else {
                            DB::commit();
                            $code = 0;
                            $data['transactionNo'] = $param['transactionNo'];
                        }

                    } else {
                        //錯誤的話回傳錯誤訊息
                        $code = $info['code'];
                        $data['message'] = $info['message'];
                    }
                } else {
                    $bet_info = json_decode($bet_record->info, true);
                    $new_info = json_encode([$bet_info, $param]);
                    $updateBetRecord = $bet_record->update(['status' => 9, 'info' => $new_info]);
                    $code = 0;
                    $data['transactionNo'] = $param['transactionNo'];
                }
            } else {
                $code = 404016;
                $data['message'] = 'transactionNo not exist or has settle';
            }
            return response()->json([
                "code" => $code,
                "data" => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * b/et/cancel
     * -5.1.1 取消投注
     *
     * @bodyParam sessionToken string token Example:xxx
     * @response {
     *  "code": "成功回應0，其他錯誤代碼自訂",
     *  "data": "錢包金額或錯誤訊息"
     * }
     */
    public function settle_change(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'header' => $request->header('X-target-IDENTITY'),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'pgs_request_get_balance');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }
            $data = array();
            $bet_record = PgsswBetRecord::where('bet_id', $param['transactionNo'])->first();
            if (is_object($bet_record) && $bet_record->status == 1) {
                $account = DB::table('pgssw_accounts')->where('username', $bet_record->username)->first();
                if (is_object($account)) {
                    $info = $this->checkIdentityAndGetVendorInfo($account, $request->header('X-target-IDENTITY'));
                    if (is_array($info) && $info['code'] == 0) {
                        $rate = $info['rate']; //平台點數與遊戲點數的比例
                        $differenceBetAmount = $bet_record->bet - $param['afterBetAmount'];
                        $differencePayoutAmount = $bet_record->win - $param['afterBetAmount'];
                        $updateValue = $differenceBetAmount - $differencePayoutAmount; //遊戲商點數金額
                        $updateAmount = $updateValue * $rate; //平台點數投注金額
                        DB::beginTransaction();
                        $member = Member::lockForUpdate()->find($account->member_id);
                        $before_balance = $member->balance;
                        //先加玩家的錢包
                        $member->update([
                            'balance' => ($member->balance + $updateAmount)
                        ]);
                        $last_sw_transaction_log = TransactionLog::where('member_id', $account->member_id)
                            ->where('type', 27)
                            ->orderBy('id', 'DESC')
                            ->first();
                        if (is_object($last_sw_transaction_log)) {
                            //如果有就檢查有沒有鎖定
                            if ($last_sw_transaction_log->status != 1000) {
                                //沒鎖定就直接鎖定
                                $last_sw_transaction_log->update(['status' => 1000]);
                            }
                        } else {
                            DB::rollback();
                            return response()->json([
                                "code" => 0,
                                "data" => ['transactionNo' => $param['transactionNo']]
                            ]);
                        }
                        $log = array();
                        $log['transaction_no'] = $this->getTransactionNo();
                        $log['agent_id'] = $member->parent;
                        $log['member_id'] = $member->id;
                        $log['vendor_id'] = $this->vendor_id; //pgssw
                        $log['order_id'] = 0;
                        $log['point'] = $updateValue * $rate;
                        $log['vendor_point'] = $updateValue;
                        $log['rate'] = $rate;
                        $log['before_balance'] = $before_balance;
                        $log['balance'] = $member->balance;
                        $log['vendor_balance'] = $member->balance * $rate;
                        $log['type'] = 29;//單錢包遊戲結算改單補點
                        $log['operator'] = '0';
                        $log['status'] = 1000; //未鎖定 0
                        $log['remark'] = $bet_record->session_token;

                        $createTransactionLog = TransactionLog::create($log);
                        if (!$createTransactionLog) {
                            DB::rollback();
                            return response()->json([
                                "code" => 0,
                                "data" => ['transactionNo' => $param['transactionNo']]
                            ]);
                        }
                        $updateRecord = array();
                        $updateRecord['bet'] = $param['afterBetAmount'];
                        $updateRecord['win'] = $param['afterPayoutAmount'];
                        $updateRecord['win_lose'] = ($param['afterPayoutAmount'] - $param['afterBetAmount']);
                        $updateRecord['payout_time'] = $param['settlementTime'];
                        $bet_info = json_decode($bet_record->info, true);
                        $new_info = json_encode([$bet_info, $param]);
                        $updateRecord['info'] = $new_info;
                        $updateRecord['status'] = 1; //已結算
                        $updatePgsswBetRecord = $bet_record->update($updateRecord);
                        if (!$updatePgsswBetRecord) {
                            DB::rollback();
                            $code = 6;
                            $data['message'] = 'system error 5';
                        } else {
                            DB::commit();
                            $code = 0;
                            $data['transactionNo'] = $param['transactionNo'];
                        }

                    } else {
                        //錯誤的話回傳錯誤訊息
                        $code = $info['code'];
                        $data['message'] = $info['message'];
                    }
                } else {
                    $updateRecord = array();
                    $updateRecord['bet'] = $param['afterBetAmount'];
                    $updateRecord['win'] = $param['afterPayoutAmount'];
                    $updateRecord['win_lose'] = ($param['afterPayoutAmount'] - $param['afterBetAmount']);
                    $updateRecord['payout_time'] = $param['settlementTime'];
                    $bet_info = json_decode($bet_record->info, true);
                    $new_info = json_encode([$bet_info, $param]);
                    $updateRecord['info'] = $new_info;
                    $updateRecord['status'] = 1; //已結算
                    $bet_record->update($updateRecord);

                    $code = 0;
                    $data['transactionNo'] = $param['transactionNo'];
                }
            } else {
                $code = 7;
                $data['message'] = 'transactionNo not exist or no settle';
            }
            return response()->json([
                "code" => $code,
                "data" => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function getMemberWallet($member_id, $wallet_type_id, $agent_group_id)
    {
        $wallet = DB::table('member_wallets as mw')
            ->join('wallet_types as wt', function ($join) {
                $join->on('wt.id', 'mw.wallet_type_id')
                    ->where('wt.status', 1);
            })
            ->join('agent_wallets as aw', function ($join) use ($agent_group_id) {
                $join->on('aw.wallet_type_id', 'wt.id')
                    ->where('aw.agent_group_id', $agent_group_id)
                    ->where('aw.status', 1);
            })
            ->where('mw.member_id', $member_id)
            ->where('mw.wallet_type_id', $wallet_type_id)
            ->where('mw.status', 1)
            ->select(
                'mw.id',
                'mw.wallet_type_id',
                'mw.balance'
            )
            ->first();
        return $wallet;
    }

    private function checkSessionTokenAndIdentity($sessionToken, $identity)
    {
        $data = array();
        $account = DB::table('pgssw_accounts')->where('session_token', $sessionToken)->first();
        //先確認sessionToken是否正確
        if (is_object($account)) {
            $info = $this->checkIdentityAndGetVendorInfo($account, $identity);
            if (is_array($info) && $info['code'] == 0) {
                $data['code'] = 0;
                $data['message'] = 'success';
                $data['vendor_info_id'] = $info['vendor_info_id'];
                $data['wallet_type_id'] = $info['wallet_type_id'];
                $data['rate'] = $info['rate'];
                $data['data'] = $account;
            } else {
                $data['code'] = $info['code'];
                $data['message'] = $info['message'];
            }
        } else {
            $data['code'] = 401002;
            $data['message'] = 'sessionToken expired';
        }

        return $data;
    }

    private function checkIdentityAndGetVendorInfo($account, $identity)
    {
        $data = array();
        //取得這個玩家的PGS商戶資料
        $setting = DB::table('vendor_infos')
            ->where('id', $account->vendor_info_id)
            ->where('status', 1)
            ->first();
        if (is_object($setting)) {
            $info = json_decode($setting->info);
            //確認header內的X-target-IDENTITY是否正確
            if ($info->IDENTITY == $identity) {
                $data['code'] = 0;
                $data['message'] = 'success';
                $data['vendor_info_id'] = $setting->id;
                $data['wallet_type_id'] = $setting->wallet_type_id;
                $data['rate'] = $setting->rate;
            } else {
                $data['code'] = 401002;
                $data['message'] = 'X-target-IDENTITY error';
            }
        } else {
            $data['code'] = 401002;
            $data['message'] = 'merchant not exist';
        }

        return $data;
    }

    private function checkGame($agent_id, $vendor_info_id, $game_code)
    {
        $result = false;
        $sub = $sub = DB::table('agents')
            ->where('id', $agent_id)
            ->select('hierarchy_path');

        $game = DB::table('games as g')
            ->join('vendor_info_games as vig', function ($join) use ($vendor_info_id) {
                $join->on('vig.game_id', 'g.id')
                    ->where('vig.vendor_info_id', $vendor_info_id)
                    ->where('vig.status', 1);
            })
            ->join('agent_games as ag', 'ag.game_id', 'g.id')
            ->join(
                DB::raw('(' . $sub->toSql() . ') as m'),
                'm.hierarchy_path',
                'like',
                DB::raw('CONCAT(\'%/\', ag.agent_id, \'%\')')
            )
            ->mergeBindings($sub)
            ->where('g.game_type', $game_code)
            ->where('g.status', 1)
            ->where('g.vendor_id', 50)
            ->select(
                'g.id',
                DB::raw('LOCATE(CONCAT(\'/\', ag.agent_id), m.hierarchy_path) as indexs'),
                'ag.status as status'
            )
            ->orderBy('indexs', 'desc')
            ->first();
        if (is_object($game)) {
            if ($game->status == 1) {
                //檢查agent_games的status是否能玩這遊戲
                $result = true;
            }
        }

        return $result;
    }

    private function checkAgentStatus($agent_id)
    {
        $result = true;
        $agent = DB::table('agents')->where('id', $agent_id)->first();
        $hierarchy = explode("/", $agent->hierarchy_path);
        $agents = DB::table('agents')->whereIn('id', $hierarchy)->get();
        foreach ($agents as $row) {
            if ($row->status == 0) {
                $result = false;
                break;
            }
        }
        return $result;
    }

    private function updateMemberBalance($member, $amount)
    {
        $result = false;
        //先把錢做運算
        $afterBalance = $member->balance - $amount;
        if ($afterBalance >= 0) {
            //如果扣款後金額大於0才做扣款
            $result = true;
            $member->update(['balance' => $afterBalance]);
        }
        return $result;
    }

    private function updateMemberWalletBalance($member_wallet, $amount)
    {
        $result = false;
        //先把錢做運算
        $afterBalance = $member_wallet->balance - $amount;
        if ($afterBalance >= 0) {
            //如果扣款後金額大於0才做扣款
            $result = true;
            $member_wallet->update(['balance' => $afterBalance]);
        }
        return $result;
    }

    private function getTransactionNo()
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = DB::table('transaction_logs')
            ->whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)->count();
        // 驗證不重覆
        if ($is_exist) {
            return self::getTransactionNo();
        } else {
            return $number;
        }
    }

    private function createLogData($member, $member_wallet, $before_balance, $rate, $platform_point, $session_token)
    {
        $log = array();
        $log['transaction_no'] = $this->getTransactionNo();
        $log['agent_id'] = $member->parent;
        $log['member_id'] = $member->id;
        $log['wallet_type_id'] = $member_wallet->wallet_type_id;
        $log['vendor_id'] = $this->vendor_id; //pgssw
        $log['order_id'] = 0;
        $log['point'] = $platform_point;
        $log['vendor_point'] = $platform_point * $rate;
        $log['rate'] = $rate;
        $log['before_balance'] = $before_balance;
        $log['balance'] = $member_wallet->balance;
        $log['vendor_balance'] = $member_wallet->balance * $rate;
        $log['type'] = TransactionEnum::TYPE_SINGLE_WALLET_BET;//單錢包轉帳
        $log['operator'] = '0';
        $log['status'] = 999; //遊戲中未鎖定 999
        $log['remark'] = $session_token;

        return $log;
    }

    private function createBetRecordData($vendor_info_id, $param, $sessionToken, $isBonus = false)
    {
        $record = array();
        $record['id'] = $this->getTransactionNo();
        $record['vendor_info_id'] = $vendor_info_id;
        $record['bet_id'] = $param['transactionNo'];
        $record['session_token'] = ($isBonus == true ? $sessionToken : [REDACTED]'sessionToken']);
        $record['username'] = $param['playerUniqueID'];
        $record['game_code'] = $param['gameID'];
        //如果是bonus就直接用結算時間
        $record['bet_time'] = ($isBonus == true ? $param['settlementTime'] : date("Y-m-d H:i:s"));
        //如果不是bonus就是未派彩先用0000-00-00 00:00:00
        $record['payout_time'] = ($isBonus == true ? $param['settlementTime'] : '0000-00-00 00:00:00');
        $record['bet'] = $param['betAmount'];
        $record['win'] = ($isBonus == true ? $param['payoutAmount'] : 0);
        $record['win_lose'] = ($isBonus == true ? $param['payoutAmount'] : 0);
        $record['status'] = ($isBonus == true ? 1 : 0); //0未結算 1結算
        $record['info'] = json_encode($param);

        return $record;
    }

    private function createTotalBetRecordData($record, $member_id)
    {
        //透過game_code查詢games的id
        $game = DB::table('games')->where('vendor_id', $this->vendor_id)->where('game_type', $record->game_code)->first();
        $data = array();
        $data['summary_time'] = date('Y-m-d H:00:00', strtotime($record->payout_time));
        $data['member_id'] = $member_id;
        $data['vendor_id'] = $this->vendor_id;
        $data['bet_record_id'] = $record->id;
        $data['game_id'] = $game->id;
        $data['reference_no'] = $record->bet_id;
        $data['bet'] = $record->bet;
        $data['valid_bet'] = $record->bet;
        $data['win_lose'] = $record->win_lose;
        $data['commission'] = 0;
        $data['bet_time'] = $record->bet_time;
        $data['payout_time'] = $record->payout_time;
        $data['admin'] = 0;

        return $data;
    }
}