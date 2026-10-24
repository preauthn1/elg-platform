<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\HttpCode;
use App\Enums\MemberBindingLogTypeEnum;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Member as MemberRequests;
use App\Http\Resources\Frontend\Member as MemberResources;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\MemberBankAccount;
use App\Models\MemberBindingLog;
use App\Models\MemberTrackingEvent;
use App\Models\SummaryBetRecord;
use App\Models\Vendor;
use App\Services\AgentService;
use App\Services\MemberAccessTokenService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberRegisterLimitService;
use App\Services\MemberScoreService;
use App\Services\MemberService;
use App\Services\WalletService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[Group('Member (會員相關)')]
class MemberController extends Controller
{
    public function __construct(
        protected MemberService $memberService,
        protected WalletService $walletService,
        protected AgentService $agentService,
        protected MemberAccessTokenService $memberAccessTokenService,
        protected MemberRegisterLimitService $memberRegisterLimitService,
        protected MemberFieldSettingService $memberFieldSettingService,
        protected MemberScoreService $memberScoreService,
    ) {}

    /**
     * 會員登出
     */
    public function logout()
    {
        $userData = $this->getUserContext();

        if ($userData->member) {
            $this->memberAccessTokenService->revoke($userData->member->id);
        }

        return ApiResponse::success();
    }

    /**
     * 會員詳情
     *
     * OLD: /api/member
     */
    public function detail(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $member->load('memberSetting');
        $memberWallets = $this->walletService->getMemberWallets($member, true, false);
        $flowLimitApplicationStatus = $this->memberService->getFlowLimitApplicationStatus($member);
        // 可用積分：讀 member_wallets type 4 投影列（純唯讀、單列查詢），沒發過積分回 '0.0000'（target/tickets#4234）
        $score = $this->memberScoreService->balance($member->id);

        $resource = new MemberResources\MemberDetailResource($member, $memberWallets, $flowLimitApplicationStatus, $score);

        return ApiResponse::success($resource);
    }

    /**
     * 檢查會員資料是否完善
     *
     * 323 支付密碼 / 324 電話認證 / 325 真實姓名 / 326 Email
     */
    public function checkProfile()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent;
        $agentSettings = $this->agentService->getAgentSettings($agent);

        // 檢查是否需要轉跳綁定銀行卡 327
        $needBankCard = $agentSettings[AgentCustomizedSettingKeyEnum::LOGIN_NEED_BIND_BANK_CARD] ?? 0;
        if ($needBankCard == 1) {
            $memberBankAccountCount = MemberBankAccount::where('member_id', $member->id)->where('status', 1)->count();
            if ($memberBankAccountCount <= 0) {
                // 通知前端轉跳綁定銀行卡
                return ApiResponse::error(
                    message: __('error_message.2040'),
                    code: HttpCode::HTTP_REDIRECT_BIND_BANK_CARD->value,
                );
            }
        }

        // 檢查是否需要轉跳設定實名認證 325
        $realNameVisible = $agentSettings[AgentCustomizedSettingKeyEnum::REAL_NAME_VERIFIED_VISIBLE] ?? 0;
        if ($realNameVisible == 1 && $member->first_name == '') {
            return ApiResponse::error(
                message: __('error_message.1096'),
                code: HttpCode::HTTP_REDIRECT_SET_REALNAME->value,
            );
        }

        // 檢查是否需要轉跳設定支付密碼 323
        $paymentPasswordVisible = $agentSettings[AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE] ?? 0;
        // $paymentPasswordCheckTimingSetting = $agentSettings[AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING] ?? 0;
        if ($paymentPasswordVisible == 1 && $member->payment_password == '') {
            // 通知前端轉跳設定支付密碼
            return ApiResponse::error(
                message: __('error_message.9014'),
                code: HttpCode::HTTP_REDIRECT_SET_PAYMENT_PASSWORD->value,
            );
        }

