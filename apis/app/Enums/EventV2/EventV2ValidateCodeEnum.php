<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

use App\Helpers\Common;
use App\Models\EventV2RejectJoin;
use App\Models\EventV2RejectRecord;
use App\Models\Member;

enum EventV2ValidateCodeEnum: string
{
    // AbstractEventV2
    /** 驗證成功 */
    case SUCCESS = '0000';

    /** 活動不存在 */
    case EVENT_NOT_EXIST = '0001';

    /** 鎖分模式中不能參加活動 */
    case EVENT_MODE = '0002';

    /** 一次只能參加一個活動 */
    case ONLY_ONE_EVENT = '0003';

    /** 您已經參加過這個活動，無法再次參加 */
    case HAS_JOIN = '0004';

    /** 您今天已經參加過這個活動，無法再次參加 */
    case DAILY = '0005';

    /** 您這周已經參加過這個活動，無法再次參加 */
    case WEEKLY = '0006';

    /** 您這個月已經參加過這個活動，無法再次參加 */
    case MONTHLY = '0007';

    /** 請先綁定手機驗證 */
    case PHONE_NOT_VALIDATE = '0008';

    /** 儲值尚未達到標準 */
    case DEPOSIT_NOT_ENOUGH = '0009';

    /** 主錢包餘額不足 */
    case BALANCE_NOT_ENOUGH = '0010';

    /** 此IP已經參加過活動（註冊玩家重複） */
    case IP_HAS_JOIN_WITH_REGISTER = '0011';

    /** 此IP已經參加過活動（活動V1、V2重複） */
    case IP_HAS_JOIN_WITH_V1_V2 = '0012';

    /** 此IP已經參加過活動（活動V2重複） */
    case IP_HAS_JOIN_WITH_V2 = '0013';

    /** 此手機已經參加過活動（註冊玩家重複） */
    case PHONE_HAS_JOIN_WITH_REGISTER = '0014';

    /** 此手機已經參加過活動（活動V1、V2重複） */
    case PHONE_HAS_JOIN_WITH_V1_V2 = '0015';

    /** 此手機已經參加過活動（活動V2重複） */
    case PHONE_HAS_JOIN_WITH_V2 = '0016';

    /** 此帳號已經參加過活動（註冊玩家重複） */
    case ACCOUNT_HAS_JOIN_WITH_REGISTER = '0017';

    /** 此帳號已經參加過活動（活動V1、V2重複） */
    case ACCOUNT_HAS_JOIN_WITH_V1_V2 = '0018';

    /** 此帳號已經參加過活動（活動V2重複） */
    case ACCOUNT_HAS_JOIN_WITH_V2 = '0019';

    /** 活動人數已達上限 */
    case MAX_JOIN_REACHED = '0020';

    /** 不在活動開放的會員／會員群組內（目標用戶，target/tickets#4234） */
    case TARGET_NOT_MATCHED = '0021';

    // RegisterService
    /** 註冊時間不在活動時間內 */
    case EVENT_01_NOT_IN_TIME = '0101';

    /** 註冊活動只能參加一次 */
    case EVENT_01_ONLY_ONE_TIMES = '0102';

    /** 請先完成實名認證 */
    case EVENT_01_REALNAME = '0103';

    // WelcomeBonus100Service
    /** 您尚未完成「歡迎獎金50%」這個活動，請先完成 */
    case EVENT_04_NOT_COMPLETE_EVENT_03 = '0401';

    // InviteFriendService
    /** 邀請人數不足 */
    case EVENT_13_INVITE_NOT_ENOUGH = '1301';

    // DownlineCommissionService
    /** 已超過參加時間 */
    case OVER_JOIN_TIME = '1401';

    /** 直屬下級會員結算不為輸分 */
    case WIN_LOSE = '1402';

    // FirstDepositService
    /** 此筆儲值已被其他獎項領取，無法用於本活動 */
    case DEPOSIT_ALREADY_AWARDED = '1503';

    /** 此筆儲值已用於參加其他活動 */
    case DEPOSIT_ALREADY_USED = '1504';

