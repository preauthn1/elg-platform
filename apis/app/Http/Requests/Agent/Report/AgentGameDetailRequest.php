<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

use App\Http\Requests\AbstractRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * SR29 代理遊戲明細表查詢條件（target/tickets#4318）。
 *
 * `start_time`／`end_time` 是使用者所選時區的牆鐘，時區由 `tz_offset` 指定，
 * 與代理數據看板（target/tickets#4180）、SR27（target/tickets#4321）同一套時區規則：
 * 後端負責轉 UTC 查詢，前端只送牆鐘不自行平移。
 *
 * 這支端點同時服務主表與所有下鑽層，層級由參數決定：
 * - 不帶 `vendor_id`／`game_type_id` → 第 0 層（主表），依「遊戲商 × 遊戲類別」彙總。
 *   查詢表單的篩選走 `filter_vendor_id`／`filter_game_type_id`，兩者**各自獨立**、
 *   可以只給一個。
 * - 帶 `vendor_id` ＋ `game_type_id` → 下鑽層，列出 `agent_id`（預設為登入者）
 *   的直屬下級；這兩個條件在整段下鑽過程鎖定不變。
 *
 * 🔴 **篩選與下鑽鍵刻意用不同的參數名**：原本兩者共用 `vendor_id`／`game_type_id`，
 * 結果查詢表單只選遊戲商就被下面的成對檢查擋成 422，兩個都選則直接跳過主表回了
 * 下鑽層——前端的麵包屑還停在第 0 層，資料與畫面對不上。共用一組欄位就無法分辨
 * 「使用者要篩選」還是「使用者點了一列要往下鑽」，所以拆開。`filter_*` 只作用在
 * 主表；下鑽層的遊戲商與類別已由被點的那一列鎖定，必然落在篩選範圍內，不需要再帶。
 *
 * 🔴 `vendor_id` 與 `game_type_id` 必須成對出現，`agent_id` 必須配合這兩者：
 * 少一個就無法決定要算哪一層，寧可擋下來也不要猜一個預設值回一組對不起來的數字
 * （需求文件的驗收核心是「本層相加 ＝ 本層合計 ＝ 上一層被點的那一列」）。
 *
 * `agent_id` 是否在登入者可視範疇內由 Service 驗證，不在這裡做——那需要查 DB，
 * 屬於商業規則不是格式驗證。
 */
class AgentGameDetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'tz_offset' => 'required|integer|between:-12,12',
            // 主表的代理商帳號篩選（需求文件「我方主動加」）。可含 - 與 _（如 T-POWER）
            'agent_username' => 'nullable|alpha_dash|between:1,30',
            // 主表的查詢表單篩選，兩者各自獨立（理由見 class docblock）
            'filter_vendor_id' => 'nullable|integer|min:1',
            'filter_game_type_id' => 'nullable|integer|min:0',
            // 下鑽鍵：成對出現，見 validateDrillParams()
            'vendor_id' => 'nullable|integer|min:1',
            // 遊戲類別允許 0：games 查無對應（game_id = 0）時歸「未分類」，
            // 主表會出現 game_type_id = 0 的列，點下去也要能帶著這個值下鑽。
            'game_type_id' => 'nullable|integer|min:0',
            'agent_id' => 'nullable|integer|min:1',
            // 分頁只作用在下鑽層，主表一律回整層（理由見 AgentGameDetailService 的「分頁」）
            'page' => 'nullable|integer|min:1',
            // 畫面下拉只給 10/20/50/100/500/1000（不提供「全部」）；上限放寬到 100000
            // 是給匯出用的，匯出要把當前所在層一次取回（需求文件③）。
            'per_page' => 'nullable|integer|between:1,100000',
        ];
    }

    /**
     * 期間上限 3 個月，與 SR27 同一套規則與理由：
     *
     * 🔴 必須用 `addMonthsNoOverflow()`：`addMonths()` 會溢位（1/31 加三個月得
     * 5/1 而不是 4/30），而前端守衛用的 dayjs `.add(3,'month')` 是截斷的，兩邊
     * 會在月底日期上判定不一致。
     * **比對一律取當日零時**：前端的迄日固定補 23:59，直接比時刻會讓「剛好三個月」
     * 的區間被擋掉，同樣與前端不一致。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePeriod($validator);
            $this->validateDrillParams($validator);
        });
    }

    private function validatePeriod(Validator $validator): void
    {
        $start = $this->input('start_time');
        $end = $this->input('end_time');
        if (!is_string($start) || !is_string($end)) {
            return;
        }

        $limit = Carbon::parse($start)->startOfDay()->addMonthsNoOverflow(3);
        if (Carbon::parse($end)->startOfDay()->greaterThan($limit)) {
            $validator->errors()->add('end_time', 'period must not exceed 3 months');
        }
    }

    /** 下鑽三參數的相依關係，理由見 class docblock */
    private function validateDrillParams(Validator $validator): void
    {
        $hasVendor = $this->input('vendor_id') !== null;
        $hasGameType = $this->input('game_type_id') !== null;

        if ($hasVendor !== $hasGameType) {
            $validator->errors()->add(
                'game_type_id',
                'vendor_id and game_type_id must be provided together',
            );
        }

        if ($this->input('agent_id') !== null && !$hasVendor) {
            $validator->errors()->add(
                'agent_id',
                'agent_id requires vendor_id and game_type_id',
            );
        }
    }
}
