<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrPlan;

use App\Models\GgrPlan;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 方案 修改 工單4121
 *
 * 規則同 StoreRequest（特殊用戶列全量替換），僅多 id。
 */
class UpdateRequest extends StoreRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 方案編號
             *
             * @example 1
             */
            'id' => 'required|integer',
            ...parent::rules(),
        ];
    }

    /**
     * 沿用既有方案原本的開始日期一律放行（已開跑的方案日期本來就在過去），
     * 只有「改成新日期」時才套 StoreRequest 的下限——擋的是把日期往前挪、
     * 讓引擎補出大量歷史期間並在下一輪發放全數入帳。
     *
     * @return array<int, string>
     */
    protected function startDateRules(): array
    {
        $current = GgrPlan::query()->whereKey($this->input('id'))->value('start_date');

        if ($current !== null
            && $this->input('start_date') === Carbon::parse($current)->toDateString()) {
            return ['required', 'date_format:Y-m-d'];
        }

        return parent::startDateRules();
    }
}