    // AngpowService（紅包，target/tickets#4234）
    /** 活動未設定消耗積分（條件配置缺 score，運營配錯） */
    case ANGPOW_SCORE_NOT_SET = '1601';

    /** 不在可領取時段（條件配置 time_window） */
    case ANGPOW_OUT_OF_TIME_WINDOW = '1602';

    /** 距上一次領取未滿 interval 秒 */
    case ANGPOW_INTERVAL_NOT_REACHED = '1603';

    /** 本週期個人領取次數已達 member_times */
    case ANGPOW_MEMBER_TIMES_REACHED = '1604';

    /** 本週期個人領取金額已達 member_amount */
    case ANGPOW_MEMBER_AMOUNT_REACHED = '1605';

    /** 活動已發出金額達 max_amount */
    case ANGPOW_MAX_AMOUNT_REACHED = '1606';

    /** 積分不足以支付 score */
    case ANGPOW_SCORE_NOT_ENOUGH = '1607';

    public function translate(?string $locale = null, array $replace = []): string
    {
        return __(key: 'event_v2.validation.' . $this->value, replace: $replace, locale: $locale);
    }

    public function isSuccess(): bool
    {
        return $this->value === self::SUCCESS->value;
    }

    public function isFailed(): bool
    {
        return !$this->isSuccess();
    }

    public function isBlockFailed(): bool
    {
        $blockFailed = [
            self::IP_HAS_JOIN_WITH_REGISTER->value,
            self::IP_HAS_JOIN_WITH_V1_V2->value,
            self::IP_HAS_JOIN_WITH_V2->value,
            self::PHONE_HAS_JOIN_WITH_REGISTER->value,
            self::PHONE_HAS_JOIN_WITH_V1_V2->value,
            self::PHONE_HAS_JOIN_WITH_V2->value,
            self::ACCOUNT_HAS_JOIN_WITH_REGISTER->value,
            self::ACCOUNT_HAS_JOIN_WITH_V1_V2->value,
            self::ACCOUNT_HAS_JOIN_WITH_V2->value,
        ];

        return in_array($this->value, $blockFailed);
    }

    public function createRecord(int $eventV2DetailId, Member $member, array $replace = []): void
    {
        $ip = Common::getIp();
        $message = $this->translate('zh_TW', $replace);

        $rejectJoin = EventV2RejectJoin::where('event_v2_detail_id', $eventV2DetailId)
            ->where('member_id', $member->id)
            ->where('status', EventV2RejectJoinStatusEnum::REJECT)
            ->first();

        if ($rejectJoin === null) {
            $rejectJoin = EventV2RejectJoin::create([
                'event_v2_detail_id' => $eventV2DetailId,
                'member_id' => $member->id,
            ]);
        }

        $rejectRecord = EventV2RejectRecord::where('event_v2_reject_join_id', $rejectJoin->id)
            ->orderBy('id', 'desc')
            ->first();

        if ($rejectRecord === null) {
            // 沒有錯誤紀錄，新增一筆
            $rejectRecord = EventV2RejectRecord::create([
                'event_v2_reject_join_id' => $rejectJoin->id,
                'validate_code' => $this->value,
                'message' => $message,
                'ip' => $ip,
            ]);

            $rejectJoin->last_event_v2_reject_record_id = $rejectRecord->id;
            $rejectJoin->save();
        } else {
            if ($rejectRecord->validate_code->value !== $this->value) {
                // 驗證碼跟上次不一樣，新增一筆
                $rejectRecord = EventV2RejectRecord::create([
                    'event_v2_reject_join_id' => $rejectJoin->id,
                    'validate_code' => $this->value,
                    'message' => $message,
                    'ip' => $ip,
                ]);

                $rejectJoin->last_event_v2_reject_record_id = $rejectRecord->id;
                $rejectJoin->save();
            } else {
                // 驗證碼跟上次一樣，IP、訊息不一樣，更新
                if ($rejectRecord->ip !== $ip || $rejectRecord->message !== $message) {
                    $rejectRecord->message = $message;
                    $rejectRecord->ip = $ip;
                    $rejectRecord->save();
                }
            }
        }
    }
}
