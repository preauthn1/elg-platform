<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberStatusLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MemberAccessTokenService
{
    /**
     * 發行新 token：作廢該會員所有有效 token（含快取）後建立新紀錄。
     */
    public function issue(int $memberId): string
    {
        return DB::transaction(function () use ($memberId) {
            $this->revoke($memberId);

            $token = [REDACTED];

            MemberAccessToken:[REDACTED]
                'member_id' => $memberId,
                'token' => $token,
                'expire_date' => $this->calculateExpireDate(),
                'admin' => $memberId,
            ]);

            return $token;
        });
    }

    /**
     * 作廢會員所有有效 access token。
     */
    public function revoke(int $memberId): void
    {
        DB::transaction(function () use ($memberId) {
            // 會員級互斥點：members 主鍵為唯一索引，FOR UPDATE 僅取 record lock，
            // 序列化同會員的 issue/revoke 併發。token 的 member_id 是非唯一索引，
            // 直接 FOR UPDATE 會取 next-key lock 波及相鄰會員、空集合時更會因
            // gap lock 相容導致併發 INSERT deadlock，故互斥收口於此、token 查詢不加鎖。
            Member::whereKey($memberId)->lockForUpdate()->first(['id']);

            $now = $this->now();

            $activeTokens = MemberAccessToken:[REDACTED]
                ->where('member_id', $memberId)
                ->where('expire_date', '>', $now)
                ->get(['id', 'token']);

            if ($activeTokens->isEmpty()) {
                return;
            }

            MemberAccessToken:[REDACTED]'id', $activeTokens->pluck('id'))
                ->update(['expire_date' => $now]);

            foreach ($activeTokens as $accessToken) {
                $this->forgetTokenCache($accessToken->token);
            }
        });
    }

    /**
     * 鎖定會員並作廢 access token，三步在同一個交易：作廢失敗時鎖定也一起回滾。
     */
    public function lockMemberAndRevoke(Member $member, MemberStatusLogTypeEnum $type): void
    {
        DB::transaction(function () use ($member, $type) {
            $this->markMemberLocked($member, $type);
            $this->revoke($member->id);
        });
    }

    /**
     * 鎖定會員並寫會員狀態紀錄，兩步在同一個交易；不作廢 token。
     *
     * 先鎖 members 主鍵列（與 revoke() 同一個鎖點）再重讀狀態：已經是鎖定（例如同時進來的另一個請求剛鎖完）
     * 就不重寫狀態紀錄。鎖定前狀態也取鎖內重讀的值，不取呼叫端手上可能已過期的 $member。
     *
     * 給「作廢 token 失敗時帳號仍要維持鎖定」的呼叫端用：鎖定提交後，在交易外呼叫 revokeAfterLock()。
     * 已鎖定的會員每個請求都會被 AuthMember 以會員狀態擋下，token 作廢是第二道防線。
     */
    public function lockMember(Member $member, MemberStatusLogTypeEnum $type): void
    {
        DB::transaction(function () use ($member, $type) {
            $lockedMember = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($lockedMember->status !== MemberStatusEnum::LOCKED->value) {
                $this->markMemberLocked($lockedMember, $type);
            }
        });
    }

    /**
     * 改會員狀態為鎖定並寫狀態紀錄，本身不開交易，由呼叫端包交易。
     *
     * 不自己開交易是為了讓 lockMemberAndRevoke() 的寫入留在它自己那一層交易：多包一層會變成
     * savepoint，遇到死結或鎖等待逾時時 Laravel 改丟 DeadlockException，
     * 接不到 bootstrap/app.php 對 QueryException 的統一回應。
     */
    private function markMemberLocked(Member $member, MemberStatusLogTypeEnum $type): void
    {
        $beforeStatus = $member->status;
        $member->status = MemberStatusEnum::LOCKED->value;
        $member->save();

        MemberStatusLog::create([
            'member_id' => $member->id,
            'agent_id' => $member->parent,
            'type' => $type->value,
            'before_status' => $beforeStatus,
            'after_status' => MemberStatusEnum::LOCKED->value,
        ]);
    }

    /**
     * 鎖定提交後作廢 access token；作廢失敗只記 log 並告警，不往外拋，鎖定維持有效。
     *
     * 必須在鎖定的交易提交之後呼叫，不可放在外層交易內：例外在這裡被吃掉，外層交易仍會照常提交，
     * 連資料庫死結這類已讓外層交易失效的錯誤也會一併被吞掉。
     */
    public function revokeAfterLock(Member $member, MemberStatusLogTypeEnum $type): void
    {
        try {
            $this->revoke($member->id);
        } catch (Throwable $e) {
            // 先寫 log 再告警：告警管線本身故障時仍留得下紀錄
            Log::error('MemberAccessToken revoke failed after member lock', [
                'member_id' => $member->id,
                'type' => $type->name,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            report($e);
        }
    }

    /**
     * 清除 token 快取（雙保險）：
     * transaction 內先清一次（fail-safe：Redis 失敗即隨交易 rollback，DB 與快取同進退；
     * 若最終 rollback，快取由下次請求自 DB 自癒回填），commit 後再清一次
     * （覆蓋 commit 前被併發請求回填的舊值；單筆失敗記 log、不中斷其餘 token）。
     */
    private function forgetTokenCache(string $token): void
    {
        $cacheKey = CacheKeys::memberAccessToken($token);

        Cache::forget($cacheKey);

        DB::afterCommit(function () use ($token, $cacheKey) {
            try {
                Cache::forget($cacheKey);
            } catch (Throwable $e) {
                Log::error('MemberAccessToken cache forget failed after commit', [
                    'token_suffix' => substr($token, -4),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function calculateExpireDate(): string
    {
        return date('Y-m-d H:i:s', strtotime('+ ' . config('custom.frontend.token_expired') . 'minute'));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
