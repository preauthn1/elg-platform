<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\EmailTextTypeEnum;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EmailGateway\EmailGroupSettingDetailSaveRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGroupSettingTextSaveRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGroupSettingViewRequest;
use App\Models\AgentGroup;
use App\Models\EmailGatewayGroupDetail;
use App\Models\EmailText;
use App\Models\Language;
use App\Services\Agent\AgentOperateLogService;
use App\Services\EmailTextService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 電子郵件設置組別（agent_group_type=7）的高級設置：群組設定（有效時間／發送上限）與驗證碼郵件樣板
 * （target/tickets#4286）。
 *
 * 對應舊系統 API_Agent 簡訊組別的高級設置（SmsController::agentGroupSetting / saveAgentGroupSetting /
 * saveSmsTextContent）。差別是簡訊樣板以國家分、郵件樣板以語系分，且郵件多了主旨。
 */
#[Group('email-gateway (電子郵件提供商)')]
class EmailGatewayGroupSettingController extends Controller
{
    public function __construct(
        private readonly EmailTextService $emailTextService,
        private readonly AgentOperateLogService $agentOperateLogService,
    ) {}

    /**
     * 高級設置一次載入：群組設定、全部樣板、可選語系
     *
     * 首次載入會為組別補齊 8 個類型的全語系預設列。組別在舊系統 API_Agent 建立，
     * 本系統沒有能在建立當下執行的處理程序（hook），所以本方法在首次載入時補（寄信時也會補）。
     */
    public function view(EmailGroupSettingViewRequest $request): JsonResponse
    {
        $group = $this->findGroup((int) $request->group_id);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $this->emailTextService->ensureDefaults($group->id, $this->getAgentContext()->agent->id);

        $detail = EmailGatewayGroupDetail::where('agent_group_id', $group->id)->first();
        $languages = $this->activeLanguages();
        $languageNames = $languages->pluck('name', 'id');

        $texts = EmailText::where('email_gateway_group_id', $group->id)
            ->orderBy('type_id')
            ->orderBy('language_id')
            ->get()
            ->map(fn (EmailText $text): array => [
                'id' => $text->id,
                'type_id' => $text->type_id,
                'parameter' => EmailTextTypeEnum::tryFrom($text->type_id)?->parameter(),
                'language_id' => $text->language_id,
                'language_name' => $languageNames[$text->language_id] ?? null,
                'subject' => $text->subject,
                'content' => $text->content,
            ]);

        return ApiResponse::success([
            'group_id' => $group->id,
            'group_name' => $group->name,
            'group_detail' => $detail ? [
                'id' => $detail->id,
                'setting' => [
                    'token_expired' => $detail->setting['token_expired'] ?? null,
                    'max_send' => $detail->setting['max_send'] ?? null,
                ],
            ] : null,
            'email_text' => $texts,
            'languages' => $languages,
            'placeholders' => EmailTextService::PLACEHOLDERS,
        ]);
    }

    /**
     * 儲存群組設定（驗證碼有效時間、有效時間內最大發送次數）
     */
    public function saveDetail(EmailGroupSettingDetailSaveRequest $request): JsonResponse
    {
        $group = $this->findGroup((int) $request->group_id);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $agent = $this->getAgentContext()->agent;
        // 值以字串存，與 sms_gateway_group_details.setting 的既有形狀一致
        $setting = [
            'token_expired' => (string) $request->integer('token_expired'),
            'max_send' => (string) $request->integer('max_send'),
        ];

        EmailGatewayGroupDetail::updateOrCreate(
            ['agent_group_id' => $group->id],
            ['setting' => $setting, 'admin' => $agent->id],
        );

        $this->agentOperateLogService->log(
            agentId: $agent->id,
            type: AgentOperateTypeEnum::EMAIL_GROUP_DETAIL_UPDATE,
            debugInfo: new PlainDebugInfo($setting),
            ip: Common::getIp(),
            referenceId: (string) $group->id,
        );

        return ApiResponse::success();
    }

