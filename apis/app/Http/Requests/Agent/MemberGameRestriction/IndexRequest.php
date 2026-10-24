<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGameRestriction;

use App\Http\Requests\AbstractRequest;

/**
 * 會員遊戲限制列表 工單4126
 */
class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 狀態 1:啟用 0:停用（不帶為全部）
             *
             * @example 1
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var int 限制類型 1:遊戲商 2:遊戲（不帶為全部）
             *
             * @example 2
             */
            'restrict_type' => 'nullable|integer|in:1,2',
            /**
             * @var int 目標用戶類型 1:指定會員 2:會員群組（不帶為全部）
             *
             * @example 1
             */
            'target_scope' => 'nullable|integer|in:1,2',
            /**
             * 涵蓋即列出：一張單可含多個會員，其中一個符合就列出。
             *
             * @var string 會員帳號關鍵字（模糊比對）
             *
             * @example "max91"
             */
            'username' => 'nullable|string|max:50',
            /**
             * @var int 會員群組編號
             *
             * @example 3
             */
            'member_group_id' => 'nullable|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 15
             */
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
