<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Models\Agent;
use App\Services\PlatformStatisticService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Context;

#[Group('PlatformStatistic')]
class PlatformStatisticController extends Controller
{
    public function __construct(private PlatformStatisticService $platformStatisticService) {}

    /**
     * 平台累計統計（免登入，依 domain 判定品牌）
     *
     * 官方形象站首頁「平台核心数据」的四個累計數字：註冊會員數、代理數、
     * 累計代理佣金、累計活動獎金。全歷史累計、快取 1 小時。
     *
     * 由 official-tpower（Node）伺服器對伺服器呼叫，祕密留在該側，
     * 瀏覽器只會拿到它快取後的純 JSON。
     */
    public function __invoke(DomainRequest $request)
    {
        // domain ⇒ 代理由 HasDomain middleware 解析並放進 Context，此處只補代理本身的狀態檢查
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        if (!$agent instanceof Agent || $agent->status != 1) {
            throw new ApiException(__('error_message.1213'));
        }

        return ApiResponse::success($this->platformStatisticService->overview($agent));
    }
}
