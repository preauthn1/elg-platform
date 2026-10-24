<?php

declare(strict_types=1);

namespace App\Services\EventV2\Traits;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * 收集會員無法參加活動的所有原因（i18n 訊息陣列），供 Livechat Bot Promotions API 使用。
 *
 * 自 {@see \App\Services\EventV2\AbstractEventV2} 抽出。呼叫 {@see EnforcesParticipationBlocks}
 * 提供的 detectXBlockCode()——兩個 trait 都併入同一個 AbstractEventV2 子類別，$this 互通。
 *
 * 注意：本 trait 刻意不 `use EnforcesParticipationBlocks;`（即使 PHP 本身允許同一 trait
 * 經多路徑組合），因為 PHPMD/PDepend 的 trait 解析與 PHP 引擎不同，會誤判為 collision
 * 而讓 `composer phpmd` 失敗（CI 會擋）。detectXBlockCode() 呼叫純靠 AbstractEventV2
 * 同時 use 兩個 trait 後的組合結果，IDE 可能無法直接導航到定義，屬已知限制。
 */
trait CollectsQualificationIssues
{
    /**
     * 收集會員無法參加此活動的所有原因（i18n 訊息陣列）。
     *
     * 與 {@see \App\Services\EventV2\AbstractEventV2::validate()} 的 fail-fast 不同，本方法不拋例外，
     * 會跑過所有檢查，並回傳全部失敗訊息。空陣列代表完全通過、可參加。
     *
     * 主要用於 Livechat Bot Promotions API：將「驗證通過」與「驗證失敗」的活動分流，
     * 並把失敗原因回傳給機器人引導會員。
     *
     * 實作刻意不呼叫 validate() / checkIpBlock() / checkPhoneBlock() / checkAccountBlock()，
     * 以避免與這些方法的 API 演進耦合（develop 將回傳型別改為 EventV2ValidateResult）。
     * 本方法直接內嵌等價的 SQL 檢查並產出 i18n 訊息。
     *
     * @return array<int, string>
     */
    public function getQualificationIssues(EventV2Detail $eventDetail, Member $member): array
    {
        $eventValidIssue = $this->qualificationEventValidIssue($eventDetail);
        if ($eventValidIssue !== null) {
            // 活動本身已失效，後續檢查無意義
            return [$eventValidIssue];
        }

        $participations = $this->loadQualificationParticipations($member);

        $candidates = [
            $this->qualificationEventModeIssue($member),
            $this->qualificationOnlyOneEventIssue($participations, $eventDetail->id),
            $this->qualificationHasJoinIssue($participations, $eventDetail->id),
            $this->qualificationPhoneNotValidateIssue($eventDetail, $member),
            $this->qualificationIpBlockIssue($eventDetail, $member),
            $this->qualificationPhoneBlockIssue($eventDetail, $member),
            $this->qualificationAccountBlockIssue($eventDetail, $member),
            $this->qualificationGetValidateIssue($eventDetail, $member, $participations),
        ];

        $filtered = array_filter($candidates, fn (?string $msg) => $msg !== null);

        return array_values(array_unique($filtered));
    }

    private function qualificationEventValidIssue(EventV2Detail $eventDetail): ?string
    {
        $time = $this->systemTime->toDateTimeString();
        $eventValid = $eventDetail->status === 1
            && $eventDetail->start_time !== null
            && $eventDetail->end_time !== null
            && $eventDetail->start_time <= $time
            && $eventDetail->end_time >= $time;

        return $eventValid ? null : EventV2ValidateCodeEnum::EVENT_NOT_EXIST->translate();
    }

    private function loadQualificationParticipations(Member $member): Collection
    {
        $timeOffset = $this->frontendTime->offsetHours;

        // 欄位與 AbstractEventV2::validate() 的 participations 對齊，紅包的 listValidate 要讀 reward／created_at／year
        return EventV2UserJoin::select('event_v2.id AS event_v2_id', 'event_v2_user_joins.event_v2_detail_id', 'event_v2_user_joins.status', 'event_v2_user_joins.reward', 'event_v2_user_joins.created_at')
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS date', [$timeOffset, '%Y-%m-%d'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS week', [$timeOffset, '%x-%v'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS month', [$timeOffset, '%Y-%m'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS year', [$timeOffset, '%Y'])
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->where('event_v2_user_joins.member_id', $member->id)
            ->get();
    }

    private function qualificationEventModeIssue(Member $member): ?string
    {
        return $member->event_mode > 0 ? EventV2ValidateCodeEnum::EVENT_MODE->translate() : null;
    }

    private function qualificationOnlyOneEventIssue(Collection $participations, int $eventDetailId): ?string
    {
        $hit = $participations
            ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
            ->where('event_v2_detail_id', '!=', $eventDetailId)
            ->isNotEmpty();

        return $hit ? EventV2ValidateCodeEnum::ONLY_ONE_EVENT->translate() : null;
    }

    private function qualificationHasJoinIssue(Collection $participations, int $eventDetailId): ?string
    {
        $hit = $participations
            ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
            ->where('event_v2_detail_id', $eventDetailId)
            ->isNotEmpty();

        return $hit ? EventV2ValidateCodeEnum::HAS_JOIN->translate() : null;
    }

    private function qualificationPhoneNotValidateIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        $unverified = $eventDetail->phone_block !== null && $member->mobile_validate === 0;

        return $unverified ? EventV2ValidateCodeEnum::PHONE_NOT_VALIDATE->translate() : null;
    }

    private function qualificationIpBlockIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        if ($eventDetail->ip_block === null) {
            return null;
        }

        try {
            return $this->resolveIpBlockIssue($eventDetail, $member);
        } catch (Throwable) {
            // IP 檢查相依外部資料時忽略，避免誤判
            return null;
        }
    }

    private function qualificationPhoneBlockIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        if ($eventDetail->phone_block === null || $member->mobile_validate === 0) {
            return null;
        }

        try {
            return $this->resolvePhoneBlockIssue($eventDetail, $member);
        } catch (Throwable) {
            // 同 ip 邏輯
            return null;
        }
    }

    private function qualificationAccountBlockIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        if ($eventDetail->account_block === null) {
            return null;
        }

        try {
            return $this->resolveAccountBlockIssue($eventDetail, $member);
        } catch (Throwable) {
            // 同 ip 邏輯
            return null;
        }
    }

    private function qualificationGetValidateIssue(EventV2Detail $eventDetail, Member $member, Collection $participations): ?string
    {
        $result = $this->getValidate(
            eventDetail: $eventDetail,
            member: $member,
            participations: $participations,
        );

        if (!$result->isFailed()) {
            return null;
        }

        $message = (string) $result->translate();

        return $message !== '' ? $message : null;
    }

    private function resolveIpBlockIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        $code = $this->detectIpBlockCode($eventDetail, $member);

        return $code?->translate();
    }

    private function resolvePhoneBlockIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        return $this->detectPhoneBlockCode($eventDetail, $member)?->translate();
    }

    private function resolveAccountBlockIssue(EventV2Detail $eventDetail, Member $member): ?string
    {
        return $this->detectAccountBlockCode($eventDetail, $member)?->translate();
    }
}
