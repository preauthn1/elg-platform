<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberStatusLogTypeEnum;
use App\Models\Member;
use App\Models\MemberStatistic;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 會員驗證失敗次數的累計與達上限鎖帳：登入密碼、Google 驗證、驗證碼、支付密碼四種共用。
 *
 * 失敗次數記在 member_statistics，依鎖帳原因（MemberStatusLogTypeEnum）對應欄位。
 * 計次一律在交易裡先鎖 members 主鍵列當會員級互斥點（與 MemberAccessTokenService::revoke() 同一個鎖點），
 * 同一會員的併發失敗請求才會依序計次、不會各自讀到同一個舊值而少算：
 * - 會員鎖必須是交易內第一條查詢：交易內第一個一般讀決定讀取快照，排在它之後的一般讀看不到別的請求剛提交的次數。
 * - 統計列先以 firstOrCreate 建好，再以主鍵鎖定讀重取後加一寫回。不直接用 member_id 對 member_statistics 下 FOR UPDATE：
 *   統計列還不存在時會拿到 gap lock（範圍鎖），兩個會員同時第一次失敗、各自新增統計列時可能互鎖。
 *
 * 兩個方法都不可在外層交易內呼叫：increaseAndLock() 在提交後作廢 token（見 MemberAccessTokenService::revokeAfterLock()），
 * increase() 的呼叫端接著要在自己的交易裡鎖帳。
 */
class MemberFailCountService
{
    public function __construct(
        private MemberAccessTokenService $memberAccessTokenService,
    ) {}

    /**
     * 累計一次失敗，達上限時鎖帳；作廢 token 失敗時帳號仍維持鎖定。
     *
     * 計次與鎖定在同一個交易：寫入鎖定失敗時失敗次數一併回滾，下一次失敗會重新計次並再嘗試鎖帳。
     * 會員已經是鎖定（例如同時進來的請求剛鎖完）時不重寫狀態紀錄，但仍作廢 token。
     * 作廢 token 在交易提交後才做，失敗只記 log 並告警；交易內也不做寫快取這類外部副作用。
     *
     * @param  Closure(int): bool  $shouldLock  依累計後的失敗次數判斷要不要鎖帳
     * @return int 累計後的失敗次數
     */
    public function increaseAndLock(Member $member, MemberStatusLogTypeEnum $type, Closure $shouldLock): int
    {
        $failCount = DB::transaction(function () use ($member, $type, $shouldLock): int {
            $lockedMember = $this->lockMemberRow($member);
            $failCount = $this->incrementFailCount($lockedMember, $type);

            if ($shouldLock($failCount)) {
                $this->memberAccessTokenService->lockMember($lockedMember, $type);
            }

            return $failCount;
        });

        if ($shouldLock($failCount)) {
            $this->memberAccessTokenService->revokeAfterLock($member, $type);
        }

        return $failCount;
    }

    /**
     * 只累計一次失敗，不鎖帳；給鎖帳方式另有規定、要自己接著鎖帳的呼叫端。
     *
     * @return int 累計後的失敗次數
     */
    public function increase(Member $member, MemberStatusLogTypeEnum $type): int
    {
        return DB::transaction(fn (): int => $this->incrementFailCount($this->lockMemberRow($member), $type));
    }

    private function lockMemberRow(Member $member): Member
    {
        return Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
    }

    private function incrementFailCount(Member $lockedMember, MemberStatusLogTypeEnum $type): int
    {
        $column = $this->failCountColumn($type);

        $statisticsId = $lockedMember->statistics()->firstOrCreate([])->id;
        $statistics = MemberStatistic::whereKey($statisticsId)->lockForUpdate()->firstOrFail();
        $statistics->{$column} += 1;

        // 登入密碼另記累計總次數，登入成功時不歸零
        if ($type === MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT) {
            $statistics->total_login_fail_count += 1;
        }

        $statistics->save();

        return (int) $statistics->{$column};
    }

    private function failCountColumn(MemberStatusLogTypeEnum $type): string
    {
        return match ($type) {
            MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT => 'login_fail_count',
            MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT => 'validation_code_fail_count',
            MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT => 'payment_pwd_fail_count',
            MemberStatusLogTypeEnum::GOOGLE_AUTH_LIMIT => 'google_auth_fail_count',
            MemberStatusLogTypeEnum::BACKEND_CHANGE => throw new InvalidArgumentException('後台變更沒有對應的失敗次數'),
        };
    }
}
