<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGameRestriction;

use App\Enums\Member\MemberRestrictScopeEnum;
use App\Http\Requests\AbstractRequest;

/**
 * 會員遊戲限制新增／編輯 工單4126
 *
 * 依 target_scope 擇一必填：1 指定會員送 member_ids、2 會員群組送 member_group_ids。
 * 另一組不必送，送了也會在 Service 忽略。
 */
class SaveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 帶 id 為編輯，不帶為新增。
             *
             * @var int 限制單編號
             *
             * @example 1
             */
            'id' => 'nullable|integer',
            /**
             * 決定 target_ids 的意義：1 送 vendor_id、2 送 game_id。
             *
             * @var int 限制類型 1:遊戲商 2:遊戲
             *
             * @example 2
             */
            'restrict_type' => 'required|integer|in:1,2',
            /**
             * @var int 對象範圍 1:指定會員 2:會員群組
             *
             * @example 1
             */
            'target_scope' => 'required|integer|in:1,2',
            /**
             * 限制會沿 hierarchy 對該代理的下線會員一併生效。
             *
             * @var int 建立此限制的代理編號
             *
             * @example 1
             */
            'agent_id' => 'required|integer',
            /**
             * 依 restrict_type 為 vendors.id 或 games.id 的清單。
             *
             * @var array<int> 受限標的
             *
             * @example [61, 54]
             */
            'target_ids' => 'required|array|min:1',
            'target_ids.*' => 'integer',
            /**
             * target_scope=1 時必填。
             *
             * @var array<int> 受限會員編號
             *
             * @example [61058]
             */
            'member_ids' => 'array',
            'member_ids.*' => 'integer',
            /**
             * target_scope=2 時必填。
             *
             * @var array<int> 受限會員群組編號
             *
             * @example [3]
             */
            'member_group_ids' => 'array',
            'member_group_ids.*' => 'integer',
            /**
             * @var int 啟用狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var string 備註
             *
             * @example "風控限制"
             */
            'memo' => 'nullable|string|max:500',
        ];
    }

    /**
     * 對象清單依 target_scope 擇一必填——兩者皆空會建出「限制了遊戲但沒有對象」的空單。
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $scope = (int) $this->input('target_scope');

            if ($scope === MemberRestrictScopeEnum::MEMBER->value && empty($this->input('member_ids'))) {
                $validator->errors()->add('member_ids', __('validation.required', ['attribute' => 'member_ids']));
            }

            if ($scope === MemberRestrictScopeEnum::GROUP->value && empty($this->input('member_group_ids'))) {
                $validator->errors()->add('member_group_ids', __('validation.required', ['attribute' => 'member_group_ids']));
            }
        });
    }
}
