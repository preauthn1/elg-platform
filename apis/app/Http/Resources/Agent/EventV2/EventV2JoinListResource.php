<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2JoinListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 依參加紀錄上凍結的領取去向分流（target/tickets#4235）：主錢包型的 reward 是金額、積分型的是積分數，
        // 分成 reward／score_reward 兩欄，不屬於該型的回 null；不讀活動現值，活動中途改去向不影響歷史
        $isScore = $this->withdraw_type === EventV2WithdrawTypeEnum::SCORE;

        return [
            /**
             * @var int 參加ID
             *
             * @example 10
             **/
            'id' => $this->id,
            /**
             * @var int 活動詳細ID
             *
             * @example 2
             **/
            'event_v2_detail_id' => $this->event_v2_detail_id,
            /**
             * @var string 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var string 會員名稱
             *
             * @example "zoe102"
             **/
            'member_username' => $this->member_username,
            /**
             * @var float|null 活動獎勵（金額）：只有主錢包型有值，積分型為 null（前端顯示「—」）
             *
             * @example 10
             **/
            'reward' => $isScore ? null : (float) $this->reward,
            /**
             * @var float|null 獲得積分：只有積分型（target/tickets#4231）有值，主錢包型為 null（前端顯示「—」）
             *
             * @example 20
             **/
            'score_reward' => $isScore ? (float) $this->reward : null,
            /**
             * @var float|null 消耗積分：這筆參加扣掉的門票積分（member_score_logs 參照本紀錄的扣除加總，target/tickets#4235）；
             *                 沒扣分的活動（非紅包）為 null，前端顯示「—」不顯示 0
             *
             * @example 10
             **/
            'score_cost' => $this->score_cost === null ? null : (float) $this->score_cost,
            /**
             * @var int 領取去向(1:主錢包,2:積分)，參加當下凍結值；見 EventV2WithdrawTypeEnum
             *
             * @example 1
             **/
            'withdraw_type' => $this->withdraw_type->value,
            /**
             * @var float 餘額門檻
             *
             * @example 160
             **/
            'balance_limit' => (float) $this->balance_limit,
            /**
             * @var float 流水門檻
             *
             * @example 0
             **/
            'flow_limit' => (float) $this->flow_limit,
            /**
             * @var float 提款上限
             *
             * @example 1000
             **/
            'withdraw_limit' => (float) $this->withdraw_limit,
            /**
             * @var float|null 已提款金額（實際轉進主錢包）：積分型沒有轉入主錢包，為 null（前端顯示「—」）
             *
             * @example 200
             **/
            'withdraw' => $isScore ? null : (float) $this->withdraw,
            /**
             * @var string 活動優惠碼
             *
             * @example "M838NP94"
             **/
            'bonus_code' => $this->bonus_code,
            /**
             * @var string IP
             *
             * @example "0.0.0.0"
             **/
            'ip' => $this->ip,
            /**
             * @var string 加入時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'join_time' => \Carbon\Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
            /**
             * @var null|string 結束時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'end_time' => $this->end_time ? \Carbon\Carbon::parse($this->end_time)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var int 狀態(1:參加,2:完成,3:放棄)
             *
             * @example 1
             **/
            'status' => $this->status,
        ];
    }
}
