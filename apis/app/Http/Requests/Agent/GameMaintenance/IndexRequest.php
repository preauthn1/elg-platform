<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GameMaintenance;

use App\Http\Requests\AbstractRequest;

/**
 * 遊戲維護列表查詢 工單4120
 */
class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
            /**
             * 一張維護單可跨遊戲商，此處採「涵蓋即列出」：
             * 只要單內有任一款屬於該商的遊戲就會被列出。
             *
             * @var int 遊戲商編號（vendors.id）
             *
             * @example 248
             */
            'vendor_id' => 'nullable|integer',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 20
             */
            'per_page' => 'required|integer',
        ];
    }
}
