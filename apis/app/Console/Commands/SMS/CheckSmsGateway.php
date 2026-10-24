<?php

declare(strict_types=1);

namespace App\Console\Commands\SMS;

use App\Models\SmsGatewayInfo;
use Illuminate\Console\Command;

/**
 * 從手機號碼查詢可用的 SMS 供應商清單
 *
 * php artisan sms:check-gateway
 * php artisan sms:check-gateway 66970783128
 * php artisan sms:check-gateway 60164580966 --group=1
 *
 * 資料表關聯：
 *   sms_gateway_infos     ← 簡訊商商戶配置 (含 sms_gateway_group_id)
 *   sms_gateways          ← 三方簡訊商 (name, code, status)
 *   sms_gateway_info_countries ← pivot
 *   countries             ← 電話冠碼 (country_code)
 */
class CheckSmsGateway extends Command
{
    protected $signature = 'sms:check-gateway
                            {mobile? : 手機號碼（含國碼，例如 60164580966）}
                            {--group= : 指定 sms_gateway_group_id，不指定則查全部群組}';

    protected $description = '從手機號碼查詢可用的 SMS 供應商';

    public function handle(): void
    {
        $mobile = $this->argument('mobile') ?? $this->ask('請輸入手機號碼（含國碼）', '60164580966');
        $groupId = $this->option('group');

        $this->line('');
        $this->info(sprintf('🔍 查詢手機號碼：%s%s', $mobile, $groupId ? "（群組 {$groupId}）" : '（全部群組）'));
        $this->line('');

        $smsGatewayInfos = $this->queryMatchingGateways($mobile, $groupId ? (int) $groupId : null);

        if ($smsGatewayInfos->isEmpty()) {
            $this->error('查無符合的 SMS 供應商');
            $this->line('');
            $this->showDiagnosticHint($mobile, $groupId);

            return;
        }

        $this->renderResultTable($smsGatewayInfos);
        $this->showRoundRobinPreview($smsGatewayInfos);
        $this->showDiagnosticHint($mobile, $groupId);
    }

    /**
     * 查詢符合手機號碼前綴的簡訊商配置
     *
     * 條件與 AbstractValidationCodeService::getSmsGatewayInfo() 一致：
     * - sms_gateway_infos.status = 1
     * - sms_gateways.status = 1
     * - countries.status = 1，且 mobile LIKE concat(country_code, '%')
     */
    private function queryMatchingGateways(string $mobile, ?int $groupId): \Illuminate\Database\Eloquent\Collection
    {
        return SmsGatewayInfo::with(['smsGateway', 'countries'])
            ->where('sms_gateway_infos.status', 1)
            ->when($groupId !== null, fn ($q) => $q->where('sms_gateway_infos.sms_gateway_group_id', $groupId))
            ->whereHas('smsGateway', fn ($q) => $q->where('sms_gateways.status', 1))
            ->whereHas('countries', fn ($q) => $q
                ->where('countries.status', 1)
                ->whereRaw('? like concat(countries.country_code, ?)', [$mobile, '%'])
            )
            ->orderBy('sms_gateway_infos.sms_gateway_group_id')
            ->orderByDesc('sms_gateway_infos.sort')
            ->get();
    }

    /**
     * 輸出匹配結果表格
     */
    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, SmsGatewayInfo>  $smsGatewayInfos
     */
    private function renderResultTable(\Illuminate\Database\Eloquent\Collection $smsGatewayInfos): void
    {
        $rows = $smsGatewayInfos->map(function (SmsGatewayInfo $info, int $index) {
            $matchedCountry = $info->countries->first();

            return [
                $index + 1,
                $info->id,
                $info->sms_gateway_group_id,
                $info->smsGateway?->name ?? '-',
                $info->smsGateway?->code ?? '-',
                $info->sort,
                $matchedCountry?->country_code ?? '-',
                $matchedCountry?->name ?? '-',
            ];
        })->toArray();

        $this->table(
            ['#', 'Info ID', '群組 ID', '簡訊商名稱', 'Code', 'Sort', '國碼', '國家'],
            $rows,
        );
    }

    /**
     * 模擬輪詢選擇結果（$times = 1..3）
     */
    private function showRoundRobinPreview(\Illuminate\Database\Eloquent\Collection $smsGatewayInfos): void
    {
        $count = $smsGatewayInfos->count();

        if ($count <= 1) {
            return;
        }

        $this->line('📊 輪詢模擬（同群組內依 sort 排序後 round-robin）：');

        $preview = collect(range(1, min($count * 2, 6)))->map(function (int $times) use ($smsGatewayInfos, $count) {
            $index = ($times - 1) % $count;
            $selected = $smsGatewayInfos->get($index);

            return [
                "第 {$times} 次",
                "index {$index}",
                $selected->id,
                $selected->smsGateway?->code ?? '-',
            ];
        })->toArray();

        $this->table(['發送次數', '陣列索引', 'Info ID', 'Code'], $preview);
    }

    /**
     * 顯示 SP 診斷提示
     */
    private function showDiagnosticHint(string $mobile, mixed $groupId): void
    {
        $this->line('💡 SP 診斷指令：');

        if ($groupId) {
            $this->line(sprintf(
                "   call ADMIN_check_sms_gateway('%s', %s, 0);",
                $mobile,
                $groupId,
            ));
        } else {
            $this->warn('   未指定群組，無法生成 SP 診斷指令。請加上 --group=<ID>');
        }

        $this->line('');
    }
}
