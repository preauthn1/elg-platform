<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\VendorManagement;

use App\Http\Requests\AbstractRequest;

/**
 * 廠商管理－更新回調路徑後綴 target/tickets#4400
 *
 * 只適用無縫錢包且有掛 Driver 的遊戲商，後端另行驗證；傳 null 或空字串代表退回 Driver 預設。
 * callback_path 必須明確帶上（present），避免只傳 id 時被當成清除覆寫。
 *
 * @knowledge rules/vendor-and-channel-management
 */
class UpdateCallbackPathRequest extends AbstractRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * @var int 遊戲商編號（vendors.id）
             *
             * @example 124
             */
            'id' => 'required|integer',
            /**
             * @var string|null 回調路徑後綴，必填欄位（值可為 null 或空字串，代表退回 Driver 預設）；
             *                  由一或多段 `/段落` 組成，每段只允許英數、底線、連字號，不可帶 query、fragment、空段、點或百分比編碼
             *
             * @example "/external/api/sw/tthsw"
             */
            'callback_path' => ['present', 'nullable', 'string', 'max:191', 'regex:/^(\/[A-Za-z0-9_-]+)+$/'],
        ];
    }
}
