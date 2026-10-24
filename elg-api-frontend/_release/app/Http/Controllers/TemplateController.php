<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AgentDomain;
use App\Models\Member;
use App\Models\Agent;
use App\REDIS;
use App\Enums\AgentCustomizedSettingKeyEnum;
use DB;
use Validator;

/**
 * @group Template
 */
class TemplateController extends Controller
{

    /**
     * template
     * - 取得模版編號(Header不需要token)
     *
     * @bodyParam domain string required 域名 Example: example.invalid
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":  {
     *          "id":"模版編號,ex:1",
     *          "name":"模版名稱,ex:v1",
     *          "logo_pc":"電腦版LOGO,ex:http://xxxx.xxx.xxx",
     *          "logo_mobile":"手機版LOGO,ex:http://xxxx.xxx.xxx",
     *          "icon":"favorite icon,ex:http://xxxx.xxx.xxx",
     *          "title":"網站名稱,ex:XSG CASINO",
     *          "frontend_register":"前端是否開放註冊0:不開放1:開放,ex:1",
     *          "has_chat":"是否開放聊天室, 0:不開放1:開放,ex:1",
     *          "has_pc":"是否有PC版，0:沒有1:有,ex:1",
     *          "has_mobile":"是否有手機版，0:沒有1:有,ex:1",
     *          "has_online_payment":"是否有線上儲值，0:沒有1:有,ex:1",
     *          "has_app":"是否有APP下載，0:沒有1:有,ex:1",
     *          "transaction_all":"進入遊戲時是否將所有錢轉入，0:否1:是,ex:1",
     *          "email_validate":"登入是否需要驗證過email(0:不需1:必需),ex:1",
     *          "mobile_validate":"登入是否需要驗證過手機號碼(0:不需1:必需),ex:1",
     *          "CS_API_USER":"連接客服系統API USER,ex:nExJ2bb0R0",
     *          "CS_API_KEY":"連接客服系統API KEY,ex:kLrVoAE3gFouxm6mzLU3ADVa",
     *          "has_agent_layout":"無限代理模式(0:關閉1:開啟),ex:1"
     *  }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new AgentDomain();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $key = array(
                'domain_v12',
                $request->ID,
                $request->domain,
                $request->LANGUAGE,
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if (isset($request->ID)) {
                    //己登入依照agent
                    $agent = DB::table('members as m')
                        ->join('agents as a', 'a.id', 'm.parent')
                        ->where('m.id', $request->ID)
                        ->select(
                            'a.id',
                            'a.hierarchy',
                            'a.frontend_register',
                            'a.has_chat',
                            'a.has_pc',
                            'a.has_mobile',
                            'a.has_online_payment',
                            'a.has_app',
                            'a.transaction_all',
                            'a.email_validate',
                            'a.mobile_validate',
                            'a.CS_API_USER',
                            'a.CS_API_KEY',
                            'a.agent_mode as has_agent_layout',
                            'a.customized_setting_group_id'
                        )
                        ->first();
                    $array = explode(',', $agent->hierarchy);
                    //頭尾兩個會是無效的
                    // for ($i = 1; $i < (count($array) - 1); $i++) {
                    $templates = $model->from('agent_domains as d')
                        ->leftJoin('templates as t', 'd.template_id', 't.id')
                        ->whereIn('d.agent_id', $array)
                        ->where('d.status', 1)
                        ->select('t.id', 't.name', 'd.logo_pc', 'd.logo_mobile', 'd.icon', 'd.title', 'd.domain')
                        ->get();

                    if (count($templates) == 1) {
                        //只有一個版就不用選
                        $data = $templates[0];
                    } elseif (count($templates) > 1) {
                        //比對都不對的話就挑第一個
                        $data = $templates[0];
                        //多於一個版需比對域名
                        foreach ($templates as $row) {
                            if ($row->domain == $request->domain) {
                                $data = $row;
                            }
                        }
                    } else {
                        //沒有版型就用公版
                        return response()->json([
                            'code' => 1213,
                            'message' => __('error_message.1213'),
                            'data' => 0
                        ]);
                    }

                    $data->frontend_register = $agent->frontend_register;
                    $data->has_chat = $agent->has_chat;
                    $data->has_pc = $agent->has_pc;
                    $data->has_mobile = $agent->has_mobile;
                    $data->has_online_payment = $agent->has_online_payment;
                    $data->has_app = $agent->has_app;
                    $data->transaction_all = $agent->transaction_all;
                    $data->email_validate = $agent->email_validate;
                    $data->mobile_validate = $agent->mobile_validate;
                    $data->CS_API_USER = $agent->CS_API_USER;
                    $data->CS_API_KEY = [REDACTED];
                    $data->has_agent_layout = $agent->has_agent_layout;
                    $data->has_PGS_layout = ($agent->id) == env('PGS_LAYOUT_LOBBY_AGENT') ? 1 : 0;
                    $data->has_show_media = $this->get_agent_media($agent->id);
                    $data->customized_setting = $this->get_customized_setting($agent->customized_setting_group_id, $request->LANGUAGE);
                } else {
                    //未登入依照域名
                    $data = $model->from('agent_domains as d')
                        ->leftJoin('templates as t', 'd.template_id', 't.id')
                        ->leftJoin('agents as a', 'a.id', 'd.agent_id')
                        ->select(
                            't.id',
                            't.name',
                            'd.logo_pc',
                            'd.logo_mobile',
                            'd.icon',
                            'd.title',
                            'a.frontend_register',
                            'a.has_chat',
                            'a.has_pc',
                            'a.has_mobile',
                            'a.has_online_payment',
                            'a.has_app',
                            'a.transaction_all',
                            'a.email_validate',
                            'a.mobile_validate',
                            'a.CS_API_USER',
                            'a.CS_API_KEY',
                            'a.agent_mode as has_agent_layout',
                            'a.customized_setting_group_id',
                            'a.code'
                        )
                        ->where('d.domain', $request->domain)
                        ->where('d.status', 1)
                        ->first();

                    //取得額外設置
                    if (is_object($data)) {
                        $data->customized_setting = $this->get_customized_setting($data->customized_setting_group_id, $request->LANGUAGE);
                    }
                }

                if (!is_object($data)) {
                    return response()->json([
                        'code' => 1213,
                        'message' => __('error_message.1213'),
                        'data' => 0
                    ]);
                }
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
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

    private function get_customized_setting($agent_group_id, $lang)
    {
        $query = DB::table('agent_customized_settings')
            ->where('agent_group_id', $agent_group_id)
            ->get();

        $result = array();
        $result["cs_icon_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::CS_IS_CUSTOM_SERVICE_ICON_VISIBLE);
        $result["cs_icon_link_url"] = $this->get_settingText($query, AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ICON_LINK_URL);
        $result["cs_add_friend"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ADD_FRIEND);

        $result["v_jackpot_reward_item"] = $this->get_target_language_settingText($query, $lang, AgentCustomizedSettingKeyEnum::LABEL_TEXT_V_JACKPOT_REWARD_ITEM);
        $result["text_wallet_point"] = $this->get_target_language_settingText($query, $lang, AgentCustomizedSettingKeyEnum::LABEL_TEXT_WALLET_POINT);
        $result["text_event_reward"] = $this->get_target_language_settingText($query, $lang, AgentCustomizedSettingKeyEnum::LABEL_TEXT_EVENT_REWARD);
        $result["text_unlimited"] = $this->get_target_language_settingText($query, $lang, AgentCustomizedSettingKeyEnum::LABEL_TEXT_UNLIMITED);
        $result["text_jackpot"] = $this->get_target_language_settingText($query, $lang, AgentCustomizedSettingKeyEnum::LABEL_TEXT_JACKPOT);
        $result["text_withdrawal"] = $this->get_target_language_settingText($query, $lang, AgentCustomizedSettingKeyEnum::LABEL_TEXT_WITHDRAWAL);

        $result["has_event"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::STYLE_HAS_EVENT, 1);
        $result["has_pgs_lobby_layout"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::STYLE_HAS_PGS_LOBBY_LAYOUT);
        $result["has_deposit_record_page"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::STYLE_HAS_DEPOSIT_RECORD_PAGE);

        $result["game_page_loading_image_url"] = $this->get_settingText($query, AgentCustomizedSettingKeyEnum::STYLE_IMAGE_GAME_PAGE_LOADING_ICON);
        $result["is_auto_agent_code"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::AUTO_AGENT_CODE);

        $result["withdrawal_button_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::WITHDRAWAL_BUTTON_VISIBLE);
        $result["deposit_button_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::DEPOSIT_BUTTON_VISIBLE);
        $result["instant_recharge_button_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::INSTANT_RECHARGE_BUTTON_VISIBLE);
        $result["flow_limit_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE);
        $result["flow_limit_display_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_DISPLAY_VISIBLE);
        $result["is_unlimit_visible"] = $this->get_boolean($query, AgentCustomizedSettingKeyEnum::STYLE_IS_UNLIMIT_VISIBLE);
        
        /* tip: 建議每次有新增 新的客製化設置 要修正 cacheKey。 (e.g. domain_v5 -> domain_v6 */
        /* tip: 建議每次有新增 新的客製化設置 要修正 cacheKey。 (e.g. domain_v5 -> domain_v6 */
        /* tip: 建議每次有新增 新的客製化設置 要修正 cacheKey。 (e.g. domain_v5 -> domain_v6 */
        /* tip: 建議每次有新增 新的客製化設置 要修正 cacheKey。 (e.g. domain_v5 -> domain_v6 */
        return $result;
    }

    private function get_boolean($query, $settingKey, $defaultValue = 0)
    {
        $find = $query->where('setting_key', $settingKey)->first();
        if (!is_object($find)) {
            return $defaultValue;
        }

        return $find->value == "1" ? 1 : 0;
    }

    private function get_settingText($query, $settingKey)
    {
        $find = $query->where('setting_key', $settingKey)->first();

        if (!is_object($find)) {
            return '';
        }

        return $find->value;
    }

    private function get_target_language_settingText($query, $lang, $settingKey)
    {
        $find = $query->where('setting_key', $settingKey)
            ->where('sub_category', $lang)
            ->first();

        if (is_object($find)) {
            return $find->value;
        }
            
        //如果特定語系沒設 取第一個
        $findFirst= $query->where('setting_key', $settingKey)
            ->sortBy('sub_category')
            ->first();

        if (is_object($findFirst)) {
            return  $findFirst->value;
        }

        return "";
    }

    private function get_agent_media($agent_id)
    {
        $agent = Agent::find($agent_id);
        $array = explode(',', $agent->hierarchy);
        for ($i = 1; $i < (count($array) - 1); $i++) {
            //從最上線開始找
            $agent_id = $array[$i];
            $find = DB::table('agent_personal_settings')
                ->where('setting_id', AgentCustomizedSettingKeyEnum::HAS_MEDIA_SHOW)
                ->where('agent_id', $agent_id)
                ->first();
            if (is_object($find)) {
                return $find->value;
                break;
            }
        }
        return 0;
    }

    private function messages()
    {
        return [
            'domain.required' => __('error_message.1209'),
            'domain.domain' => __('error_message.1210')
        ];
    }
}