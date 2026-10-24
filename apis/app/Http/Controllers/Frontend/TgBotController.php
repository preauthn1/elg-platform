<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\General\HttpCode;
use App\Enums\MemberTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\CompleteProfileRequest;
use App\Http\Requests\Frontend\TgBot\DeleteTgUserRequest;
use App\Http\Requests\Frontend\TgBot\OauthLoginRequest;
use App\Http\Requests\Frontend\TgBot\WebAppLoginRequest;
use App\Http\Resources\Frontend\Tgbot\WebAppLoginResource;
use App\Models\Agent;
use App\Models\AgentDomain;
use App\Models\Brand;
use App\Models\BrandAgent;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\MemberTelegramRel;
use App\Models\PrizeContent;
use App\Services\AgentService;
use App\Services\EventService;
use App\Services\Frontend\ValidateToken\MobileBindService;
use App\Services\LoginService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberProfileCompletionService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

#[Group('TgBot (Telegram bot)')]
class TgBotController extends Controller
{
    public function __construct(
        protected LoginService $loginService,
        protected RegisterService $registerService,
        protected EventService $eventService,
        protected MemberService $memberService,
        protected AgentService $agentService,
        protected MemberProfileCompletionService $profileCompletionService,
        protected MemberFieldSettingService $memberFieldSettingService,
    ) {}

    /**
     * 補資料整合（target/tickets#3969）：登入後一次補齊實名、支付密碼、手機與信箱（驗證碼綁定）、生日，
     * 會員不必逐項各打一支 API，也不必綁定後重新登入。
     * 哪些要補：支付密碼看代理設定「支付密碼開關」（AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE，
     * 6051）；其餘欄位看代理的註冊欄位設定（target/tickets#3970）。一個原子流程完成後回傳新 token。
     * 業務下沉 MemberProfileCompletionService。
     */
    public function completeProfile(CompleteProfileRequest $request)
    {
        $member = $this->getUserContext()->member;
        if (empty($member)) {
            return ApiResponse::error(__('error_message.1007'));
        }

        $token = [REDACTED], $request->validated());

