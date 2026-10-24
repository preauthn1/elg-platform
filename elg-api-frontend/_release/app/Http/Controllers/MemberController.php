<?php

namespace App\Http\Controllers;

use App\Models\AgentDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\MemberCreditCard;
use App\Models\MemberValidation;
use App\Models\TransactionLog;
use App\Models\MemberCode;
use App\Models\MemberAgent;
use App\Models\FlowLimit;
use App\Repositories\AgentPersonalSettingRepository;
use App\Helpers\Common;
use DB;
use App\REDIS;
use Hash;
use Validator;
use Mail;

/**
 * @group Member
 */
class MemberController extends Controller
{

    /**
     * member
     * - 會員明細
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "會員系統編號,ex:1",
     *        "parent": "代理系統編號,ex:0",
     *        "username": "會員帳號,ex:hello.world",
     *        "balance": "會員餘額,ex:100",
     *        "image": "會員頭像,ex:http://xxxxx.xxxx",
     *        "email": "會員信箱,ex:user@example.invalid",
     *        "nickname": "暱稱,ex:user",
     *        "first_name": "會員名字,ex:hello",
     *        "last_name": "會員姓氏,ex:world",
     *        "birthday": "出生年月日,ex:1999-01-01",
     *        "mobile": "電話號碼,ex:09123456789",
     *        "timezone_id": "時區系統編號,ex:1",
     *        "country_id": "國家系統編號,ex:1",
     *        "zip_code": "郵遞區號,ex:23045",
     *        "district": "居住地區,ex:中正區",
     *        "city": "居住城市,ex:台北市",
     *        "address": "居住地址,ex:忠孝西路一段",
     *        "status": "狀態,ex:1",
     *        "is_validate": "是否通過身份認證,ex:1",
     *        "mobile_validate": "是否通過手機認證,ex:1",
     *        "email_validate": "是否通過email認證,ex:1",
     *        "event_mode": "是否進入活動鎖定模式0:未鎖定，1:鎖定",
     *        "last_login": "最後登入時間,ex:2019-03-26 03:44:34",
     *        "setting":{
     *            "activity_limit_time": 30,
     *            "push_notify": 1,
     *            "sms_notify": 1,
     *            "email_notify": 1,
     *            "message_box_notify": 1,
     *            "message_popup_notify": 1
     *        }
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $data = Member::onWriteConnection()
                ->select(
                    'id',
                    'parent',
                    'username',
                    'balance',
                    'image',
                    'email',
                    'nickname',
                    'first_name',
                    'last_name',
                    'birthday',
                    'mobile',
                    'timezone_id',
                    'country_id',
                    'zip_code',
                    'district',
                    'city',
                    'address',
                    'status',
                    'is_validate',
                    'mobile_validate',
                    'email_validate',
                    'event_mode',
                    'last_login'
                )
                ->find($request->ID);
            $setting = MemberSetting::where('member_id', $request->ID)
                ->select(
                    'activity_limit_time',
                    'push_notify',
                    'sms_notify',
                    'email_notify',
                    'message_box_notify',
                    'message_popup_notify'
                )
                ->first();
            if (is_object($setting)) {
                $data->setting = $setting;
            }
            if ($data) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
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
     * create
     * - 會員註冊(Header不需要token)
     *
     * @bodyParam code string 代理的推薦碼 Example: ABC123
     * @bodyParam username string required 會員帳號(不可重覆) Example: username
     * @bodyParam password string required 會員密碼
     * @bodyParam password_confirmation string required 確認會員密碼
     * @bodyParam email email 會員電子信箱(不可重覆) Example: user@example.invalid
     * @bodyParam nickname string 暱稱(不可重覆) Example: user
     * @bodyParam device_id string 推播設備號 Example: xxxx
     * @bodyParam image string 會員頭像 Example: base64 file
     * @bodyParam first_name string 英文名 Example: CHIA HUA
     * @bodyParam last_name string 英文姓 Example: CHAN
     * @bodyParam birthday date 出生年月日 Example: 1990-01-01
     * @bodyParam mobile string 聯絡電話(不可重覆) Example: 0912345678
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id integer 國家 Example: 1
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "會員系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Member();
            // 添加年齡需買18歲的驗證
            $rule = $model->rules_create;
            $rule['birthday'] = 'date_format:Y-m-d|before:' . date('Y-m-d', strtotime('-18years +1days'));
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            //確認username和nickname是否有禁用字
            $unusable_words = ['customer', 'service', 'vworld', 'agent', 'a g e n t', 'v w o r l d', 's e r v i c e'];
            foreach ($unusable_words as $word) {
                if (strpos(strtolower($request->username), $word) !== false
                    || strpos(strtolower($request->nickname), $word) !== false) {
                    return response()->json([
                        'code' => 1093,
                        'message' => str_replace(':value', $word, __('error_message.1093'))
                    ]);
                }
            }
            // 用推薦碼找到是哪個Agent的下線
            $agent = Agent::where('code', $request->code)->where('status', 1)->first();
            
            $memberAgent = null;
            if (!is_object($agent)) {
            	$memberAgent = $this->checkMemberCode($request);
            	if (is_object($memberAgent)) {
            		$agent = Agent::where('id', $memberAgent->agent_id)->where('status', 1)->first();
            		if ($agent->agent_mode != 1) {
            			$agent = null;
            		}
            	}
            }
            
            if (!is_object($agent)) {
                // 推薦碼錯誤
                return response()->json([
                    'code' => 1050,
                    'message' => __('error_message.1050')
                ]);
            } elseif ($agent['frontend_register'] != 1 && $request->code != '') {
                // 營運商不開放註冊
                return response()->json([
                    'code' => 1051,
                    'message' => __('error_message.1051')
                ]);
            } elseif (preg_match("/^agent/", strtolower($request->username))) {
                // 前綴不可為agent
                return response()->json([
                    'code' => 2036,
                    'message' => __('error_message.2036')
                ]);
            } else {
                //檢查帳號是否是正確的域名，不是就直接回傳錯誤訊息
                $check_domain = $this->checkDomain($agent->id, $request->domain);
                if (!$check_domain) {
                    return response()->json([
                        'code' => 1003,
                        'message' => __('error_message.1003')
                    ]);
                }

                $member = array();
                $member['parent'] = is_object($agent) ? $agent->id : 1; // 沒有Agent預設為1
                $member['username'] = $request->username;
                $member['email'] = $request->email;
                // 如果沒有傳暱稱，給個預設名稱
                if (isset($request->nickname) && $request->nickname != '') {
                    $member['nickname'] = $request->nickname;
                } else {
                    $member['nickname'] = $this->getNickname();
                }
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image) && $request->image != '') {
                    $path = $this->uploadBase64File($request->image);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $member['image'] = $path['message'];
                }
                $member['password'] = Hash::make($request->password);
                $member['device_id'] = $request->device_id;
                $member['first_name'] = $request->first_name;
                $member['last_name'] = $request->last_name;
                $member['birthday'] = $request->birthday;
                $member['mobile'] = $request->mobile;
                $member['timezone_id'] = $request->timezone_id;
                $member['country_id'] = $request->country_id;
                $member['zip_code'] = $request->zip_code;
                $member['district'] = $request->district;
                $member['city'] = $request->city;
                $member['address'] = $request->address;
                $member['status'] = 1;
                $member['admin'] = 0;
                if ($result = Member::create($member)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'member'
                    ));
                    
                    if ($agent->agent_mode == 1) {
                    	$memberCode = array();
                    	$memberCode['member_id'] = $result->id;
                    	$memberCode['agent_code'] = $this->getMemberAgentCode();
                    	
                    	$resultMemberCode = MemberCode::create($memberCode);
                    	
                    	//綁定上級
                    	$modelMemberAgent = array();
                    	$modelMemberAgent['member_id'] = $result->id;
                    	$modelMemberAgent['parent_id'] = $agent->id;
                    	$modelMemberAgent['topper_member_id'] = (is_object($memberAgent))? $memberAgent->member_id : 0;
                    	$modelMemberAgent['agent_mode'] = 1;  //1: 無限代
                    	$modelMemberAgent['agent_level'] = (is_object($memberAgent))? ($memberAgent->agent_level + 1) : 1;
                    	$agent_relation = (is_object($memberAgent)) ? $memberAgent->agent_relation : '0,';
                    	$modelMemberAgent['agent_relation'] = $agent_relation . $result->id . ',';
                    	MemberAgent::create($modelMemberAgent);
                    }

                    // 如果有填寫EMAIL發送認證信
                    if (isset($request->email)) {
                        if (count($this->registerMail($request->email)) > 0) {
                            // 註冊成功，但認證信件發送失敗
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.2003'),
                                'data' => $result->id
                            ]);
                        }
                    }

                    //註冊完成後，立即呼叫CS API，同步cs DB的資料
                    $this->trigger_CS_API_SyncData($agent, $request->username);

                    DB::update("CALL SP_event_gift_point( 1, " . $result->id . ", " . $agent->id . ")");

                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $result->id
                    ]);
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
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


    private function trigger_CS_API_SyncData($queryAgent, $username)
    {
        try {
            $data = [
                'API_USER' => $queryAgent->CS_API_USER,
                'API_KEY' => $queryAgent->CS_API_KEY,
                'THIRD_PARTY_ID' => $username,
            ];
            $result_json = Common::postToSocket("sync/members", $data);
            return;
        } catch (Exception $e) {
            return;
        }
    }

    /**
     * update
     * - 修改會員資料
     *
     * @bodyParam email email required 會員電子信箱(不可重覆) Example: user@example.invalid
     * @bodyParam nickname string required 暱稱(不可重覆) Example: user
     * @bodyParam image string 會員頭像 Example: base64 file
     * @bodyParam first_name string required 英文名 Example: CHIA HUA
     * @bodyParam last_name string required 英文姓 Example: CHAN
     * @bodyParam birthday date 出生年月日 Example: 1990-01-01
     * @bodyParam mobile string 聯絡電話(不可重覆) Example: 0912345678
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id integer 國家 Example: 1
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Member();
            // 添加年齡需買18歲的驗證
            $rule = $model->rules_update;
            $rule['birthday'] = 'date_format:Y-m-d|before:' . date('Y-m-d', strtotime('-18years +1days'));
            $rule['email'] = 'email|unique:members,email,' . $request->ID;
            $rule['mobile'] = 'digits_between:0,20|unique:members,mobile,' . $request->ID;
            $rule['nickname'] = 'between:0,30|unique:members,nickname,' . $request->ID;
            $validator = Validator::make($request->all(), $rule, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }

            //確認nickname是否有禁用字
            $unusable_words = $this->getUnsupportNicknames($request->ID);

            foreach ($unusable_words as $word) {
                if (strpos(strtolower($request->nickname), $word) !== false) {
                    return response()->json([
                        'code' => 1093,
                        'message' => str_replace(':value', $word, __('error_message.1093'))
                    ]);
                }
            }

            if ($data = Member::find($request->ID)) {
                $member = array();
                if (isset($request->email) && $request->email != '') {
                    $member['email'] = $request->email;
                    $member['email_validate'] = 0;
                }
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image) && $request->image != '') {
                    $path = $this->uploadBase64File($request->image);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $member['image'] = $path['message'];
                }
                if (isset($request->mobile) && $request->mobile != '') {
                    $member['mobile'] = $request->mobile;
                    $member['mobile_validate'] = 0;
                }
                $member['nickname'] = (isset($request->nickname) && $request->nickname != '') ? $request->nickname : $data->nickname;
                $member['first_name'] = (isset($request->first_name) && $request->first_name != '') ? $request->first_name : $data->first_name;
                $member['last_name'] = (isset($request->last_name) && $request->last_name != '') ? $request->last_name : $data->last_name;
                $member['birthday'] = (isset($request->birthday) && $request->birthday != '') ? $request->birthday : $data->birthday;
                $member['timezone_id'] = (isset($request->timezone_id) && $request->timezone_id != '') ? $request->timezone_id : $data->timezone_id;
                $member['country_id'] = (isset($request->country_id) && $request->country_id != '') ? $request->country_id : $data->country_id;
                $member['zip_code'] = (isset($request->zip_code) && $request->zip_code != '') ? $request->zip_code : $data->zip_code;
                $member['district'] = (isset($request->district) && $request->district != '') ? $request->district : $data->district;
                $member['city'] = (isset($request->city) && $request->city != '') ? $request->city : $data->city;
                $member['address'] = (isset($request->address) && $request->address != '') ? $request->address : $data->address;
                $member['admin'] = 0;
                if (Member::where('id', $request->ID)->update($member)) {
                    $key = array(
                        'member'
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
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

    private function getUnsupportNicknames($memberID)
    {
        $rep = new AgentPersonalSettingRepository();

        //先查看db (代理個人配置) 是否有設
        $querySettings = $rep->getFirstSettingByMemberId($memberID , 92001);

        if(!is_object($querySettings) || is_null($querySettings))
        {
            return ['customer', 'service', 'vworld', 'agent', 'a g e n t', 'v w o r l d', 's e r v i c e' , '代理' , '客服' , '代理' , '客服'];
        }

        $settingValues = explode("," ,  $querySettings->value);
        $invalid_nicknames = $settingValues; 
        $invalid_nicknames = array_diff($invalid_nicknames, [""]);

        return $invalid_nicknames;
    }


    /**
     * changePassword
     * - 變更會員密碼
     *
     * @bodyParam password string required 舊密碼 Example: aaa123456
     * @bodyParam new_password string required 新密碼 Example: abc123456
     * @bodyParam new_password_confirmation string required 確認新密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function changePassword(Request $request)
    {
        try {
            $model = new Member();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_password, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            // 先確認帳號是否存在
            if ($data = $model->find($request->ID)) {
                // 驗證舊密碼是否正確
                if (Hash::check($request->password, $data->password)) {
                    $member = array();
                    $member['password'] = Hash::make($request->new_password);
                    $member['admin'] = 0;
                    if ($model->where('id', $request->ID)->update($member)) {
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 2001;
                        $message = __('error_message.2001');
                    }
                } else {
                    $code = 1018;
                    $message = __('error_message.1018');
                }
            } else {
                $code = 1007;
                $message = __('error_message.1007');
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

    /**
     * createSetting
     * - 新增會員設定
     *
     * @bodyParam activity_limit_time integer required 最長非活動狀態時間 Example: abc123456
     * @bodyParam push_notify boolean  required 推送通知 Example: abc123456
     * @bodyParam sms_notify boolean  required 短信提示 Example: abc123456
     * @bodyParam email_notify boolean  required 電子郵件通知 Example: abc123456
     * @bodyParam message_box_notify boolean  required 信息收件箱 Example: abc123456
     * @bodyParam message_popup_notify boolean  required 彈出信息 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function createSetting(Request $request)
    {
        try {
            $model = new MemberSetting();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            if (!MemberSetting::where('member_id', $request->ID)->First()) {
                $data = array();
                $data['member_id'] = $data['admin'] = $request->ID;
                $data['activity_limit_time'] = $request->activity_limit_time;
                $data['push_notify'] = $request->push_notify;
                $data['sms_notify'] = $request->sms_notify;
                $data['email_notify'] = $request->email_notify;
                $data['message_box_notify'] = $request->message_box_notify;
                $data['message_popup_notify'] = $request->message_popup_notify;
                if ($model->create($data)) {
                    $key = array(
                        'member',
                        $request->ID
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 2016,
                    'message' => __('error_message.2016')
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
     * updateSetting
     * - 修改會員設定
     *
     * @bodyParam activity_limit_time integer 最長非活動狀態時間 Example: abc123456
     * @bodyParam push_notify boolean  推送通知 Example: abc123456
     * @bodyParam sms_notify boolean  短信提示 Example: abc123456
     * @bodyParam email_notify boolean  電子郵件通知 Example: abc123456
     * @bodyParam message_box_notify boolean  信息收件箱 Example: abc123456
     * @bodyParam message_popup_notify boolean  彈出信息 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function updateSetting(Request $request)
    {
        try {
            $model = new MemberSetting();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            if ($data = MemberSetting::where('member_id', $request->ID)->First()) {
                $setting = array();
                $setting['activity_limit_time'] = isset($request->activity_limit_time) ? $request->activity_limit_time : $data->activity_limit_time;
                $setting['push_notify'] = isset($request->push_notify) ? $request->push_notify : $data->push_notify;
                $setting['sms_notify'] = isset($request->sms_notify) ? $request->sms_notify : $data->sms_notify;
                $setting['email_notify'] = isset($request->email_notify) ? $request->email_notify : $data->email_notify;
                $setting['message_box_notify'] = isset($request->message_box_notify) ? $request->message_box_notify : $data->message_box_notify;
                $setting['message_popup_notify'] = isset($request->message_popup_notify) ? $request->message_popup_notify : $data->message_popup_notify;
                $setting['admin'] = $request->ID;
                if (MemberSetting::where('member_id', $request->ID)->update($setting)) {
                    $key = array(
                        'member',
                        $request->ID
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1010,
                    'message' => __('error_message.1010')
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
     * lastTransactionToVendor
     * - 最後一次轉點進遊戲記錄
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":  {
     *        "id": 7,
     *        "member_id": 1,
     *        "vendor_id": 1,
     *        "point": "10.00",
     *        "balance": "990.00",
     *        "vendor_balance": "10.00",
     *        "status": 1000,
     *        "created_at": "2019-04-09 10:20:44"
     *    }
     * }
     */
    public function lastTransactionToVendor(Request $request)
    {
        try {
            $data = TransactionLog::select('id', 'member_id', 'vendor_id', 'point', 'balance', 'vendor_balance', 'status', 'created_at')->where('member_id', $request->ID)
                ->where('type', 1)
                ->orderBy('id', 'desc')
                ->First();
            if ($data) {
                $key = array(
                    'member',
                    $request->ID
                );
                $redis = new REDIS();
                $redis->delete($key);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1010,
                    'message' => __('error_message.1010')
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

    public function flowLimit(Request $request)
    {
        try {
            $model = new FlowLimit();
            $request->per_page = isset($request->per_page) ? $request->per_page : 20;
            $request->page = isset($request->page) ? $request->page : 1;
            $agent = $request->ID;
            $memeber = $request->member_id;
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
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

    private function getNickname()
    {
        $array = [
            'BOSS',
            'BIG BOSS',
            'MEMBER',
            'LUCKY STAR',
            'WINNER'
            //'我來比有錢',
            //'博彩首富',
            //'富豪之星',
            //'比你更有錢',
            //'我來贏的'
        ];
        $nickname = $array[rand(0, 4)] . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $is_exist = Member::where('nickname', $nickname)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getNickname();
        } else {
            return $nickname;
        }
    }

    private function checkMemberCode($request)
    {
        $agent = DB::table('member_codes as mc')
            ->join('member_agent as ma', 'ma.member_id', 'mc.member_id')
            ->join('members as m', 'm.id', 'ma.member_id')
            ->where('mc.agent_code', $request->code)
            ->select(
                'mc.member_id',
                'ma.parent_id',
                'ma.topper_member_id',
                'ma.agent_level',
                'ma.agent_relation',
                'm.parent as agent_id'
            )
            ->first();
        return $agent;
    }

    private function getMemberAgentCode()
    {
        $str = Str::upper(env('UNLIMIT_PREFIX').str_random(7));
        $is_exist = MemberCode::where('agent_code', $str)->count();
        if ($is_exist) {
            return $this->getMemberAgentCode();
        }
        return $str;
    }

    private function checkDomain($agent_id, $domain)
    {
        $hasDomain = false;
        $agent = Agent::find($agent_id);
        $hierarchy = explode("/", $agent->hierarchy_path);
        //代理要由最下層開始往上找
        rsort($hierarchy);
        foreach ($hierarchy as $agent) {
            $agentDomain = AgentDomain::where('agent_id', $agent)
                ->get();
            if (is_object($agentDomain) && count($agentDomain) > 0) {
               foreach($agentDomain as $row){
                   if($row->domain == $domain){
                       $hasDomain = true;
                       break;
                   }
               }
                break;
            }
        }
        return $hasDomain;
    }
    
    private function messages()
    {
        return [
            'id.required' => __('error_message.1066'),
            'id.digits_between' => __('error_message.1067'),

            'username.required' => __('error_message.1026'),
            'username.r_alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),
            'email.unique' => __('error_message.1088'),

            'first_name.r_alpha_dash' => __('error_message.1031'),
            'first_name.between' => __('error_message.1032'),

            'last_name.r_alpha_dash' => __('error_message.1033'),
            'last_name.between' => __('error_message.1034'),

            'birthday.required' => __('error_message.1087'),
            'birthday.date_format' => __('error_message.1070'),
            'birthday.before' => __('error_message.1029'),

            'mobile.digits_between' => __('error_message.1036'),
            'mobile.unique' => __('error_message.1090'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'country_id.digits_between' => __('error_message.2022'),

            'zip_code.digits_between' => __('error_message.1038'),

            'district.between' => __('error_message.1039'),

            'city.between' => __('error_message.1040'),

            'address.between' => __('error_message.1041'),

            'status.boolean' => __('error_message.2009'),

            'token.required' => __('error_message.1071'),

            'activity_limit_time.required' => __('error_message.1072'),
            'activity_limit_time.digits_between' => __('error_message.1073'),

            'push_notify.required' => __('error_message.1074'),
            'push_notify.boolean' => __('error_message.1075'),

            'sms_notify.required' => __('error_message.1076'),
            'sms_notify.boolean' => __('error_message.1077'),

            'email_notify.required' => __('error_message.1078'),
            'email_notify.boolean' => __('error_message.1079'),

            'message_box_notify.required' => __('error_message.1080'),
            'message_box_notify.boolean' => __('error_message.1081'),

            'message_popup_notify.required' => __('error_message.1082'),
            'message_popup_notify.boolean' => __('error_message.1083')
        ];
    }
}