<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberMail;

use App\Enums\LanguageEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Validator;

/**
 * 新增／編輯站內信 工單4152
 *
 * 依 send_type 要求對應的對象欄位；多語系內容英文必填——前台語系
 * fallback 的底線是英文，沒有英文就會有會員看到空白信。
 */
class SaveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 站內信編號（帶 id 為編輯，不帶為新增）
             *
             * @example 1
             */
            'id' => 'nullable|integer|min:1',
            /**
             * @var int 發送類型 1:指定會員 2:會員群組 3:全平臺(組織) 4:代理及子代理
             *
             * @example 1
             */
            'send_type' => 'required|integer|in:1,2,3,4',
            /**
             * @var string 發布時間（到點前台才顯示；到點後整封信鎖定不可異動）
             *
             * @example "2026-09-01 10:00:00"
             */
            'publish_time' => 'required|date_format:Y-m-d H:i:s',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var string 備註（僅後台可見）
             *
             * @example "九月活動通知"
             */
            'memo' => 'nullable|string|max:500',
            /**
             * 多語系內容清單。必須包含英文（language_id=1）——前台語系 fallback 的底線。
             *
             * @var array<array{language_id: int, title: string, content: string}> 多語系內容
             */
            'languages' => 'required|array|min:1',
            'languages.*.language_id' => 'required|integer|min:1',
            'languages.*.title' => 'required|string|max:200',
            'languages.*.content' => 'required|string|max:20000',
            /**
             * @var array<int> 收件會員ID清單（send_type=1 必填）
             */
            'member_ids' => 'required_if:send_type,1|array',
            'member_ids.*' => 'integer|min:1',
            /**
             * @var array<int> 會員群組ID清單（send_type=2 必填，存檔時展開為收件人）
             */
            'member_group_ids' => 'required_if:send_type,2|array',
            'member_group_ids.*' => 'integer|min:1',
            /**
             * @var int 指定代理ID（send_type=4 必填，該代理及其子代理全部會員）
             *
             * @example 4249
             */
            'hierarchy_agent_id' => 'required_if:send_type,4|integer|min:1',
            /**
             * @var int 組織ID（send_type=3 必填，發給該組織下所有會員）
             *
             * @example 1
             */
            'organization_id' => 'required_if:send_type,3|integer|min:1',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $languages = $this->input('languages', []);

            $hasEnglish = collect($languages)->contains(
                fn ($row) => (int) ($row['language_id'] ?? 0) === LanguageEnum::EN->value
                    && trim((string) ($row['title'] ?? '')) !== ''
                    && trim((string) ($row['content'] ?? '')) !== ''
            );

            if (!$hasEnglish) {
                $validator->errors()->add(
                    'languages',
                    __('error_message.9991'),
                );
            }
        });
    }
}