        return ApiResponse::success(['token' => $token]);
    }

    /**
     * 綁定手機
     * - 由 tgbot 專案呼叫
     */
    public function bindMobile(Request $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;
        if (empty($member)) {
            return ApiResponse::error(__('error_message.1007'));
        }

        if ($member->mobile && $member->mobile_validate == 1) {
            return ApiResponse::error(__('error_message.9012'));
        }

        $mobile = $request->mobile;

        $exists = $this->memberService->hasReachedMobileBindingLimit($mobile, $agent);

        if ($exists) {
            return ApiResponse::error(__('error_message.9015'));
        }

        // 創建 MobileBindService 實例並傳入會員
        $mobileBindService = new MobileBindService($member);
        $mobileBindService->updateMemberAndGiveReward($mobile);

        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
        ]);
    }

    /**
     * 取得 BOT Brands 列表
     * - 由 tgbot 專案呼叫
     */
    public function getBrandList(Request $request)
    {
        $key = $request->key ?? '';
        if (config('encrypt.aes.md5') != $key) {
            return response()->json([
                'code' => 9999,
                'message' => __('error_message.9999'),
            ]);
        }
        $model = Brand::Join('brand_agents', 'brands.id', 'brand_agents.brand_id')
            ->join('agents', 'brand_agents.agent_id', 'agents.id')
            ->where('brands.status', 1)
            ->where('agents.status', 1)
            ->select(
                'brands.id',
                'brands.name',
                'brands.code',
                'brands.bot_id',
                'brands.bot_token',
                'agents.code as invite_code'
            );
        $brands = $model->get();

        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $brands,
        ]);
    }

    public function getNewMemberList(Request $request)
    {
        $key = $request->key ?? '';
        $latest_id = $request->latest_id ?? 0;

        if (config('encrypt.aes.md5') != $key) {
            return response()->json([
                'code' => 9999,
                'message' => __('error_message.9999'),
            ]);
        }

        $members = MemberTelegramRel::query()
            ->with('brand:id,code,bot_id')
            ->when($latest_id > 0, function ($query) use ($latest_id) {
                $query->where('id', '>', $latest_id);
            })
            ->when($latest_id == 0, function ($query) {
                $query->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-5 mins')));
            })
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $members,
        ]);
    }

    /**
     * 取代理的正常類型網域，該代理沒有就沿 hierarchy 往上層代理找
     * - 行為與舊系統 API_Frontend 的 AgentDomainService::findByAgentId($agentId, 0) 相同
     */
    private function findAgentDomain(Agent $agent): ?AgentDomain
    {
        $agentIds = $agent->hierarchy_array ?: [$agent->id];

        foreach ($agentIds as $agentId) {
            $agentDomain = AgentDomain::query()
                ->where('agent_id', $agentId)
                ->where('status', 1)
                ->where('type', 0)
                ->orderByDesc('sort')
                ->first();

            if ($agentDomain) {
                return $agentDomain;
            }
        }

        return null;
    }

    private function getLanguageCode(Request $request)
    {
        $locale = $request->header('Accept-Language');
        $locale = explode(',', $locale)[0];
        $locale = strtolower(trim($locale));

        // 將 tgbot 專案的語系轉為 DB的語系
        $languageCode = match ($locale) {
            'en-us' => 'en',
            'mm-my' => 'my-mm',
            default => $locale,
        };

        return $languageCode;
    }

    /**
     * 取得BOT Brand info
     * - 由 tgbot 專案呼叫
     */
    public function getBrandInfo(Request $request)
    {
        $brandCode = $request->header('x-bot-ID');
        $tgUserId = $request->header('x-bot-tgID');
        $languageCode = $this->getLanguageCode($request);

        $brand = Brand::query()->where('code', $brandCode)->first();
        if (!is_object($brand)) {
            return response()->json([
                'code' => 9997,
                'message' => __('error_message.9997'),
            ]);
        }
        $brandAgent = BrandAgent::query()->where('brand_id', $brand->id)->first();
        if (!is_object($brandAgent)) {
            return response()->json([
                'code' => 9997,
                'message' => __('error_message.9997'),
            ]);
        }
        $info = json_decode($brandAgent->info);
        // 多語系內容
        $langContent = json_decode($brandAgent->lang_content, true);
        // 預設語系內容
        $defaultLanguage = $info->DEFAULT_LANGUAGE ?? 'en';
        $defaultLangContent = $langContent[$defaultLanguage] ?? [];

        // 用戶語系內容
        $currentLangContent = $langContent[$languageCode] ?? [];

        $agent = $brandAgent->agent;
        $member = $this->getMemberFromTelegramRel($brand->id, $tgUserId);
        if (!$member) {
            $memberCode = '';
        } else {
            $memberCode = MemberCode::where('member_id', $member->id)->first();
            $memberCode = $memberCode->agent_code ?? '';
        }

        // 產生 tg 分享連結
        $shareLink = '';
        if (!empty($memberCode)) {
            $inviteLink = 'https://example.invalid/' . $brand->bot_id . '/startapp?startapp=' . $memberCode;
            $shareText = $this->langContent($currentLangContent, $defaultLangContent, 'share_content', 'Welcome !!');
            $shareLink = 'https://example.invalid/share/url?url=' . rawurlencode($inviteLink) . '&text=' . rawurlencode($shareText);
        }

        // 取得代理首頁網址
        $agentDomain = $this->findAgentDomain($agent);
        if (!$agentDomain) {
            return response()->json([
                'code' => 9997,
                'message' => __('error_message.9997'),
            ]);
        }
        $url = sprintf('https://%s', $agentDomain->domain);

        $data = [];
        $data['webapp_login_link'] = sprintf($info->WEBAPP_LOGIN_LINK, $url, $brandCode);
        $data['event_link'] = sprintf($info->EVENT_LINK, $url, $brandCode);
        $data['agent_link'] = sprintf($info->AGENT_LINK, $url, $brandCode);
        $data['withdrawal_link'] = sprintf($info->WITHDRAWAL_LINK, $url, $brandCode);
        // $data['cs_link'] = sprintf($info->CS_LINK, $url);
        $data['wallet_link'] = sprintf($info->WALLET_LINK, $url, $brandCode);
        $data['launch_link'] = $data['webapp_login_link'];
        $data['vip_link'] = sprintf($info->VIP_LINK, $url, $brandCode);
        $data['history_link'] = sprintf($info->HISTORY_LINK, $url, $brandCode);
        $data['share_link'] = $shareLink;
        // $data['offcial_tg'] = $info->OFFICIAL_LINK; // 客服連結
        // $data['cs_tg'] = $info->CS_LINK; // 官方TG連結
        $data['agent_id'] = $agent->id;
        $data['invite_code'] = $agent->code;
        $data['platform_name'] = $request->BRAND;
        $defaultCoverMedia = [
            'type' => 'photo',
            'url' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png',
        ];

        $data['main_button_cs_pre_login'] = $info->MAIN_BUTTON_CS_PRE_LOGIN ?? 1;
        $data['main_button_official'] = $info->MAIN_BUTTON_OFFICIAL ?? 1;

        $data['main_button_wallet'] = $info->MAIN_BUTTON_WALLET ?? 1;
        $data['main_button_vip'] = $info->MAIN_BUTTON_VIP ?? 0;
        $data['main_button_event'] = $info->MAIN_BUTTON_EVENT ?? 1;
        $data['main_button_invite'] = $info->MAIN_BUTTON_INVITE ?? 0;
        $data['main_button_checkin'] = $info->MAIN_BUTTON_CHECKIN ?? 0;
        $data['main_button_mission'] = $info->MAIN_BUTTON_MISSION ?? 0;
        $data['main_button_history'] = $info->MAIN_BUTTON_HISTORY ?? 1;
        $data['main_button_cs_post_login'] = $info->MAIN_BUTTON_CS_POST_LOGIN ?? 1;
        $data['main_button_homepage'] = $info->MAIN_BUTTON_HOMEPAGE ?? 0;
        $data['main_button_share'] = $info->MAIN_BUTTON_SHARE ?? 0;
        $data['bot_mode'] = $info->BOT_MODE ?? 1;
        $data['default_language'] = $defaultLanguage;

        // 多語系配置
        // {
        //     "start_message": "",
        //     "start_media": {
        //       "type": "photo",
        //       "url": ""
        //     },
        //     "restart_message": "",
        //     "restart_media": {
        //       "type": "photo",
        //       "url": ""
        //     },
        //     "share_button_name": "",
        //     "share_content": ""
        //   }
        $data['start_message'] = $this->langContent($currentLangContent, $defaultLangContent, 'start_message');
        $data['restart_message'] = $this->langContent($currentLangContent, $defaultLangContent, 'restart_message');
        $data['share_button_name'] = $this->langContent($currentLangContent, $defaultLangContent, 'share_button_name');
        $data['start_cover_media'] = $this->langContent($currentLangContent, $defaultLangContent, 'start_media', $defaultCoverMedia, 'url');
        $data['restart_cover_media'] = $this->langContent($currentLangContent, $defaultLangContent, 'restart_media', $defaultCoverMedia, 'url');

        $linkButtonsConfig = $this->langContent($currentLangContent, $defaultLangContent, 'link_buttons_config');
        $data['link_buttons_config'] = $linkButtonsConfig === '' ? [] : json_decode($linkButtonsConfig, true);

        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $data,
        ]);
    }

    /**
     * 多語系欄位取值：用戶語系 → 預設語系 → fallback。
     *
     * media 類欄位（值為 ['type' => ..., 'url' => ...]）以 $requiredKey='url' 判斷是否有內容，
     * 判空一律用 empty() 的語意。
     *
     * @param  array<string, mixed>  $currentLangContent  用戶語系內容
     * @param  array<string, mixed>  $defaultLangContent  預設語系內容
     * @param  string|null  $requiredKey  值為陣列時，用來判斷有無內容的子鍵
     */
    private function langContent(
        array $currentLangContent,
        array $defaultLangContent,
        string $key,
        mixed $fallback = '',
        ?string $requiredKey = null,
    ): mixed {
        foreach ([$currentLangContent, $defaultLangContent] as $langContent) {
            $value = $langContent[$key] ?? null;

            if (!empty($requiredKey ? ($value[$requiredKey] ?? null) : $value)) {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * 使用 domain 取得 bot 資料
     */
    public function getBrandByDomain(Request $request)
    {
        // domain 找代理
        $return = Cache::remember(CacheKeys::tgbotBrandByDomain($request->domain), CacheTtl::ONE_HOUR->value, function () use ($request) {
            $agentDomain = AgentDomain::query()
                ->where('domain', $request->domain)
                ->first();
            if (!$agentDomain) {
                return [];
            }

            // 網域的代理
            $domainAgentId = $agentDomain->agent_id;

            // 代理的 bot
            $brandAgent = BrandAgent::query()
                ->whereHas('agent', function ($query) use ($domainAgentId) {
                    $query->where('hierarchy', 'like', '%,' . $domainAgentId . ',%');
                })
                ->whereHas('brand', function ($query) {
                    $query->where('status', 1);
                })
                ->with(['brand' => function ($query) {
                    $query->where('status', 1);
                }])
                ->orderBy('sort', 'desc')
                ->first();

            if (!$brandAgent) {
                return [];
            }

            $brand = $brandAgent->brand;
            $info = json_decode($brandAgent->info, true);

            $return = [];
            /** @var AgentDomain $brand */
            // token 轉化為 id
            // 7893794809:AAEPIW6QhOD0n21MB9*************** -> 7893794809
            $tokenArr = explode(':', $brand->bot_token);

            if (count($tokenArr) > 1) {
                $botId = $tokenArr[0];
            } else {
                $botId = '';
            }

            $return[] = [
                'bot_id' => $botId,
                'bot_username' => $brand->bot_id,
                'tg_share_text' => $info['TG_SHARE_TEXT'] ?? '',
                'login_domain' => $info['LOGIN_DOMAIN'] ?? '',
            ];

            return $return;
        });

        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $return,
        ]);
    }

    public function oauthLogin(OauthLoginRequest $request)
    {
        $input = $request->validated();

        // 取得 tg 用戶資料
        // example: 'query_id=AAHEECJFAwAAAMQQIkWY33An&user=%7B%22id%22%3A7602311364%2C%22first_name%22%3A%22Tim%20T%22%2C%22last_name%22%3A%22%22%2C%22language_code%22%3A%22zh-hans%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%example.invalid%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2FATR6lKDM6yAmu6jumGAEsYwa9XMk4rNUvILZA8YvwchqhKPe_VyMUd5wjE3g0ytA.svg%22%7D&auth_date=1748314899&signature=ifgIYz4_ihBYoPQHE94ASjglaYvrVrdMqLclDrV5XCT4EXjv0kw7ghIaTFnQGRJKHZPMy5W_g1yLA6M9uBPIDA&hash=2adc201ca0e4ebe8ef97c16420fc427e420cbc7f1acdaec931e6b7b56f9bf50d'
        $user = $input['user'];
        $botId = $input['bot_username']; // DB 的 bot_id 實際上是 tg 的 username
        $code = $input['code'] ?? '';

        // 取得 brand 跟 agent
        $brand = Brand::query()
            ->with('info.agent')
            ->where('bot_id', $botId)
            ->first();

        if (!$brand) {
            throw new ApiException(__('error_message.1050'));
        }

        $agent = $brand->info->agent;

        // 驗簽
        $checkHash = $this->checkOauthHash($brand->bot_token, $user);
        if (!$checkHash) {
            throw new ApiException(__('error_message.9999'));
        }

        // 建帳判斷關閉模式、取帳號前綴用 bot 的代理；帶邀請碼時下方會把 $agent 覆寫成邀請碼代理，要先留住
        $botAgent = $agent;

        // 使用邀請碼取得 agent 跟 sponsor
        $sponsor = null;
        $eventSummary = null;
        if (!empty($code)) {
            [$agent, $sponsor, $eventSummary, $memberType] = $this->registerService->detectAgentAndSponsor($code, 0, $agent);
        }

        // 開始建立會員
        $chat_id = $user['id'];
        $member = $this->getMemberFromTelegramRel($brand->id, $chat_id);
        if (!is_object($member)) {
            // 建立會員前檢查是否需要輸入邀請碼:依 3970 config 的註冊欄位 referral_code 是否必填。
            // 判斷基準為 bot 綁定的代理($code 為空時 $agent 未被邀請碼重新指派)
            if (empty($code) && $this->memberFieldSettingService->isReferralCodeRequired($agent)) {
                throw new ApiException(__('error_message.9999'), HttpCode::HTTP_REDIRECT_INPUT_INVITE_CODE->value);
            }
            try {
                DB::beginTransaction();

                $member = $this->tgBotCreateMember($brand, $agent, $botAgent, $user, $sponsor, $code);

                // 邀請玩家獎勵
                if ($eventSummary) {
                    // 寫入預派紀錄 TODO: 不應該耦合在此, 放在 SP_event_register 比較合適
                    if ($prizeContent = PrizeContent::find($eventSummary->prize_content_id)) {
                        $prizeContent->increment('distribute');
                    }
                    $this->eventService->distributeInviteEvent($member);
                }
                DB::commit();

                // 註冊完成後，立即呼叫CS API，同步cs DB的資料
                $this->registerService->triggerCsSyncData($agent, $member->username);
                $this->registerService->runRegisterEventStoredProcedures($member, $agent);
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        // 取得 token
        $memberAccessToken = [REDACTED];

        // 更新會員登入數據
        $this->loginService->updateMemberLoginData($member, $input);

        // 新增登入記錄
        $this->loginService->logMemberLogin($member->id, 1);

        // 新增登入IP記錄
        $this->loginService->logMemberIp($member->id, $agent->hierarchy_path, Common::getIp());

        // 檢查單日是否有相同IP不同帳號的登入
        $this->loginService->warningSameIpMember($member, Common::getIp());

        $resource = new WebAppLoginResource(['token' => $memberAccessToken]);

        return ApiResponse::success($resource);
    }

    public function webAppLogin(WebAppLoginRequest $request)
    {
        $input = $request->validated();

        // 取得 tg 用戶資料
        // example: 'query_id=AAHEECJFAwAAAMQQIkWY33An&user=%7B%22id%22%3A7602311364%2C%22first_name%22%3A%22Tim%20T%22%2C%22last_name%22%3A%22%22%2C%22language_code%22%3A%22zh-hans%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%example.invalid%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2FATR6lKDM6yAmu6jumGAEsYwa9XMk4rNUvILZA8YvwchqhKPe_VyMUd5wjE3g0ytA.svg%22%7D&auth_date=1748314899&signature=ifgIYz4_ihBYoPQHE94ASjglaYvrVrdMqLclDrV5XCT4EXjv0kw7ghIaTFnQGRJKHZPMy5W_g1yLA6M9uBPIDA&hash=2adc201ca0e4ebe8ef97c16420fc427e420cbc7f1acdaec931e6b7b56f9bf50d'
        $initDataStr = $input['init_data'];
        $brandCode = $input['brand_code'];
        $code = $input['code'] ?? '';

        // 取得 brand 跟 agent
        $brand = Brand::query()
            ->with('info.agent')
            ->where('code', $brandCode)
            ->first();

        if (!$brand) {
            throw new ApiException(__('error_message.1050'));
        }

        $agent = $brand->info->agent;

        // 驗簽
        parse_str($initDataStr, $initData);
        $user = json_decode($initData['user'], true);

        $checkHash = $this->checkWebAppHash($brand->bot_token, $initDataStr);
        if (!$checkHash) {
            throw new ApiException(__('error_message.9999'));
        }

        // 建帳判斷關閉模式、取帳號前綴用 bot 的代理；帶邀請碼時下方會把 $agent 覆寫成邀請碼代理，要先留住
        $botAgent = $agent;

        // 使用邀請碼取得 agent 跟 sponsor
        if (!empty($code)) {
            [$agent, $sponsor, $eventSummary, $memberType] = $this->registerService->detectAgentAndSponsor($code, 0, $agent);
        } else {
            $sponsor = null;
            $eventSummary = null;
        }

        // 開始建立會員
        $chat_id = $user['id'];
        $member = $this->getMemberFromTelegramRel($brand->id, $chat_id);
        if (!is_object($member)) {
            // 建立會員前檢查是否需要輸入邀請碼:依 3970 config 的註冊欄位 referral_code 是否必填。
            // 判斷基準為 bot 綁定的代理($code 為空時 $agent 未被邀請碼重新指派)
            if (empty($code) && $this->memberFieldSettingService->isReferralCodeRequired($agent)) {
                throw new ApiException(__('error_message.9999'), HttpCode::HTTP_REDIRECT_INPUT_INVITE_CODE->value);
            }
            try {
                DB::beginTransaction();

                $member = $this->tgBotCreateMember($brand, $agent, $botAgent, $user, $sponsor, $code);

                // 邀請玩家獎勵
                if ($eventSummary) {
                    $this->eventService->distributeInviteEvent($member);
                }
                DB::commit();

                // 註冊完成後，立即呼叫CS API，同步cs DB的資料
                $this->registerService->triggerCsSyncData($agent, $member->username);
                $this->registerService->runRegisterEventStoredProcedures($member, $agent);
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        // 取得 token
        $memberAccessToken = [REDACTED];

        // 更新會員登入數據
        $this->loginService->updateMemberLoginData($member, $input);

        // 新增登入記錄
        $this->loginService->logMemberLogin($member->id, 1);

        // 新增登入IP記錄
        $this->loginService->logMemberIp($member->id, $agent->hierarchy_path, Common::getIp());

        // 檢查單日是否有相同IP不同帳號的登入
        $this->loginService->warningSameIpMember($member, Common::getIp());

        $resource = new WebAppLoginResource(['token' => $memberAccessToken]);

        return ApiResponse::success($resource);
    }

    /**
     * 建立會員
     *
     * 帳號：bot 代理的組別在關閉模式時用前綴自動產生（「前綴-uniqid」），否則沿用 uniqid。
     * TG 建帳沒有手機號碼，一號一帳在會員之後補手機時由綁定上限檢查套用。
     *
     * @param  Agent  $agent  會員的上級代理（帶邀請碼時為邀請碼代理）
     * @param  Agent  $botAgent  bot 綁定的代理，判斷關閉模式與取帳號前綴用
     * @param  array  $user  telegram sdk user data
     */
    private function tgBotCreateMember(Brand $brand, Agent $agent, Agent $botAgent, array $user, ?Member $sponsor = null, string $code = '', int $memberType = MemberTypeEnum::NORMAL->value): Member
    {
        // 開始建立會員
        $chat_id = $user['id'];
        $first_name = $user['first_name'] ?? '';
        $last_name = $user['last_name'] ?? '';
        $nickname = $first_name . $last_name;
        $regi_ip = Common::getIp();

        // 註冊帳號
        $usernamePrefix = $this->memberFieldSettingService->usernamePrefix($botAgent);
        $username = $usernamePrefix === null ? uniqid() : $this->registerService->generateUsername($usernamePrefix);
        $password = [REDACTED], 0, 8);
        $insertData = [];
        $insertData['parent'] = $agent->id;
        $insertData['username'] = $username;
        $insertData['password'] = Hash::make($password);
        // 風控比對用(會員追蹤查同密碼帳號),不參與登入驗證
        $insertData['password_md5'] = md5($password);
        // $insertData['first_name'] = $first_name;
        // $insertData['last_name'] = $last_name;
        $insertData['nickname'] = $nickname;
        $insertData['invitation_code'] = $code;
        $insertData['admin'] = 0;
        $insertData['register_ip'] = ip2long($regi_ip);
        $insertData['status'] = 1;
        $insertData['type'] = $memberType;
        $insertData['previous_day_type'] = $memberType;

        $member = Member::query()->create($insertData);
        if (!$member) {
            throw new ApiException(__('error_message.2001'));
        }
        MemberTelegramRel::create([
            'brand_id' => $brand->id,
            'member_id' => $member->id,
            'tg_user_id' => $chat_id,
            'tg_password' => $password,
        ]);

        // 無限級代理
        if ($agent->agent_mode == 1) {
            $this->registerService->createMemberAgent($member, $agent, $sponsor);
        }

        return $member;
    }

    /**
     * 刪除 tg 用戶
     */
    public function deleteTgUser(DeleteTgUserRequest $request)
    {
        $input = $request->validated();
        $delete_user_token = [REDACTED]'delete_user_token'] ?? '';
        $key = 'tgbot_delete_user_token:' . $delete_user_token;
        $data = Redis::get($key);

        if (empty($data)) {
            throw new ApiException(__('error_message.9998'));
        }

        $brand_id = $data['brand_id'];
        $tg_user_id = $data['tg_user_id'];
        $member_id = $data['member_id'];

        Log::info('deleteTgUser', ['deleteData' => $data]);

        MemberTelegramRel::query()
            ->where('brand_id', $brand_id)
            ->where('tg_user_id', $tg_user_id)
            ->where('member_id', $member_id)
            ->delete();

        Redis::del($key);

        return ApiResponse::success();
    }

    /**
     * 驗證 WebAppHash, 參考下列網址
     * https://example.invalid/MakStashkevich/1a4600819af872420d566a4297ac99df
     */
    private function checkWebAppHash(string $bot_token, string $data_check_string): bool
    {
        $data_check_arr = explode('&', rawurldecode($data_check_string));
        $needle = 'hash=';
        $check_hash = false;
        foreach ($data_check_arr as &$val) {
            if (substr($val, 0, strlen($needle)) === $needle) {
                $check_hash = substr_replace($val, '', 0, strlen($needle));
                $val = null;
            }
        }

        $data_check_arr = array_filter($data_check_arr);
        sort($data_check_arr);

        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = [REDACTED]'sha256', $bot_token, 'WebAppData', true);
        $hash = bin2hex(hash_hmac('sha256', $data_check_string, $secret_key, true));

        if (strcmp($hash, $check_hash) === 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 驗證 OauthHash, 參考下列網址
     * https://example.invalid/mcnaveen/fb67c3c3197bb3480372b055d9e943f7
     */
    private function checkOauthHash(string $bot_token, array $auth_data): bool
    {
        $check_hash = $auth_data['hash'];
        unset($auth_data['hash']);
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = [REDACTED]'sha256', $bot_token, true);
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        if (strcmp($hash, $check_hash) !== 0) {
            return false;
        }
        if ((time() - $auth_data['auth_date']) > 86400) {
            return false;
        }

        return true;
    }

    /**
     * 每個 bot 獨立一個帳號
     * http://example.invalid:1688/target/tickets/-/issues/2781
     *
     * @param  int  $brand_id
     * @param  int  $tg_user_id
     */
    private function getMemberFromTelegramRel($brand_id, int|string|null $tg_user_id): ?Member
    {
        // 找出與目前 brand 相同代理的所有 brand_id
        $brand_id_arr = BrandAgent::query()
            ->whereIn('agent_id', function ($query) use ($brand_id) {
                $query->select('agent_id')->from('brand_agents')->where('brand_id', $brand_id);
            })->pluck('brand_id')->toArray();

        // 找出這些 brand_id 下的用戶
        $member = Member::query()
            ->with('telegramRels')
            ->whereHas('telegramRels', function ($query) use ($brand_id_arr, $tg_user_id) {
                $query->whereIn('brand_id', $brand_id_arr)
                    ->where('tg_user_id', $tg_user_id);
            })
            ->first();

        return $member;

        // if (is_object($member)) {
        //     return $member;
        // }

        // // 沒找到用戶的話查詢組織下是否有用戶
        // $brand = Brand::with('info.agent')->whereHas('info.agent')->find($brand_id);
        // if (!is_object($brand)) {
        //     throw new ApiException(__('error_message.1050'));
        // }
        // $agent = $brand->info->agent;

        // $member = Member::query()
        //     ->with('agent')
        //     ->with('telegramRels')
        //     ->whereHas('telegramRels', function ($query) use ($tg_user_id) {
        //         $query->where('tg_user_id', $tg_user_id);
        //     })
        //     ->whereHas('agent', function ($query) use ($agent) {
        //         $query->where('organization_id', $agent->organization_id);
        //     })
        //     ->first();

        // if (!is_object($member)) {
        //     // 組織下沒有用戶, 進入註冊程序
        //     return null;
        // }

        // // 允許跨 brand
        // $source_brand_id = $member->telegramRels->first()->brand_id;
        // $source_brand = Brand::query()
        //     ->whereHas('info')
        //     ->with('info')
        //     ->find($source_brand_id);
        // if (is_object($source_brand)) {
        //     $source_brand_bot_id = $source_brand->bot_id;
        //     $source_brand_agent_id = $source_brand->info->agent_id;
        // } else {
        //     $source_brand_bot_id = 0;
        //     $source_brand_agent_id = 0;
        // }

        // if ($is_allow_cross_brand) {
        //     // 檢查傳入的 brand 跟找到的 brand 的 agent 是否相同
        //     if ($agent->id == $source_brand_agent_id) {
        //         return $member;
        //     }
        // }

        // // 其他情況報錯不進入註冊程序
        // $delete_user_token = [REDACTED], $tg_user_id, $member->id);
        // $logData = [
        //     'tg_user_id' => $tg_user_id,
        //     'brand_id' => $brand_id,
        //     'source_id' => $source_brand_bot_id,
        //     'delete_user_token' => $delete_user_token,
        //     'source_brand_id' => $source_brand_id,
        // ];
        // Log::info('WebAppLogin_Error', $logData);

        // // 格式不要動
        // throw new ApiException(
        //     json_encode([
        //         'tg_user_id' => $tg_user_id,
        //         'brand_id' => $brand_id,
        //         'source_id' => $source_brand_bot_id,
        //         'delete_user_token' => $delete_user_token,
        //     ]),
        //     1002
        // );
    }

    private function createDeleteUserToken($brand_id, $tg_user_id, $member_id): string
    {
        $delete_user_token = [REDACTED];
        $data = [
            'brand_id' => $brand_id,
            'tg_user_id' => $tg_user_id,
            'member_id' => $member_id,
            'delete_user_token' => $delete_user_token,
        ];

        $key = 'tgbot_delete_user_token:' . $delete_user_token;
        Redis::set($key, $data, 3600);

        return $delete_user_token;
    }
}
