<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Member;

use App\Data\MemberWalletData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberDetailResource extends JsonResource
{
    private MemberWalletData $memberWallets;

    private int $flowLimitApplicationStatus;

    /** 會員可用積分（MemberScoreService::balance()，4 位小數字串） */
    private string $score;

    public function __construct($resource, MemberWalletData $wallets, int $flowLimitApplicationStatus, string $score = '0.0000')
    {
        parent::__construct($resource);
        $this->memberWallets = $wallets;
        $this->flowLimitApplicationStatus = $flowLimitApplicationStatus;
        $this->score = $score;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent' => $this->parent,
            'username' => $this->username,
            'balance' => $this->balance,
            // 'balance' => $this->memberWallets->wallet->balance,
            'rolling_chip' => $this->memberWallets->rollingChipWallet?->balance ?? 0.00,
            // 積分（target/tickets#4233 錢包 type 4；紅包 target/tickets#4234 用它當門票）：前端首頁／錢包卡顯示用，格式同 rolling_chip
            'score' => $this->score,
            'image' => $this->image,
            'email' => $this->email,
            'nickname' => $this->nickname,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birthday' => $this->birthday,
            'mobile' => $this->mobile,
            'timezone_id' => $this->timezone_id,
            'country_id' => $this->country_id,
            'zip_code' => $this->zip_code,
            'district' => $this->district,
            'city' => $this->city,
            'address' => $this->address,
            'status' => $this->status,
            'is_validate' => $this->is_validate,
            'mobile_validate' => $this->mobile_validate,
            'email_validate' => $this->email_validate,
            'realname_validate' => $this->realname_validate,
            'has_2fa' => $this->has_2fa,
            'event_mode' => $this->event_mode,
            'flow_limit_application' => $this->flowLimitApplicationStatus,
            'last_login' => $this->last_login,
            'payment_password' => ($this->payment_password != '') ? 1 : 0,
            'agent_username' => $this->agent->username ?? '',
            'is_tg_user' => $this->isTgUser,
            'ip_change_mobile_verify' => $this->ip_change_mobile_verify,
            'ip_change_email_verify' => $this->ip_change_email_verify,
            'setting' => $this->when($this->memberSetting, function () {
                return new MemberSettingResource($this->memberSetting);
            }),
        ];
    }
}