        // 是否需要電話驗證 324
        if ($agent->mobile_validate == 1 && $member->mobile_validate == 0) {
            return ApiResponse::error(
                message: __('error_message.9007'),
                code: HttpCode::HTTP_REDIRECT_SET_MOBILE->value,
            );
        }

        // 是否需要 Email 驗證 326
        if ($agent->email_validate == 1 && $member->email_validate == 0) {
            return ApiResponse::error(
                message: __('error_message.9008'),
                code: HttpCode::HTTP_REDIRECT_SET_EMAIL->value,
            );
        }

        return ApiResponse::success();
    }

    /**
     * 修改會員資料
     *
     * OLD: /api/update
     */
    public function updateProfile(MemberRequests\UpdateProfileRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $input = $request->validated();

        // 檢查Email是否已超過允許綁定的次射
        if (isset($input['email'])) {
            $hasReachedEmailBindingLimit = $this->memberService->hasReachedEmailBindingLimit($input['email'], $member->agent);

            if ($hasReachedEmailBindingLimit) {
                throw new ApiException(__('error_message.1088'));
            }
        }

        // 過濾空值,保留 0 值
        $filteredInput = array_filter($input, function ($value) {
            return $value !== '' && $value !== null && $value !== false;
        });

        if (!empty($filteredInput)) {
            $member->update($filteredInput);
        }

        return ApiResponse::success();
    }

    /**
     * 修改會員密碼
     *
     * OLD: /api/changePassword
     *
     * @throws ApiException
     */
    public function changePassword(MemberRequests\ChangePasswordRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        if (!Hash::check($request->password, $member->password)) {
            throw new ApiException(__('error_message.1018'));
        }

        $this->memberFieldSettingService->passwordPolicy($userData->agent)->assert($request->new_password);

        if (Hash::check($request->new_password, $member->payment_password)) {
            throw new ApiException(__('error_message.9019'));
        }

        $member->update([
            'password' => Hash::make($request->new_password),
            // 風控比對用(會員追蹤查同密碼帳號),不參與登入驗證
            'password_md5' => md5($request->new_password),
            'admin' => 0,
        ]);

        return ApiResponse::success();
    }

    /**
     * 修改支付密碼
     *
     * OLD: /api/changePaymentPassword
     *
     * @throws ApiException
     */
    public function changePaymentPassword(MemberRequests\ChangePaymentPasswordRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $input = $request->validated();

        // 舊支付密碼正確, 首次設定不需要舊密碼
        if ($member->payment_password != '' &&
            !Hash::check($input['current_payment_password'] ?? '', $member->payment_password)
        ) {
            throw new ApiException(__('error_message.1018'));
        }

        // 新舊支付密碼不得相同
        // if (!Hash::check($input['payment_password'], $member->payment_password)) {
        //     throw new ApiException(__('error_message.1094'));
        // }

        // 支付密碼不得與會員密碼相同
        if (Hash::check($input['new_payment_password'], $member->password)) {
            throw new ApiException(__('error_message.1094'));
        }

        $member->update([
            'payment_password' => Hash::make($input['new_payment_password']),
            'admin' => 0,
        ]);

        // 修改紀錄
        MemberBindingLog::create([
            'type' => MemberBindingLogTypeEnum::UPDATE_PAYMENT_PASSWORD->value, // 修改支付密碼
            'member_id' => $member->id,
            'value' => $input['new_payment_password'],
            'ip' => Common::getIp(),
            'validate_type' => 0,
        ]);

        return ApiResponse::success();
    }

    /**
     * 設定真實姓名
     *
     * 只能設定一次
     *
     * @throws ApiException
     */
    public function setRealName(MemberRequests\SetRealNameRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        // 檢查是否已經設定過真實姓名
        if ($member->first_name != '') {
            throw new ApiException(__('error_message.1097'));
        }

        $input = $request->validated();
        $realName = trim($input['real_name']);

        // 真實姓名重複限制:讀 type=6「註冊/綁定限制」的 name 上限(與 v2 註冊、TG 補資料、實名認證同一套來源);
        // 未勾選則不限制。此處原本完全沒檢查,可繞過實名認證流程直接重複使用同一姓名。
        $exceeded = $this->memberRegisterLimitService->check($member->agent, [
            MemberRegisterLimitFieldEnum::NAME->value => $realName,
        ]);
        if ($exceeded !== null) {
            throw new ApiException(__('error_message.2065'), 2065);
        }

        $member->update([
            'first_name' => $realName,
        ]);

        // TODO 棄用 SP
        if (config('app.env') !== 'testing') {
            DB::statement('CALL SP_event_register(?, ?, ?)', [$member->id, $member->parent, 'account']);
            // $memberId = $member->id;
            // $agentId = $member->agent->id;
            // Common::callSP('SP_event_register', $memberId, $agentId, 'account');
        }

        return ApiResponse::success();
    }

    /**
     * 設定生日
     *
     * 只能設定一次
     *
     * @throws ApiException
     */
    public function setBirthday(MemberRequests\SetBirthdayRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        // 檢查是否已經設定過生日
        if ($member->birthday != '') {
            throw new ApiException(__('error_message.1100'));
        }

        $input = $request->validated();

        $member->update([
            'birthday' => trim($input['birthday']),
        ]);

        MemberBindingLog::create([
            'type' => MemberBindingLogTypeEnum::FILL_BIRTHDAY->value,
            'member_id' => $member->id,
            'value' => $input['birthday'],
            'ip' => Common::getIp(),
            'validate_type' => 0,
            'status' => 1,
        ]);

        return ApiResponse::success();
    }

    /**
     * 取得有綁定帳號的遊戲商列表
     *
     * OLD: /api/member_all_vendor
     */
    public function vendors(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;

        // 一次性取得所有遊戲商
        $allVendors = Vendor::where('status', 1)
            ->whereHas('vendorInfos', function ($query) {
                $query->where('status', 1);
            })
            ->with([
                'vendorCustomizedItems' => function ($query) use ($agent) {
                    $query->where('agent_group_id', $agent->vendor_customize_group_id)
                        ->where('item_type', 1)
                        ->where('status', 1)
                        ->select('id', 'vendor_id', 'name');
                },
            ])
            ->select(['id', 'tname', 'name'])
            ->get();

        // 篩選出有綁定帳號的遊戲商
        $data = [];
        foreach ($allVendors as $vendor) {
            $vendorAccountCacheKey = CacheKeys::memberVendorAccount($vendor->tname, $member->id);

            $isMemberExistsInVendor = Cache::remember(
                $vendorAccountCacheKey,
                CacheTtl::HALF_HOUR->value,
                fn () => $this->memberService->isMemberExistsInVendor($member->id, $vendor->tname)
            );

            if ($isMemberExistsInVendor) {
                $data[] = [
                    'id' => $vendor->id,
                    'name' => $vendor->vendorCustomizedItems->first()->name ?? $vendor->name,
                ];
            }
        }

        return ApiResponse::success(MemberResources\VendorsResource::collection($data));
    }

    /**
     * 會員輸贏報表
     *
     * OLD: /api/member_winlose
     */
    public function winloses(MemberRequests\WinlosesRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $startTime = Carbon::parseFrontend($request->search_day)
            ->startOfDay()
            ->toSystemTime();
        $endTime = $startTime->copy()->addDay();

        $limit = Carbon::now()
            ->toFrontendTime()
            ->startOfDay()
            ->toSystemTime()
            ->subDays(2); // 只允許查詢3天內 (當天/昨天/前天)

        if ($startTime < $limit) {
            throw new ApiException(__('error_message.2044', ['values' => 3]));
        }

        $cacheKey = CacheKeys::memberWinloses($member->id, $request->vendor_id, $userData->languageId, $request->search_day);
        $data = Cache::remember($cacheKey, CacheTtl::HALF_HOUR->value, function () use ($request, $startTime, $endTime, $userData): array {
            $list = SummaryBetRecord::query()
                ->where('member_id', $userData->member->id)
                ->where('vendor_id', $request->vendor_id)
                ->where('summary_time', '>=', $startTime)
                ->where('summary_time', '<', $endTime)
                ->with([
                    'game:id,name',
                    'game.gameNames' => function ($query) use ($userData) {
                        $query->where('language_id', $userData->languageId)
                            ->select('id', 'game_id', 'name');
                    },
                ])
                ->select(
                    'game_id',
                    DB::raw('SUM(bet) as valid_bet'),
                    DB::raw('SUM(win_lose) as win_lose'),
                    DB::raw('SUM(record_count) as record_count'),
                )
                ->groupBy('game_id')
                ->get();

            $sum = [
                'sum_win_lose' => $list->sum('win_lose'),
                'sum_record_count' => $list->sum('record_count'),
            ];

            return [
                'list' => $list,
                'sum' => $sum,
            ];
        });

        $list = $data['list'];
        $sum = $data['sum'];

        $list = $list->paginate($request->input('per_page', 15));

        return ApiResponse::success([
            'items' => MemberResources\WinlosesResource::collection($list),
            'pagination' => OnlyPaginateResource::make($list),
            'sum' => [
                'sum_win_lose' => $sum['sum_win_lose'],
                'sum_record_count' => $sum['sum_record_count'],
            ],
        ]);
    }

    /**
     * 會員所有遊戲商的有效投注額加總
     *
     * OLD: /api/vendor_total_valid_bets
     */
    public function totalValidBets(MemberRequests\TotalValidBetsRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;

        $searchDay = Carbon::parseFrontend($request->search_day)
            ->startOfDay()
            ->toSystemTime();
        $startTime = $searchDay->copy();
        $endTime = $startTime->copy()->addDay();

        $limit = Carbon::now()
            ->toFrontendTime()
            ->startOfDay()
            ->toSystemTime()
            ->subDays(2); // 只允許查詢3天內 (當天/昨天/前天)

        if ($startTime < $limit) {
            throw new ApiException(__('error_message.2044', ['values' => 3]));
        }

        $cacheKey = CacheKeys::memberTotalValidBets($member->id, $request->search_day);
        // summary bet record update each hour 30 minutes.
        $data = Cache::remember($cacheKey, CacheTtl::HALF_HOUR->value, function () use ($member, $agent, $startTime, $endTime) {
            return SummaryBetRecord::query()
                ->where('member_id', $member->id)
                ->where('summary_time', '>=', $startTime)
                ->where('summary_time', '<', $endTime)
                ->has('vendor')
                ->with([
                    'vendor:id,name,image',
                    'vendorCustomizedItems' => function ($query) use ($agent) {
                        $query->where('agent_group_id', $agent->customized_setting_group_id)
                            ->where('item_type', 1)
                            ->where('status', 1)
                            ->select('id', 'vendor_id', 'name', 'image');
                    },
                ])
                ->select(
                    'vendor_id',
                    DB::raw('SUM(bet) as total_valid_bet'),
                    DB::raw('MAX(summary_time) as summary_time')
                )
                ->groupBy('vendor_id')
                ->get();
        });

        $data = $data->paginate($request->input('per_page', 15));

        return ApiResponse::successWithPagination(
            data: MemberResources\TotalValidBetsResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 取得會員追蹤事件
     */
    public function trackingEvents(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $list = MemberTrackingEvent::query()
            ->where('member_id', $member->id)
            ->whereNull('sent_at')
            ->select('id', 'type', 'data')
            ->get();

        if ($list->isNotEmpty()) {
            MemberTrackingEvent::whereIn('id', $list->pluck('id'))
                ->update(['sent_at' => now()]);
        }

        return ApiResponse::success([
            'items' => MemberResources\MemberTrackingEvent::collection($list),
        ]);
    }
}
