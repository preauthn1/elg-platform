<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\MemberWallet;
use App\Services\Agent\Concerns\ChecksMemberScope;
use Illuminate\Support\Carbon;

/**
 * 代理後台讀單一會員資料（target/tickets#4438），由 API_Agent `member/view` 移植，舊接口保留給舊後台。
 *
 * 新後台開洗分彈窗、補點彈窗、會員編輯頁共用；欄位照舊接口回齊，三頁各取所需。
 * 必須：不回會員支付密碼雜湊（舊接口原樣回 payment_password，沒有頁面在用，屬外洩）。
 * 範疇＝整棵下線（ChecksMemberScope，與會員清單一致）。
 */
class MemberProfileService
{
    use ChecksMemberScope;

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException member not found／member out of scope
     */
    public function view(Agent $operator, int $memberId): array
    {
        $member = $this->findMemberInScope($operator, $memberId);
        $agent = Agent::query()->find($member->parent, ['id', 'username', 'customized_setting_group_id']);

        $wallets = MemberWallet::query()
            ->where('member_id', $member->id)
            ->whereIn('wallet_type_id', [WalletEnum::CODE_ROLLING_CHIP->value, WalletEnum::CODE_SCORE->value])
            ->pluck('balance', 'wallet_type_id');

        // 舊接口只看代理群組的自訂值、沒設回 0（不吃 AgentSettingDefine 預設值），照抄
        $paymentPasswordVisible = $agent === null ? null : AgentCustomizedSetting::query()
            ->where('agent_group_id', $agent->customized_setting_group_id)
            ->where('setting_key', AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE)
            ->value('value');

        return [
            'agent_username' => $agent?->username,
            'id' => $member->id,
            'parent' => $member->parent,
            'username' => $member->username,
            'balance' => Common::numAdd('0', (string) $member->balance, 4),
            'rolling_chip' => Common::numAdd('0', (string) ($wallets[WalletEnum::CODE_ROLLING_CHIP->value] ?? '0'), 4),
            'score' => Common::numAdd('0', (string) ($wallets[WalletEnum::CODE_SCORE->value] ?? '0'), 4),
            'device_type' => $member->device_type,
            'email' => $member->email,
            'nickname' => $member->nickname,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'birthday' => $member->birthday,
            'mobile' => $member->mobile,
            'timezone_id' => $member->timezone_id,
            'country_id' => $member->country_id,
            'zip_code' => $member->zip_code,
            'district' => $member->district,
            'city' => $member->city,
            'address' => $member->address,
            'register_country_id' => $member->register_country_id,
            'register_ip' => $member->register_ip,
            'register_domain' => $member->register_domain,
            'status' => $member->status,
            'is_validate' => $member->is_validate,
            'mobile_validate' => $member->mobile_validate,
            'email_validate' => $member->email_validate,
            'realname_validate' => $member->realname_validate,
            'ip_change_mobile_verify' => $member->ip_change_mobile_verify,
            'ip_change_email_verify' => $member->ip_change_email_verify,
            'member_group_id' => $member->member_group_id,
            // 舊接口寫死 +8；apis 走後台時區（八個部署皆 Asia/Taipei，結果相同）
            'last_login' => $member->last_login ? Carbon::parse($member->last_login)->toBackendTime()->toDateTimeString() : null,
            'payment_password_visible' => (int) ($paymentPasswordVisible ?? 0),
            'type' => $member->type,
        ];
    }
}
