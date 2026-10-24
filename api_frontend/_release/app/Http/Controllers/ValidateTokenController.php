<?php

namespace App\Http\Controllers;

use App\Models\AgentDomain;
use App\Models\SimpleNamedLock;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Member;
use App\Models\Agent;
use App\Models\Country;
use App\Models\ValidateToken;
use App\Models\SmsText;
use App\Models\SmsGatewayGroupDetail;
use App\Models\SmsGatewayInfo;
use App\Models\AgentGroup;
use App\Services\TelegramService;
use App\SmsApi;
use App\REDIS;
use DB;
use Hash;
use Validator;
use Mail;

class ValidateTokenController extends Controller
{
    private $params = array(
        'insert_characters' => '',
        'left_credit_alert' => 0,
        'msg_merchant_prefix' => ''
    );

    /**
     * sendValidateCode
     * - 傳送驗證碼，需傳入驗證過的電子信箱或手機號碼(Header不需要token)
     *
     * @bodyParam domain string required 網域 Example: example.invalid
     * @bodyParam type integer required 類型(1:資料驗證, 2:忘記密碼, 3:找回密碼, 4:洗分驗證, 5:支付密碼) Example: 1
     * @bodyParam member_id integer 會員編號(資料驗證時必需傳入) Example: 1
     * @bodyParam username string 會員帳號(由營運商設定是否必填) Example: test1234
     * @bodyParam verify_by integer 驗證方式(1:手機2Email) Example: 1
     * @bodyParam mobile integer 會員電話 Example: 886954761453
     * @bodyParam email email 會員電子信箱 Example: user@example.invalid
     * @bodyParam payment_password string 支付密碼 Example: 123456
     * @bodyParam payment_password_confirmation string 確認支付密碼 Example: 123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function sendValidateCode(Request $request)
    {
        try {
            $rules = [
                'domain' => 'required|domain',
                'type' => 'in:1,2,3,4,5,6',
                'member_id' => 'digits_between:1,10',
                'username' => 'r_alpha_num|between:4,30',
                'verify_by' => 'in:1,2',
                'email' => ($request->type == 1) ? 'email|between:0,60|unique:members,email' : 'email|between:0,60',
                //'mobile' => ($request->type == 1) ? 'digits_between:0,20|unique:members,mobile' : 'digits_between:0,20',
                'mobile' => 'digits_between:0,20',
                'payment_password' => ($request->type == 5) ? 'required|between:6,12|confirmed' : 'between:6,12|between:6,12'
            ];

            // 驗證POST資料
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            //查組織ID
            $organization_id = $this->getOrganizationIdByDomain($request->domain);
            if ($organization_id == 0) {
                return response()->json([
                    'code' => 9997,
                    'message' => '[fv-01011] ' . __('error_message.9997'),
                    'date' => $organization_id
                ]);
            }
            //檢查前先Lock
            $lock = new SimpleNamedLock("ValidateTokenController", 0, $request->mobile);
            //檢查手機號是否重複
            if ($request->type == 1) {

                if ($this->checkMobileNumberExists($request->mobile, $organization_id) ||
                    $this->checkMobileNumberExists2($request->mobile, $organization_id, $request->member_id)) {
                    $lock->unLock();
                    return response()->json([
                        'code' => 1090,
                        'message' => __('error_message.1090'),
                        'aaa' => $request->mobile
                    ]);
                }
            }
            // 帳號是否存在
            $model = new Member();
            //1.資料驗證, 5.支付密碼
            if ($request->type == 1 || $request->type == 5) {
                // 驗證直接查找帳號
                $row = $model->where('id', $request->member_id)
                    ->first();
            } elseif ($request->verify_by == 1 && $request->mobile != '') {
                // 2.忘記密碼or3.找回密碼，需經過驗證
                $row = DB::table('members as m')
                    ->join('agents as a', function ($join) use ($organization_id) {
                        $join->on('a.id', 'm.parent')
                            ->where('a.organization_id', $organization_id)
                            ->where('a.status', 1);
                    })
                    ->where('m.mobile', $request->mobile)
                    ->where('m.mobile_validate', 1)
                    ->whereIn('m.status', [1, 2])
                    ->select('m.*')
                    ->get();
            } elseif ($request->verify_by == 2 && $request->email != '') {
                // 忘記or找回密碼，需經過驗證
                $row = DB::table('members as m')
                    ->join('agents as a', function ($join) use ($organization_id) {
                        $join->on('a.id', 'm.parent')
                            ->where('a.organization_id', $organization_id)
                            ->where('a.status', 1);
                    })
                    ->where('m.email', $request->email)
                    ->where('m.email_validate', 1)
                    ->whereIn('m.status', [1, 2])
                    ->select('m.*')
                    ->get();
            } else {
                $lock->unLock();
                // 如果驗證方式跟資料不符，回傳錯誤訊息
                return response()->json([
                    'code' => 9991,
                    'message' => __('error_message.9991')
                ]);
            }

            if ($row) {
                //綁定驗查帳號是否已綁定
                if ($request->type == 1) {
                    if ($request->verify_by == 1 && $row->mobile_validate == 1) {
                        $lock->unLock();
                        //帳號已認證, 請洽客服
                        return response()->json([
                            'code' => 9012,
                            'message' => __('error_message.9012')
                        ]);
                    } elseif ($request->verify_by == 2 && $row->email_validate == 1) {
                        $lock->unLock();
                        return response()->json([
                            'code' => 9013,
                            'message' => __('error_message.9013')
                        ]);
                    }
                } elseif ($request->type == 5) {
                    if ($request->verify_by == 1 && $row->mobile_validate == 1) {
                        if ($row->mobile != '') {
                            $request->mobile = $row->mobile;
                        } else {
                            $lock->unLock();
                            return response()->json([
                                'code' => 9013,
                                'message' => __('error_message.9013')
                            ]);
                        }
                    } elseif ($request->verify_by == 2 && $row->email_validate == 1) {
                        if ($row->email != '') {
                            $request->email = $row->email;
                        } else {
                            $lock->unLock();
                            return response()->json([
                                'code' => 9013,
                                'message' => __('error_message.9013')
                            ]);
                        }
                    }
                }

                //如果不是資料驗證,不同站台可能會有重複的手機號碼
                if ($request->type != 1 && $request->type != 5) {
                    // 用domain來判斷是哪個站台的
                    $row = $this->checkDomain($request->domain, $row);
                    // 如果是不同站台回傳會員不存在
                    if (!$row) {
                        $lock->unLock();
                        return response()->json([
                            'code' => 1007,
                            'message' => __('error_message.1007')
                        ]);
                    }
                }

                if ($request->type == 6) {
                    //如果是找回支付密碼,要確認沒有跟密碼重複
                    if(Hash::check($request->payment_password, $row->password)) {
                        $lock->unLock();
                        return response()->json([
                            'code' => 1094,
                            'message' => __('error_message.1094')
                        ]);
                    }
                }

                // 先找到營運商，再確認是否需要驗證帳號
                $agent = Agent::find($row->parent);
                if ($agent->need_username && $request->type != 1 && $request->type != 5 && $request->type != 6) {
                    // 帳號不符回傳錯誤訊息
                    if ($row->username != $request->username) {
                        $lock->unLock();
                        return response()->json([
                            'code' => 1005,
                            'message' => __('error_message.1005')
                        ]);
                    }
                }

                //取得token expired時間
                $smsGatewaySetting = DB::table('agent_groups as ag')
                    ->join('sms_gateway_group_details as sd', 'sd.agent_group_id', 'ag.id')
                    ->where('ag.organization_id', $agent->organization_id)
                    ->where('ag.agent_group_type', 3)
                    ->select(
                        'ag.id',
                        'sd.setting'
                    )
                    ->first();
                $json = json_decode($smsGatewaySetting->setting);
                //Validate Token 有效時間; 預設6分鐘
                $row->token_expired = $token_expired = isset($json->token_expired)
                    ? $json->token_expired
                    : env('SMS_TOKEN_EXPIRED', 6);
                //簡訊有效時間最大發送次數; 預設3次
                $row->max_send = $max_send = isset($json->max_send)
                    ? $json->max_send
                    : env('SMS_MAX_SEND', 3);
                $row->sms_gateway_group_id = $agent->sms_gateway_group_id;

                //有效時間內($token_expired)的token不重新抓取，直接重新發送, 並用另一個簡訊商發送
                $validate_token = [REDACTED], $request->type, $request->verify_by);
                if (!is_object($validate_token) || $validate_token->info != $request->mobile) { //找不到有效時間內的token 或手機號碼不同就重新產生驗證碼
                    // 有效時間(預設6分鐘)內沒發送過的話就重新發送
                    // 將舊TOKEN改為無效,
                    ValidateToken:[REDACTED]'member_id', $row->id)
                        ->where('status', 0)
                        ->update(
                            array(
                                'status' => 2
                            )
                        );

                    // 新增TOKEN
                    $data = array();
                    $data['member_id'] = $row->id;
                    $data['type_id'] = $request->type . $request->verify_by;
                    $data['info'] = ($request->verify_by == 1) ? $request->mobile : $request->email;
                    $data['info_2'] = ($request->type == 5 || $request->type == 6)
                        ? Hash::make($request->payment_password)
                        : '';
                    $token = [REDACTED]'token'] = $this->getToken($request->verify_by);
                    $data['expire_date'] = date('Y-m-d H:i:s', strtotime('+ ' . $token_expired . 'minute'));
                    $data['status'] = 0;
                    $data['admin'] = $row->id;
                    if (!ValidateToken:[REDACTED] {
                        $lock->unLock();
                        // TOKEN新增失敗
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                    $row->times = 1;
                } elseif ($validate_token->times < $max_send) {
                    $validate_token->increment('times');
                    $validate_token->expire_date = date('Y-m-d H:i:s', strtotime('+ ' . $token_expired . 'minute'));
                    $validate_token->save();
                    $token = [REDACTED];
                    $row->times = $validate_token->times;
                } else {
                    $lock->unLock();
                    // 系統錯誤，請稍後再試
                    return response()->json([
                        'code' => 2037,
                        'message' => str_replace(':values', $max_send, __('error_message.2037'))
                    ]);
                }

                if ($this->sendValidate($row, $request, $token)) {
                    $lock->unLock();
                    // 發送成功
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                } else {
                    $lock->unLock();
                    // 發送失敗
                    return response()->json([
                        'code' => 2003,
                        'message' => __('error_message.2003')
                    ]);
                }
            } else {
                $lock->unLock();
                // 會員不存在
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if(isset($lock)) {
                $lock->unLock();
            }
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            if(isset($lock)) {
                $lock->unLock();
            }
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * checkValidateCode
     * - 確認驗證碼是否正確(Header不需要token)
     *
     * @bodyParam token string required 驗證碼 Example: 184438
     * @bodyParam mobile string required 手機號 Example: 1234567890
     * @bodyParam email string required 手機號 Example: 1234567890
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "會員帳號"
     * }
     */
    public function checkValidateCode(Request $request)
    {
        $rules = [
            'token' => 'required',
            'mobile' => 'required_without:email|digits_between:0,20',
            'email' => 'required_without:mobile|email|between:0,60',
        ];

        // 驗證POST資料
        $validator = Validator::make($request->all(), $rules, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => $validator->errors()
                    ->all()
            ]);
        }
        try {
            // 驗證TOKEN是否有效(有效TOKEN不重覆，所以不需其他條件)
            $row = $this->getValidateTokenByToken($request->token);
            if (is_object($row) && ($row->info == $request->mobile || $row->info == $request->email)  ) {
                $member = Member::where('id', $row->member_id)->first();
                if (!is_object($member)) {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }

                $type = substr($row->type_id, 0, 1);
                $verify_by = substr($row->type_id, 1, 1);
                $validate_array = array();
                // 1X單純驗證，不需進行修改密碼的步驟，直接更新狀態即可
                if ($type == 1) {
                    switch ($verify_by) {
                        case 1:
                            $validate_array = [
                                'mobile_validate' => 1,
                                'mobile' => $row['info']
                            ];
                            break;
                        case 2:
                            $validate_array = [
                                'email_validate' => 1,
                                'email' => $row['info']
                            ];
                            break;
                    }

                    if (is_array($validate_array) && count($validate_array) > 0) {
                        // 更新會員資料
                        $update_member = $member->update($validate_array);
                        if (!$update_member) {
                            return response()->json([
                                'code' => 2001,
                                'message' => __('error_message.2001')
                            ]);
                        }
                    }

                    // 更新TOKEN狀態
                    ValidateToken:[REDACTED]'id', $row->id)
                        ->update(
                            [
                                'status' => 1
                            ]
                        );
                } elseif ($type == 5 || $type == 6) {
                    $validate_array = [
                        'payment_password' => $row['info_2']
                    ];

                    if (is_array($validate_array) && count($validate_array) > 0) {
                        // 更新會員資料
                        $update_member = $member->update($validate_array);
                        if (!$update_member) {
                            return response()->json([
                                'code' => 2001,
                                'message' => __('error_message.2001')
                            ]);
                        }
                    }

                    // 更新TOKEN狀態
                    ValidateToken:[REDACTED]'id', $row->id)
                        ->update(
                            [
                                'status' => 1
                            ]
                        );
                }

                //綁定手機送
                if ($row->type_id == 11 && is_object($member)) {
                    DB::update("CALL SP_event_gift_point( 2, " . $member->id . ", " . $member->parent . ")");
                }

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => isset($member->username) ? $member->username : ''
                ]);
            } else {
                return response()->json([
                    'code' => 9992,
                    'message' => __('error_message.9992')
                ]);
            }
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
     * resetPassword
     * - 利用驗證碼重設密碼(Header不需要token)
     *
     * @bodyParam token string required 驗證碼 Example: NgF7huNcta13V5WyJwyGL34xQlHT3Acp
     * @bodyParam new_password string required 新密碼 Example: abc123456
     * @bodyParam new_password_confirmation string required 確認新密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function resetPassword(Request $request)
    {
        try {
            $model = new Member();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_reset_password, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            // 先確認TOKEN是否有效
            $row = $this->getValidateTokenByToken($request->token);
            if ($row) {
                $update_data = array();
                $update_data['password'] = Hash::make($request->new_password);
                $update_data['status'] = 1;
                $update_data['admin'] = 0;
                $model = $model->where('id', $row->member_id)->first();
                if (is_object($model)) {
                    // 更新密碼
                    if ($model->update($update_data)) {
                        // 更新TOKEN
                        ValidateToken:[REDACTED]'token', $request->token)
                            ->update(
                                array(
                                    'status' => 1
                                )
                            );
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 2001;
                        $message = __('error_message.2001');
                    }
                } else {
                    $code = 2001;
                    $message = __('error_message.2001');
                }
            } else {
                $code = 9998;
                $message = __('error_message.9998');
            }
            return response()->json([
                'code' => $code,
                'message' => $message
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

    private function sendValidate($member, $request, $token)
    {
        $return_value = true;
        $tg_message = '';
        if ($request->verify_by == 1) {
            //取得簡訊商發送順序
            $sms_gateways = DB::table('sms_gateway_infos as sgi')
                ->join('sms_gateways as sg', function ($join) {
                    $join->on('sg.id', 'sgi.sms_gateway_id')
                        ->where('sg.status', 1);
                })
                ->join('sms_gateway_info_countries as sgic', function ($join) {
                    $join->on('sgic.sms_gateway_info_id', 'sgi.id')
                        ->where('sgic.status', 1);
                })
                ->join('countries as c', function ($join) use ($request) {
                    $join->on('c.id', 'sgic.country_id')
                        ->where('c.status', 1)
                        ->whereRaw("'" . $request->mobile . "' like concat(c.country_code, '%')");
                })
                ->where('sgi.status', 1)
                ->where('sgi.sms_gateway_group_id', $member->sms_gateway_group_id)
                ->groupBy('sgi.id')
                ->orderBy('sgi.sort', 'ASC')
                ->select(
                    'sg.id as sms_gateway_id',
                    'sg.code',
                    'sg.url',
                    'example.invalid',
                    'c.id as country_id'
                )
                ->get();

            //當前可用簡訊商戶數
            $sms_gateway_count = count($sms_gateways);

            if ($sms_gateway_count > 0) {
                if ($sms_gateway_count == 1) {
                    $member->times = 1;
                } elseif ($member->times > $sms_gateway_count) {
                    //當前有效時間內發送次數 > 當前可用簡訊商戶數
                    $member->times = ($member->times % $sms_gateway_count);
                }
                $sms_gateway = $sms_gateways[($member->times - 1)];
                $sms_gateway = $this->setDefaultParamToSmsInfo($sms_gateway);

                // 取訊息
                $text = SmsText::where('sms_gateway_group_id', $member->sms_gateway_group_id)
                    ->where('type_id', $request->type)
                    ->where('country_id', $sms_gateway->country_id)
                    ->first();

                if (is_object($text)) {
                    //發送訊息
                    $token = [REDACTED], $sms_gateway);
                    $text = str_replace('{code}', $token, $text->text);

                    $sms = new SmsApi();
                    switch ($sms_gateway->code) {
                        case 'sg':  //sms-get
                            $result = $sms->sendBySmsGet($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                        case 'sw':  //sms-works
                            $result = $sms->sendBySmsWorks($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                        case 'ib':  //infobip
                            $result = $sms->sendByInfobip($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                        case 'oc':  //1code
                            $result = $sms->sendByOneCode($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                        case 'cx':  //chuanxsms
                            $result = $sms->sendByChuanxSms($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                        case 'ict':  //1code
                            $result = $sms->sendByICT($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                        case 'local':  //local
                            $result = $sms->sendByLocal($member->id, $request->mobile, $text, $sms_gateway);
                            break;
                    }
                    if (!isset($result) || $result['code'] != 1000) {
                        $return_value = false;
                    }
                } else {
                    //未設定sms text
                    $tg_message = "【sms發送失敗-查無簡訊內容】" . PHP_EOL .
                        "mobile:" . $request->mobile . PHP_EOL .
                        "sms_group:" . $member->sms_gateway_group_id . PHP_EOL .
                        "SP: call ADMIN_check_sms_gateway('" . $request->mobile . "', " . $member->sms_gateway_group_id .
                        ", " . $request->type . ", " . $sms_gateway->country_id . ")";

                    $return_value = false;
                }
            } else {
                // 查無該國家簡訊商戶
                $tg_message = "【sms發送失敗-查無符合國際電話冠碼】" . PHP_EOL .
                    "mobile:" . $request->mobile . PHP_EOL .
                    "sms_group:" . $member->sms_gateway_group_id . PHP_EOL .
                    "SP: call ADMIN_check_sms_gateway('" . $request->mobile . "', " . $member->sms_gateway_group_id . ", 0)";

                $return_value = false;
            }
        } elseif ($request->verify_by == 2) {
            // mail 驗證
            if (count($this->forgetPasswordMail($request->email, $token)) > 0) {
                $return_value = false;
            }
        }

        if ($tg_message != '') {
            $tgService = new TelegramService();
            $tgService->sendMessage($tg_message, 'sms');
        }
        return $return_value;
    }

    private function getValidateTokenByToken($token)
    {
        return ValidateToken:[REDACTED]'token', $token)
            ->where('status', 0)
            ->where('expire_date', '>=', date('Y-m-d H:i:s'))
            ->first();
    }

    private function getValidateTokenByMemberId($member_id, $type = 1, $verify_by = 1)
    {
        $type = $type . $verify_by;
        return ValidateToken:[REDACTED]'member_id', $member_id)
            ->where('status', 0)
            ->where('type_id', $type)
            ->where('expire_date', '>=', date('Y-m-d H:i:s'))
            ->first();
    }

    // 發送驗證信
    private function registerMail($email)
    {
        $data = array(
            "test" => 'register'
        );
        Mail::send('email.register', $data, function ($message) use ($email) {
            $message->subject(__('mail.register_subject'));
            $message->to($email);
        });
        $failures = [];
        if (count(Mail::failures()) > 0) {
            $failures[] = Mail::failures()[0];
        }
        return $failures;
    }

    // 發送忘記密碼信
    private function forgetPasswordMail($email, $token)
    {
        $data = array(
            "text" => '設定新密碼',
            "url" => env('APP_URL') . '/SetPassword/' . $token
        );
        Mail::send('email.forget_password', $data, function ($message) use ($email) {
            $message->subject(__('mail.register_subject'));
            $message->to($email);
        });
        $failures = [];
        if (count(Mail::failures()) > 0) {
            $failures[] = Mail::failures()[0];
        }
        return $failures;
    }

    // 取TOKEN, 1:手機用的就為6碼數字，2:電子郵件用為32碼文字+數字
    private function getToken($verifyBy)
    {
        if ($verifyBy == 1) {
            $token = [REDACTED], 999999), 6, '0', STR_PAD_LEFT);
            $isMobile = 1;
        } else {
            $token = [REDACTED];
            $isMobile = 0;
        }

        $is_exist = ValidateToken:[REDACTED]'token', $token)
            ->where('expire_date', '>=', date('Y-m-d H:i:s'))
            ->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getToken($isMobile);
        } else {
            return $token;
        }
    }

    private function changeSmsContent($token, $sms_gateway)
    {
        $info = json_decode($sms_gateway->info, true);
        if (is_array($info) && $info['insert_characters'] != '') {
            //$token = [REDACTED] ($s, $info) {return $s.$info['insert_characters'];}, str_split($token)));
            $ar = str_split($token);
            foreach ($ar as $key => &$value) {
                $value = $value . (($key != array_key_last($ar)) ? $info['insert_characters'] : '');
            }
            $token = [REDACTED];
        }
        return $token;
    }

    private function setDefaultParamToSmsInfo($sms_gateway)
    {
        $info = $ori_info = json_decode($sms_gateway->info, true);

        if (!isset($info['insert_characters'])) {
            $info['insert_characters'] = $this->params['insert_characters'];
        }
        if (!isset($info['left_credit_alert'])) {
            $info['left_credit_alert'] = $this->params['left_credit_alert'];
        }
        if (!isset($info['prefix'])) {
            $info['prefix'] = $this->params['msg_merchant_prefix'];
        }

        if ($info != $ori_info) {
            $sms_gateway->info = json_encode($info);
        }
        return $sms_gateway;
    }

    private function checkDomain($domain, $account)
    {
        $correctAccount = false;
        if (count($account) > 0) {
            foreach ($account as $row) {
                $agentDomain = $this->getAgentDomain($row->parent);
                if (in_array($domain, $agentDomain)) {
                    $correctAccount = $row;
                    break;
                }
            }
        }
        return $correctAccount;
    }

    private function getAgentDomain($agentId)
    {
        $agentDomain = array();
        $agent = Agent::find($agentId);
        $hierarchy = explode("/", $agent->hierarchy_path);
        //代理要由最下層開始往上找
        rsort($hierarchy);
        foreach ($hierarchy as $val) {
            $agentDomain = AgentDomain::where('agent_id', $val)->pluck('domain')->toArray();
            if (count($agentDomain) > 0) {
                break;
            }
        }
        return $agentDomain;
    }

    private function messages()
    {
        return [
            'type.in' => 'in:1,2,3,4',
            'verify_by.in' => 'in:1,2',

            'member_id.digits_between' => __('error_message.1067'),

            'username.r_alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'payment_password.required' => __('error_message.1019'),
            'payment_password.between' => __('error_message.1020'),
            'payment_password.confirmed' => __('error_message.1021'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),
            'email.unique' => __('error_message.1088'),

            'mobile.digits_between' => __('error_message.1036'),
            'mobile.unique' => __('error_message.1090'),

            'token.required' => __('error_message.9992')
        ];
    }
}
