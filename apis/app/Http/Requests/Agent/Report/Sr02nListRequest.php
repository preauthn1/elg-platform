<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

/**
 * SR02N 開洗分明細表：列表（target/tickets#4356）
 */
class Sr02nListRequest extends Sr02nFilterRequest
{
    /** 每頁筆數上限（前端匯出一次撈全量，設天花板防護） */
    public const int MAX_PER_PAGE = 100000;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * @var int 每頁筆數（匯出時前端帶大值，設天花板防護）
             *
             * @example 20
             */
            'per_page' => 'required|integer|between:1,' . self::MAX_PER_PAGE,
        ];
    }
}
