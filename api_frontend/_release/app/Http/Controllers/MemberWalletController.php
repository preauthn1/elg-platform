<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Services\MemberWalletService;
use App\Helpers\Common;
use App\Helpers\SQLHelper;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\AgentWallet;
use App\REDIS;
use DB;
use Hash;
use Validator;
use Mail;

/**
 * @group Member
 */
class MemberWalletController extends Controller
{

    /**
     * member/wallet
     * - 會員錢包明細
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "wallet_type_id": "錢包種類系統編號,ex:1",
     *        "name": "錢包種類名稱,ex:CHIP",
     *        "balance": "錢包餘額,ex:100.0000000000"
     * }
     */
    public function getWallet(Request $request, MemberWalletService $service)
    {
        try {
            return response()->json([
                'code' => '1000',
                'message' => 'success',
                'data' => $service->init($request)->findAll()
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

    public function setWallet(Request $request)
    {
        $begin_transaction = false;
        try {
            $request->CASH_FLOW_GROUP = 7;
            $request->wallet_type_id = 1;
            //檢查錢包
            $wallet = DB::table('agent_wallets as aw')
                ->join('wallet_types as wt', function ($join) {
                    $join->on('wt.id', 'aw.wallet_type_id')
                        ->where('wt.status', 1);
                })
                ->where('aw.agent_group_id', $request->CASH_FLOW_GROUP)
                ->where('aw.status', 1)
                ->first();

            if (is_object($wallet)) {
                DB::beginTransaction();
                $begin_transaction = true;
                $member_wallet = MemberWallet::where('member_id', $request->ID)->where('wallet_type_id')->first();
                if (is_object($member_wallet)) {

                } else {
                    //創建玩家該幣種錢包
                    $member_wallet = $this->createMemberWallet();
                }
                return response()->json([
                    'code' => 1000,
                    'message' => 'success',
                    //'data' => $request->all()
                    'data' => $wallet
                ]);
            } else {
                return response()->json([
                    'code' => 11001,
                    'message' => __('error_message.11001')
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

    private function checkMemberCode($code = '')
    {
        $data = DB::table('members as m')
            ->join('member_agent as ma', 'ma.member_id', 'm.id')
            ->join('member_codes as mc', function ($join) use ($code) {
                $join->on('mc.member_id', 'm.id')
                    ->where('mc.agent_code', $code);
            })
            ->select(
                'mc.member_id',
                'ma.parent_id',
                'ma.topper_member_id',
                'ma.agent_level',
                'ma.agent_relation',
                'm.parent as agent_id'
            )
            ->first();
        return $data;
    }

    private function checkMemberCodeForEvent($member_id = 0)
    {
        $data = DB::table('members as m')
            ->join('member_agent as ma', 'ma.member_id', 'm.id')
            ->where('m.id', $member_id)
            ->select(
                'm.id as member_id',
                'ma.parent_id',
                'ma.topper_member_id',
                'ma.agent_level',
                'ma.agent_relation',
                'm.parent as agent_id'
            )
            ->first();
        return $data;
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
            'username.alpha_num' => __('error_message.1025'),
            'username.r_alpha_num' => __('error_message.1099'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'code.required' => __('error_message.1027'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'payment_password.required' => __('error_message.1016'),
            'payment_password.between' => __('error_message.1017'),
            'payment_password.confirmed' => __('error_message.1021'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),
            'email.unique' => __('error_message.1088'),

            //'first_name.r_alpha_dash' => __('error_message.1031'),
            'first_name.required' => __('error_message.1095'),
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