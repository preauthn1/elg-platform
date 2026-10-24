<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGroup;

class UpdateRequest extends StoreRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 群組編號
             *
             * @example 1
             */
            'id' => 'required|integer',
            /**
             * @var int|null 擁有者代理編號（編輯不可變更擁有者，放寬為選填避免未傳被 required 擋下）
             *
             * @example 33893
             */
            'agent_id' => 'nullable|integer',
        ] + parent::rules();
    }
}
