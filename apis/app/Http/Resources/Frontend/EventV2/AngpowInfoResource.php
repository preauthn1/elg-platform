<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 紅包畫面資訊（frontend event-v2/angpow/info，target/tickets#4234）。
 * 數字由 AngpowService::frontendInfo() 算，這裡只負責輸出；null＝該限制沒設（不限制）。
 * 能不能抽、為什麼不能抽不在這裡：由 event-v2/join 失敗時的 message 回（Max 2026-10-01 收斂欄位）。
 */
class AngpowInfoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var bool 活動是否開啟中：在活動期間內且在每日可抽時段內（停用也算未開啟）。false 不展示紅包；其餘數字照回
             *
             * @example true
             **/
            'is_open' => $this['is_open'],
            /**
             * @var string|null 每抽一次扣幾分；null＝活動沒設（配錯，不能抽）
             *
             * @example "10.0000"
             **/
            'score_cost' => $this['score_cost'],
            /**
             * @var string 會員目前可用積分（同 member/detail 的 score）
             *
             * @example "39.0000"
             **/
            'score_balance' => $this['score_balance'],
            /**
             * @var int|null 個人本期（可重複參加週期）次數上限；null＝不限
             *
             * @example 5
             **/
            'member_times_limit' => $this['member_times_limit'],
            /**
             * @var int|null 個人本期還能抽幾次；null＝不限
             *
             * @example 3
             **/
            'member_times_remaining' => $this['member_times_remaining'],
            /**
             * @var int|null 全站本期（包括期間）總次數上限；null＝不限
             *
             * @example 1000
             **/
            'site_times_limit' => $this['site_times_limit'],
            /**
             * @var int|null 全站本期還剩幾包；null＝不限
             *
             * @example 880
             **/
            'site_times_remaining' => $this['site_times_remaining'],
            /**
             * @var string|null 全站本期總金額上限；null＝不限
             *
             * @example "20000.0000"
             **/
            'site_amount_limit' => $this['site_amount_limit'],
            /**
             * @var string|null 全站本期還能發多少；null＝不限，最低 0（最後一包可能讓已發出超過上限）
             *
             * @example "16550.0000"
             **/
            'site_amount_remaining' => $this['site_amount_remaining'],
            /**
             * @var int|null 同一人兩次抽之間要隔幾秒，前端據此決定要不要做間隔處理；null＝不限
             *
             * @example 300
             **/
            'interval' => $this['interval'],
            /**
             * @var int 距下次可抽還要等幾秒；0＝現在就可以（interval 沒設或還沒抽過也是 0）
             *
             * @example 42
             **/
            'next_available_in' => $this['next_available_in'],
            /**
             * @var array<int, string>|null 每天可抽時段 ["起", "迄"]，已轉成前台時區；null＝全天
             *
             * @example ["08:00", "23:00"]
             **/
            'time_window' => $this['time_window'],
            /**
             * @var bool 現在是否在可抽時段內（全天恆為 true）
             *
             * @example true
             **/
            'in_time_window' => $this['in_time_window'],
        ];
    }
}
