<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayCreateRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayDeleteRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayDetailRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayGroupDropdownRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayListRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayRecordContentRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayRecordRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayUpdateRequest;
use App\Http\Requests\Agent\EmailGateway\EmailGatewayUpdateStatusRequest;
use App\Http\Resources\Agent\EmailGateway\EmailGatewayDetailResource;
use App\Http\Resources\Agent\EmailGateway\EmailGatewayGroupResource;
use App\Http\Resources\Agent\EmailGateway\EmailGatewayListResource;
use App\Http\Resources\Agent\EmailGateway\EmailGatewayRecordResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\AgentGroup;
use App\Models\EmailGateway;
use App\Models\EmailSendRecord;
use App\Services\Agent\AgentOperateLogService;
use App\Services\AgentService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

#[Group('email-gateway (電子郵件提供商)')]
class EmailGatewayController extends Controller
{
    public function __construct(
        private readonly AgentService $agentService,
        private readonly AgentOperateLogService $agentOperateLogService,
    ) {}

    public function list(EmailGatewayListRequest $request): JsonResponse
    {
        $data = EmailGateway::select(
            'email_gateways.id',
            'email_gateways.agent_group_id',
            'g.name AS agent_group_name',
            'email_gateways.email',
            'email_gateways.host',
            'email_gateways.port',
            'email_gateways.scheme',
            'email_gateways.from_name',
            'email_gateways.sort',
            'email_gateways.status',
            'email_gateways.updated_at',
            'updater.username AS updated_by',
        )
            ->leftJoin('agent_groups AS g', 'g.id', '=', 'email_gateways.agent_group_id')
            ->leftJoin('agents AS updater', 'updater.id', '=', 'email_gateways.admin')
            ->when($request->agent_group_id, function (Builder $query) use ($request) {
                $query->where('email_gateways.agent_group_id', $request->agent_group_id);
            })
            ->when($request->email, function (Builder $query) use ($request) {
                $query->where('email_gateways.email', 'like', $request->email . '%');
            })
            ->when(!is_null($request->status), function (Builder $query) use ($request) {
                $query->where('email_gateways.status', $request->status);
            })
            ->unless($this->isPlatformAdmin(), function (Builder $query) {
                $query->where('g.organization_id', $this->getAgentContext()->agent->organization_id);
            })
            ->orderBy('email_gateways.sort')
            ->orderBy('email_gateways.id')
            ->paginate($request->per_page);

        return ApiResponse::successWithPagination(
            data: EmailGatewayListResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 電子郵件設置組別下拉
     */
    public function groupDropdown(EmailGatewayGroupDropdownRequest $request): JsonResponse
    {
        $data = AgentGroup::select('id', 'name', 'organization_id')
            ->where('agent_group_type', AgentGroupTypeEnum::EMAIL_GATEWAY->value)
            ->unless($this->isPlatformAdmin(), function (Builder $query) {
                $query->where('organization_id', $this->getAgentContext()->agent->organization_id);
            })
            ->orderBy('id')
            ->get();

        return ApiResponse::success(EmailGatewayGroupResource::collection($data));
    }

    /**
     * 電子郵件發送紀錄
     */
    public function record(EmailGatewayRecordRequest $request): JsonResponse
    {
        $data = EmailSendRecord::select(
            'email_send_records.id',
            'email_send_records.created_at',
            'g.name AS agent_group_name',
            'email_send_records.sender',
            'a.username AS agent_username',
            'm.username AS member_username',
            'email_send_records.receiver',
            'email_send_records.subject',
            'email_send_records.message_content',
            'email_send_records.response_message',
            'email_send_records.status',
        )
            ->leftJoin('email_gateways AS eg', 'eg.id', '=', 'email_send_records.email_gateway_id')
            ->leftJoin('agent_groups AS g', 'g.id', '=', 'eg.agent_group_id')
            ->leftJoin('agents AS a', 'a.id', '=', 'email_send_records.agent_id')
            ->leftJoin('members AS m', 'm.id', '=', 'email_send_records.member_id')
            ->when($request->send_id, function (Builder $query) use ($request) {
                $query->where('email_send_records.id', $request->send_id);
            })
            ->when($request->agent_group_id, function (Builder $query) use ($request) {
                $query->where('eg.agent_group_id', $request->agent_group_id);
            })
            ->when($request->agent_username, function (Builder $query) use ($request) {
                $query->where('a.username', 'like', '%' . $request->agent_username . '%');
            })
            ->when($request->member_username, function (Builder $query) use ($request) {
                $query->where('m.username', 'like', '%' . $request->member_username . '%');
            })
            ->when($request->receiver, function (Builder $query) use ($request) {
                $query->where('email_send_records.receiver', 'like', $request->receiver . '%');
            })
            ->when(!is_null($request->status), function (Builder $query) use ($request) {
                $query->where('email_send_records.status', $request->status);
            })
            ->when($request->start_time && $request->end_time, function (Builder $query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_time . ':00')->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_time . ':59')->toSystemTime()->toDateTimeString();

                $query->whereBetween('email_send_records.created_at', [$startTime, $endTime]);
            })
            // 以代理的組織為界；用組別會把未指派組別的紀錄(email_gateway_id=0)整批濾掉
            ->unless($this->isPlatformAdmin(), function (Builder $query) {
                $query->where('a.organization_id', $this->getAgentContext()->agent->organization_id);
            })
            ->orderByDesc('email_send_records.id')
            ->paginate($request->per_page);

        if (!$this->isRecordContentVisible()) {
            $data->getCollection()->each(function (EmailSendRecord $record) {
                $record->message_content = '';
            });
        }

        return ApiResponse::successWithPagination(
            data: EmailGatewayRecordResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 查看發送紀錄的郵件內容，並寫入代理操作紀錄
     */
    public function recordContent(EmailGatewayRecordContentRequest $request): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;

        $record = EmailSendRecord::select(
            'email_send_records.id',
            'email_send_records.message_content',
        )
            ->leftJoin('agents AS a', 'a.id', '=', 'email_send_records.agent_id')
            ->where('email_send_records.id', $request->send_id)
            ->unless($this->isPlatformAdmin(), function (Builder $query) use ($agent) {
                $query->where('a.organization_id', $agent->organization_id);
            })
            ->first();

        if (!$record) {
            return ApiResponse::error(__('error_message.email_gateway.not_found'));
        }

        $this->agentOperateLogService->log(
            agentId: $agent->id,
            type: AgentOperateTypeEnum::VIEW_EMAIL_CONTENT,
            debugInfo: new PlainDebugInfo(''),
            ip: Common::getIp(),
            referenceId: (string) $record->id,
        );

        return ApiResponse::success(['message_content' => $record->message_content]);
    }

    public function detail(EmailGatewayDetailRequest $request): JsonResponse
    {
        $emailGateway = EmailGateway::find($request->id);

        if (!$emailGateway) {
            return ApiResponse::error(__('error_message.email_gateway.not_found'));
        }

        if (!$this->isGroupInScope($emailGateway->agent_group_id)) {
            return ApiResponse::error(__('error_message.email_gateway.group_forbidden'));
        }

        return ApiResponse::success(EmailGatewayDetailResource::make($emailGateway));
    }

    public function create(EmailGatewayCreateRequest $request): JsonResponse
    {
        $agentGroupId = (int) $request->agent_group_id;

        if (!$this->isEmailGatewayGroup($agentGroupId)) {
            return ApiResponse::error(__('error_message.email_gateway.group_not_email_type'));
        }

        if (!$this->isGroupInScope($agentGroupId)) {
            return ApiResponse::error(__('error_message.email_gateway.group_forbidden'));
        }

        if ($this->emailExists($agentGroupId, (string) $request->email)) {
            return ApiResponse::error(__('error_message.email_gateway.duplicate'));
        }

        return DB::transaction(function () use ($request, $agentGroupId) {
            EmailGateway::create([
                'agent_group_id' => $agentGroupId,
                'email' => $request->email,
                'password' => $request->password,
                'host' => $request->host,
                'port' => $request->port,
                'scheme' => $request->scheme,
                'from_name' => $request->from_name ?? '',
                'sort' => $request->sort ?? 0,
                'status' => $request->status,
                'admin' => $this->getAgentContext()->agent->id,
            ]);

            return ApiResponse::success();
        });
    }

    public function update(EmailGatewayUpdateRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $emailGateway = EmailGateway::where('id', $request->id)->lockForUpdate()->first();

            if (!$emailGateway) {
                return ApiResponse::error(__('error_message.email_gateway.not_found'));
            }

            if (!$this->isGroupInScope($emailGateway->agent_group_id)) {
                return ApiResponse::error(__('error_message.email_gateway.group_forbidden'));
            }

            if ($this->emailExists($emailGateway->agent_group_id, (string) $request->email, $emailGateway->id)) {
                return ApiResponse::error(__('error_message.email_gateway.duplicate'));
            }

            $updateData = [
                'email' => $request->email,
                'host' => $request->host,
                'port' => $request->port,
                'scheme' => $request->scheme,
                'from_name' => $request->from_name ?? '',
                'sort' => $request->sort ?? 0,
                'status' => $request->status,
                'admin' => $this->getAgentContext()->agent->id,
            ];

            // 留空表示不變更密碼
            if (!empty($request->password)) {
                $updateData['password'] = $request->password;
            }

            $emailGateway->update($updateData);

            return ApiResponse::success();
        });
    }

    public function updateStatus(EmailGatewayUpdateStatusRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $emailGateway = EmailGateway::where('id', $request->id)->lockForUpdate()->first();

            if (!$emailGateway) {
                return ApiResponse::error(__('error_message.email_gateway.not_found'));
            }

            if (!$this->isGroupInScope($emailGateway->agent_group_id)) {
                return ApiResponse::error(__('error_message.email_gateway.group_forbidden'));
            }

            $emailGateway->update([
                'status' => $request->status,
                'admin' => $this->getAgentContext()->agent->id,
            ]);

            return ApiResponse::success();
        });
    }

    public function delete(EmailGatewayDeleteRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $emailGateway = EmailGateway::where('id', $request->id)->lockForUpdate()->first();

            if (!$emailGateway) {
                return ApiResponse::error(__('error_message.email_gateway.not_found'));
            }

            if (!$this->isGroupInScope($emailGateway->agent_group_id)) {
                return ApiResponse::error(__('error_message.email_gateway.group_forbidden'));
            }

            $emailGateway->delete();

            return ApiResponse::success();
        });
    }

    /**
     * 同一組別下寄件信箱不得重複
     */
    private function emailExists(int $agentGroupId, string $email, ?int $exceptId = null): bool
    {
        return EmailGateway::where('agent_group_id', $agentGroupId)
            ->where('email', $email)
            ->when($exceptId, function (Builder $query, int $exceptId) {
                $query->whereNot('id', $exceptId);
            })
            ->exists();
    }

    /**
     * 呼叫者是否為平台端（admin 及其子帳號），不受組織限制
     */
    private function isPlatformAdmin(): bool
    {
        return $this->getAgentContext()->agent->isPlatformAdmin();
    }

    /**
     * 目標組別是否為電子郵件設置組別
     */
    private function isEmailGatewayGroup(int $agentGroupId): bool
    {
        return AgentGroup::where('id', $agentGroupId)
            ->where('agent_group_type', AgentGroupTypeEnum::EMAIL_GATEWAY->value)
            ->exists();
    }

    /**
     * 目標組別是否在可操作範圍內（以組織為界，平台端不受限）
     */
    private function isGroupInScope(int $agentGroupId): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return AgentGroup::where('id', $agentGroupId)
            ->where('organization_id', $this->getAgentContext()->agent->organization_id)
            ->exists();
    }

    /**
     * 發送紀錄是否直接顯示郵件內容，未設定時預設顯示
     */
    private function isRecordContentVisible(): bool
    {
        $setting = $this->agentService->getSpecificAgentSetting(
            $this->getAgentContext()->agent,
            AgentCustomizedSettingKeyEnum::EMAIL_RECORD_VERIFICATION_CODE_VISIBLE
        );

        return is_null($setting) || (int) $setting === 1;
    }
}
