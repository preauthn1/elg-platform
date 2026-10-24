<?php

declare(strict_types=1);

namespace App\Models;

use App\CacheKeys\CacheKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * @property int $id 系統編號
 * @property int $agent_id 代理系統編號(User)
 * @property string $token Token
 * @property string $expire_date 過期日期
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 *
 * @method static Builder<static>|AgentAccessToken newModelQuery()
 * @method static Builder<static>|AgentAccessToken newQuery()
 * @method static Builder<static>|AgentAccessToken query()
 *
 * @mixin \Eloquent
 */
class AgentAccessToken extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * API_Agent Authenticate middleware 快取 token 查詢結果的分鐘數（對方 AgentAccessToken:[REDACTED]
     * 「剛過期、快取還在」的 token 也要清，所以掃描範圍往前多抓這麼多分鐘。
     */
    private const int API_AGENT_CACHE_MINUTES = 5;

    /**
     * 登出該代理的所有登入（target/tickets#4518）：未過期的 token 到期時間改成現在，並刪掉 API_Agent 在 redis 的 token 快取。
     *
     * apis 的 AuthAgent 每請求直查 DB，改了到期時間下一個請求就被擋；API_Agent 的 Authenticate 會把「token 有效」
     * 快取 5 分鐘（redis store，key 見 CacheKeys::apiAgentAccessToken()），不刪快取的話被踢的人在舊後台還能再用 5 分鐘。
     * 必須在資料庫交易提交後才呼叫：交易內先刪快取，提交前進來的請求會把仍有效的 token 重新快取。
     * 用在：變更 Google 2FA／登入 IP 白名單後踢人，以及登入時作廢同帳號的舊 token。
     */
    public static function expireAll(int $agentId): void
    {
        $now = now();
        $cacheSince = $now->copy()->subMinutes(self::API_AGENT_CACHE_MINUTES + 1)->toDateTimeString();

        $tokens = static::onWriteConnection()
            ->where('agent_id', $agentId)
            ->where('expire_date', '>', $cacheSince)
            ->pluck('token');

        static::query()
            ->where('agent_id', $agentId)
            ->where('expire_date', '>', $now->toDateTimeString())
            ->update(['expire_date' => $now->toDateTimeString()]);

        if ($tokens->isEmpty()) {
            return;
        }

        // DB 已作廢、apis 每個請求直查 DB；清 API_Agent 快取失敗只是舊後台最多再放行 5 分鐘，
        // 記 log、不中斷，不能讓 redis 異常把已完成的變更或登入變成錯誤（同 MemberAccessTokenService::forgetTokenCache）
        try {
            Redis::connection()->del(
                ...$tokens->map(static fn (string $token): string => CacheKeys::apiAgentAccessToken($token))->all(),
            );
        } catch (Throwable $e) {
            Log::error('AgentAccessToken API_Agent cache forget failed after expire', [
                'agent_id' => $agentId,
                'token_count' => $tokens->count(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 關聯的 agent 帳號
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id')
            ->where('status', 1);
    }
}
