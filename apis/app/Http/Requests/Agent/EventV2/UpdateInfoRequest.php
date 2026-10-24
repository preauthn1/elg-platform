<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class UpdateInfoRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'event_detail_id' => 'required|integer',
            /**
             * @var array|null 活動詳細語系
             **/
            'detail_lang' => 'nullable|array',
            /**
             * @var int 語系ID
             *
             * @example 2
             **/
            'detail_lang.*.language_id' => 'required|integer',
            /**
             * @var string|null 活動名稱
             *
             * @example "每日簽到存 30 送 10"
             **/
            'detail_lang.*.name' => 'nullable|string',
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             **/
            'detail_lang.*.image' => 'nullable|string',
            /**
             * @var string|null 活動內容
             *
             * @example "<div>內容</div>"
             **/
            'detail_lang.*.content' => 'nullable|string',
            /**
             * @var int|null 排序
             *
             * @example 1
             **/
            'sort' => 'nullable|integer',
            /**
             * @var int|null 活動狀態
             *
             * @example 1
             **/
            'status' => 'nullable|integer',
        ];
    }
}
