<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 代理後台的請求稽核紀錄：誰、從哪個 IP、打了哪支接口、帶了什麼參數。後台沒有頁面讀它，供事後追查。
 *
 * API_Agent 由 AgentOperationLogsMiddleware 對每支接口自動寫入；接口搬進 apis 後改由
 * {@see \App\Http\Middleware\Agent\LogAgentOperation} 寫，避免搬家後紀錄斷掉（target/tickets#4422）。
 *
 * @property int $id 系統編號
 * @property int $operator_id 操作者 agents.id
 * @property string $project_name 專案名稱
 * @property string $page_path 接口路徑
 * @property string $request_method HTTP Method
 * @property string $ip 操作者 IP
 * @property array<mixed> $content 請求參數
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AgentOperateGeneralLog newModelQuery()
 * @method static Builder<static>|AgentOperateGeneralLog newQuery()
 * @method static Builder<static>|AgentOperateGeneralLog query()
 *
 * @mixin \Eloquent
 */
class AgentOperateGeneralLog extends Model
{
    protected $table = 'agent_operate_general_logs';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'content' => 'array',
    ];
}
