<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend\TGBot;

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Models\Brand;
use App\Models\MemberTelegramRel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TGBotAuthMember
{
    public function handle(Request $request, Closure $next): Response
    {
        // 取得telegram id與brand code
        $tg_id = $request->header('x-bot-tgID');
        $brand_code = $request->header('x-bot-ID');

        if (empty($tg_id)) {
            throw new ApiException(__('error_message.9998'), HttpCode::HTTP_NOT_ACCEPTABLE->value);
        }

        // 取得品牌與代理資訊
        $brand = Brand::query()
            ->where('code', $brand_code)
            ->with('info.agent')
            ->firstOrFail();

        $brandAgent = $brand->info?->agent;

        if (!$brandAgent) {
            throw new ApiException(__('error_message.9998'), HttpCode::HTTP_NOT_ACCEPTABLE->value);
        }

        // 使用模型關聯查詢會員與代理
        $telegramRel = MemberTelegramRel::query()
            ->where('brand_id', $brand->id)
            ->where('tg_user_id', $tg_id)
            ->with(['member.agent'])
            ->firstOrFail();

        $member = $telegramRel->member;
        $agent = $member->agent;

        if (!$member || !$agent || !$agent->hasParentAgent($brandAgent->id)) {
            throw new ApiException(__('error_message.9998'), HttpCode::HTTP_NOT_ACCEPTABLE->value);
        }

        // 設定上下文
        Context::addHidden(ContextKeyEnum::MEMBER->value, $member->makeHidden('agent'));
        Context::addHidden(ContextKeyEnum::AGENT->value, $agent);

        // 設定日誌上下文
        Log::withContext([
            'member_id' => $member->id,
            'agent_id' => $agent->id,
        ]);

        return $next($request);
    }
}
