<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Models\AgentDomain;
use App\Services\Agent\BasicInfoService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('BasicInfo (基本資訊)')]
class BasicInfoController extends Controller
{
    public function __construct(
        private readonly BasicInfoService $service,
    ) {}

    /**
     * 取頁尾基本資訊內容
     *
     * 回傳關於我們／條款和條件／常見問題／隱私政策／博彩責任的內容，
     * 內容取值為**當前語系 → 英文 → 空字串**（依請求的 Accept-Language 判定語系）。
     *
     * 五項一律回傳、key 固定，未填內容或開關關閉者 content 為空字串——
     * 前端依固定 key 取值，少欄位會出錯。
     *
     * 各項是否開啟另由 template 接口的 basic_info 旗標下發，前台可據此
     * 決定頁尾要放哪些入口，點開才呼叫本接口取內容（長文不隨 template 傳）。
     */
    public function index(DomainRequest $request): JsonResponse
    {
        $input = $request->validated();

        // 與 template 接口一致：由域名決定屬於哪個代理
        $agentDomain = AgentDomain::with('agent')
            ->where('domain', $input['domain'])
            ->where('status', 1)
            ->first();

        if (!$agentDomain || !$agentDomain->agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        return ApiResponse::success([
            'items' => $this->service->getContents($agentDomain->agent),
        ]);
    }
}
