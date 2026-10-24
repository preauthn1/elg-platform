<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberDetail;

use App\Http\Requests\AbstractRequest;

/**
 * 會員詳情抽屜 › 統計摘要（4274；期間改收後台時區的日時分秒，target/tickets#4438 第 8 項）。
 * 前端把使用者所選時區的「當天 00:00:00～23:59:59」換算成後台時區（GMT+8）牆鐘再送來，
 * 服務端再轉成 UTC 查資料，台北凌晨的交易才不會被算到前一天。
 */
class MemberDetailStatisticsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => 'required|integer|min:1',
            /**
             * @var string 期間起（後台時區牆鐘；與 end_time 成對，皆空為全期間）
             *
             * @example "2026-10-09 00:00:00"
             */
            'start_time' => 'nullable|required_with:end_time|date_format:Y-m-d H:i:s',
            /**
             * @var string 期間迄（後台時區牆鐘，含該秒）
             *
             * @example "2026-10-09 23:59:59"
             */
            'end_time' => 'nullable|required_with:start_time|date_format:Y-m-d H:i:s|after_or_equal:start_time',
        ];
    }
}
