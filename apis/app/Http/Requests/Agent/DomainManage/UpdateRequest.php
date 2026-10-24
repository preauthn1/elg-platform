<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\DomainManage;

use App\Http\Requests\AbstractRequest;
use App\Services\Agent\DomainManageService;
use Illuminate\Validation\Rule;

class UpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 域名編號
             *
             * @example 1
             */
            'id' => 'required|integer|exists:domains,id',
            /**
             * @var string 域名（不可與其他筆重複）
             *
             * @example "example.invalid"
             */
            'domain' => [
                'required',
                'string',
                'max:191',
                Rule::unique('domains', 'domain')->ignore($this->input('id')),
            ],
            /**
             * @var int 類型編號 1:代理後台 3:客服後台（2 前台屬玩家站台，不在此管理）
             *
             * @example 1
             */
            'domain_type_id' => [
                'required',
                'integer',
                'exists:domain_types,id',
                Rule::in(DomainManageService::ALLOWED_TYPE_IDS),
            ],
            /**
             * @var int 所屬代理編號（後台依域名取設定的必要關聯，一域名對一代理）
             *
             * @example 100005
             */
            'agent_id' => 'required|integer|exists:agents,id',
            /**
             * @var string 名稱
             *
             * @example "代理後台主站"
             */
            'domain_name' => 'nullable|string|max:191',
            /**
             * @var string LOGO 淺色模式圖片網址
             *
             * @example "https://example.invalid/.../logo-light.png"
             */
            'logo_lm' => 'nullable|string|max:500',
            /**
             * @var string LOGO 深色模式圖片網址
             *
             * @example "https://example.invalid/.../logo-dark.png"
             */
            'logo_dm' => 'nullable|string|max:500',
            /**
             * @var string 色系（hex 色碼，沿用個性化 #2519 格式）
             *
             * @example "#880000"
             */
            'theme_color' => ['nullable', 'string', 'max:20', 'regex:/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
            /**
             * @var string 網站 icon（favicon）圖片網址
             *
             * @example "https://example.invalid/.../favicon.png"
             */
            'icon' => 'nullable|string|max:500',
            /**
             * @var int 排序
             *
             * @example 0
             */
            'sort' => 'nullable|integer|min:0',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
        ];
    }
}
