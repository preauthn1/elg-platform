<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Models\LiveChatAccount;
use App\Models\Member;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 會員 LiveChat 客服 token 代發；沿用舊版 API_Frontend `LiveChatService` 邏輯，
 * 串接 LiveChat 官方 OAuth API（https://example.invalid/customer/token）換取／更新 access token。
 */
class LiveChatService
{
    private const TOKEN_URL = 'https://example.invalid/customer/token';

    public function __construct(
        private AgentService $agentService,
    ) {}

    public function getLiveChatAccount(Member $member): ?LiveChatAccount
    {
        return LiveChatAccount::where('member_id', $member->id)->first();
    }

    public function hasToken(Member $member): bool
    {
        return LiveChatAccount::where('member_id', $member->id)->exists();
    }

    public function invalidate(Member $member): bool
    {
        return LiveChatAccount::where('member_id', $member->id)->delete() > 0;
    }

    /**
     * 呼叫 LiveChat 官方 OAuth API 換取（或延續 cookie 續期）access token，並更新 live_chat_accounts。
     */
    public function getFreshToken(Member $member): ?LiveChatAccount
    {
        $agent = $member->agent;
        $licenseId = (int) $this->agentService->getSpecificAgentSetting($agent, AgentCustomizedSettingKeyEnum::CUSTOMIZED_LIVE_CHAT_LICENSE_ID);
        $clientId = (string) $this->agentService->getSpecificAgentSetting($agent, AgentCustomizedSettingKeyEnum::CUSTOMIZED_LIVE_CHAT_CLIENT_ID);

        $account = $this->getLiveChatAccount($member);
        $cookies = $account ? ['__lc_cid' => $account->lc_cid, '__lc_cst' => $account->lc_cst] : [];

        try {
            $response = Http::logAction('LiveChat_GetFreshToken', maskKeys: ['access_token', '__lc_cid', '__lc_cst'])
                ->withHeaders(['origin' => config('app.url')])
                ->withCookies($cookies, 'example.invalid')
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'cookie',
                    'response_type' => 'token',
                    'client_id' => $clientId,
                    'license_id' => $licenseId,
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::error('LiveChatService::getFreshToken failed', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $json = $response->json();
        $newCookies = $this->parseSetCookieHeaders($response->headers()['Set-Cookie'] ?? []);

        $data = [
            'creation_date' => now(),
            'license_id' => $licenseId,
            'access_token' => $json['access_token'] ?? '',
            'entity_id' => $json['entity_id'] ?? '',
            'expires_in' => $json['expires_in'] ?? 0,
            'token_type' => $json['token_type'] ?? '',
            'lc_cid' => $newCookies['__lc_cid'] ?? ($account->lc_cid ?? ''),
            'lc_cst' => $newCookies['__lc_cst'] ?? ($account->lc_cst ?? ''),
        ];

        if ($account) {
            $account->update($data);
        } else {
            $account = LiveChatAccount::create($data + ['member_id' => $member->id]);
        }

        return $account;
    }

    /**
     * @param  array<int, string>  $setCookieHeaders
     * @return array<string, string>
     */
    private function parseSetCookieHeaders(array $setCookieHeaders): array
    {
        $cookies = [];

        foreach ($setCookieHeaders as $header) {
            $pair = explode(';', $header, 2)[0];
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $cookies[trim($name)] = trim($value);
        }

        return $cookies;
    }
}
