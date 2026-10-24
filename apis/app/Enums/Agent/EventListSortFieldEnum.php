<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * 後台「活動管理」（V1 活動列表）可排序欄位（target/tickets#4320）。
 *
 * 值即前端表頭的 prop；orderColumns() 回對應的 SQL 排序欄位／select 別名，
 * 白名單化避免把使用者輸入直接拼進 ORDER BY。
 */
enum EventListSortFieldEnum: string
{
    case ID = 'id';
    case AGENT_USERNAME = 'agent_username';
    case TITLE = 'title';
    /** 滿足條件：依首筆 detail 的行為（action_id）再依門檻（point） */
    case CONDITION = 'condition';
    /** 開始時間：啟用中 detail 的最早開始時間（Jackpot 活動取 event_jackpots） */
    case START_TIME = 'start_time';
    /** 結束時間：啟用中 detail 的最晚結束時間（Jackpot 活動取 event_jackpots） */
    case END_TIME = 'end_time';
    case PRIZE_TYPE_ID = 'prize_type_id';
    case PRIZE_IS_REPEATEDLY = 'prize_is_repeatedly';
    case PRIZE_EXPIRE_DATE = 'prize_expire_date';
    case STATUS = 'status';

    /**
     * 排序用欄位（依序套用同一方向）。別名對應 EventListService 的 select 清單。
     *
     * @return list<string>
     */
    public function orderColumns(): array
    {
        return match ($this) {
            self::ID => ['events.id'],
            self::AGENT_USERNAME => ['agent_username'],
            self::TITLE => ['title'],
            self::CONDITION => ['sort_action_id', 'sort_point'],
            self::START_TIME => ['sort_start_time'],
            self::END_TIME => ['sort_end_time'],
            self::PRIZE_TYPE_ID => ['prize_type_id'],
            self::PRIZE_IS_REPEATEDLY => ['prize_is_repeatedly'],
            self::PRIZE_EXPIRE_DATE => ['prize_expire_date'],
            self::STATUS => ['events.status'],
        };
    }
}
