<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use App\Services\EventV2\EventV2FieldParamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventListResource extends JsonResource
{
    private static ?EventV2FieldParamService $fieldParamService = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 活動ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string|null 處理類別（app/Services/EventV2 下的類別名），前端據此判斷該類型可否搭配積分領取去向
             *
             * @example "DailyFreeService"
             **/
            'handler_class' => $this->handler_class,
            /**
             * @var array<string, mixed>|null 「設定條件」十格各自可填的參數，依請求語系本地化；handler 不存在為 null（target/tickets#4231 需求 7）。
             *                                key 為欄位名（condition_formula…flow_config），值 {main: {kind, items[]}, score?: {kind, items[]}}——
             *                                score 只在領取去向可選積分的類型、且只有提款限制公式／配置兩格才有；items 各含 name／type／description／example。
             *
             * @example {"condition_config":{"main":{"kind":"keys","items":[{"name":"max_join","type":"integer","description":"全站參加上限","example":"{\"max_join\": 100}"}]}}}
             **/
            'field_params' => (self::$fieldParamService ??= app(EventV2FieldParamService::class))->forHandler($this->handler_class),
        ];
    }
}
