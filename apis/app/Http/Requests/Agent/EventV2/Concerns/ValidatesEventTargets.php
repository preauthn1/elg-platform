<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2\Concerns;

use App\Enums\MemberRestrictionTargetEnum;
use App\Services\EventV2\EventV2TargetScope;
use Illuminate\Validation\Validator;

/**
 * 活動 V2 目標用戶（target_type＋target_ids，target/tickets#4234）的後台驗證，Create／Update 共用。
 *
 * 必須：
 * - target_type：0 或不送＝不限制；1＝指定會員；2＝會員群組（MemberRestrictionTargetEnum）。
 * - 選了類型就必須選內容（target_ids 非空），沒選類型卻帶了 target_ids 也擋——避免運營以為有限制。
 * - target_ids 每個都要在「活動代理商帳號那條線」裡（EventV2TargetScope）：代理商帳號照舊決定誰看得到活動，
 *   目標用戶只是在這條線內再縮小誰能參加；一次只能一種類型（同一個欄位，天然不混用）。
 * - 舊後台永遠不送這兩個欄位：Create 落不限制、Update 沿用資料庫現值（Controller 以 has('target_type') 判斷要不要重寫）。
 */
trait ValidatesEventTargets
{
    /** @return array<string, mixed> */
    protected function targetRules(string $presence): array
    {
        return [
            'target_type' => [$presence, 'nullable', 'integer', 'in:0,1,2'],
            'target_ids' => ['nullable', 'array'],
            'target_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function targetType(): ?MemberRestrictionTargetEnum
    {
        return MemberRestrictionTargetEnum::tryFrom((int) $this->input('target_type'));
    }

    /** @return list<int> */
    public function targetIds(): array
    {
        $ids = $this->input('target_ids');

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    protected function validateTargets(Validator $validator): void
    {
        if (!$this->has('target_type') && !$this->has('target_ids')) {
            return;
        }

        $type = $this->targetType();
        $ids = $this->targetIds();

        if ($type === null) {
            if ($ids !== []) {
                $validator->errors()->add('target_type', __('event_v2.validation.target_type_required'));
            }

            return;
        }

        if ($ids === []) {
            $validator->errors()->add('target_ids', __('event_v2.validation.target_ids_required'));

            return;
        }

        // 只能從活動代理那條線裡選（EventV2TargetScope，與選項接口同一份規則）；代理不存在交給 Controller 既有檢查
        $scope = EventV2TargetScope::forAgentId((int) $this->input('agent_id'));
        if ($scope !== null && !$scope->containsAll($type === MemberRestrictionTargetEnum::MEMBER, $ids)) {
            $validator->errors()->add('target_ids', __('event_v2.validation.target_ids_invalid'));
        }
    }
}