    /**
     * 儲存全部樣板（整組覆寫）
     *
     * 給的列 upsert；沒給的特定語系列刪除；每個類型的全語系列（language_id=0）必須存在、不可移除。
     */
    public function saveText(EmailGroupSettingTextSaveRequest $request): JsonResponse
    {
        $group = $this->findGroup((int) $request->group_id);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $rows = collect($request->input('email_text'))->map(fn (array $row): array => [
            'type_id' => (int) $row['type_id'],
            'language_id' => (int) $row['language_id'],
            'subject' => (string) $row['subject'],
            'content' => (string) $row['content'],
        ]);

        $keys = $rows->map(fn (array $row): string => $row['type_id'] . '_' . $row['language_id']);
        if ($keys->duplicates()->isNotEmpty()) {
            return ApiResponse::error(__('error_message.email_gateway.duplicate_text'));
        }

        $activeLanguageIds = $this->activeLanguages()->pluck('id')->all();
        $invalidLanguage = $rows
            ->pluck('language_id')
            ->reject(fn (int $id): bool => $id === EmailTextService::ALL_LANGUAGE_ID || in_array($id, $activeLanguageIds, true));
        if ($invalidLanguage->isNotEmpty()) {
            return ApiResponse::error(__('error_message.email_gateway.language_invalid'));
        }

        $typesWithDefault = $rows
            ->where('language_id', EmailTextService::ALL_LANGUAGE_ID)
            ->pluck('type_id')
            ->all();
        foreach (EmailTextTypeEnum::cases() as $type) {
            if (!in_array($type->value, $typesWithDefault, true)) {
                return ApiResponse::error(__('error_message.email_gateway.default_language_required'));
            }
        }

        $agent = $this->getAgentContext()->agent;

        DB::transaction(function () use ($group, $rows, $keys, $agent) {
            foreach ($rows as $row) {
                EmailText::updateOrCreate(
                    [
                        'email_gateway_group_id' => $group->id,
                        'type_id' => $row['type_id'],
                        'language_id' => $row['language_id'],
                    ],
                    [
                        'subject' => $row['subject'],
                        'content' => $row['content'],
                        'admin' => $agent->id,
                    ],
                );
            }

            // 沒給的特定語系列視為「移除」；全語系列不在刪除範圍（上面已驗證 8 個類型都有給）
            EmailText::where('email_gateway_group_id', $group->id)
                ->where('language_id', '!=', EmailTextService::ALL_LANGUAGE_ID)
                ->get()
                ->reject(fn (EmailText $text): bool => $keys->contains($text->type_id . '_' . $text->language_id))
                ->each(fn (EmailText $text) => $text->delete());
        });

        $this->agentOperateLogService->log(
            agentId: $agent->id,
            type: AgentOperateTypeEnum::EMAIL_TEXT_UPDATE,
            debugInfo: new PlainDebugInfo(['rows' => $rows->count()]),
            ip: Common::getIp(),
            referenceId: (string) $group->id,
        );

        return ApiResponse::success();
    }

    /**
     * 取目標組別；不是電子郵件設置組別、或不在呼叫者組織範圍內時直接回錯誤回應
     */
    private function findGroup(int $groupId): AgentGroup|JsonResponse
    {
        $group = AgentGroup::where('id', $groupId)
            ->where('agent_group_type', AgentGroupTypeEnum::EMAIL_GATEWAY->value)
            ->first();

        if (!$group) {
            return ApiResponse::error(__('error_message.email_gateway.group_not_email_type'));
        }

        $agent = $this->getAgentContext()->agent;
        if (!$agent->isPlatformAdmin() && (int) $group->organization_id !== (int) $agent->organization_id) {
            return ApiResponse::error(__('error_message.email_gateway.group_forbidden'));
        }

        return $group;
    }

    /**
     * 啟用中的語系（樣板可選語系清單）
     *
     * @return Collection<int, Language>
     */
    private function activeLanguages(): Collection
    {
        return Language::select('id', 'name', 'code')
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }
}
