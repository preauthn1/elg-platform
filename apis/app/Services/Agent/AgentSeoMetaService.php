<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\SeoPageEnum;
use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentDomainSeoMeta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SEO 元數據 工單4102
 *
 * 設定綁在域名身上（總代名下已登記的網域），每個頁面（SeoPageEnum）一列；同一總代線可將一個域名
 * 標為預設，該線其他沒設定的域名回退用它。前台取值順序：域名自己 → 該線預設 → 空字串。
 */
class AgentSeoMetaService
{
    /**
     * 某總代可設定 SEO 的域名下拉。
     *
     * 與代理列表「已登記網域清單」同一資料來源：只取總代自己名下、啟用中的一般域名（type=0，
     * 排除推廣短網址與遊戲商中轉），不查階層——下線代理自己名下的域名不開放各自設定，
     * 前台只吃該線預設回退。
     *
     * @return Collection<int, array{id: int, domain: string}>
     */
    public function domainOptions(int $generalAgentId): Collection
    {
        return AgentDomain::query()
            ->where('agent_id', $generalAgentId)
            ->where('status', 1)
            ->where('type', 0)
            ->orderBy('id')
            ->get(['id', 'domain'])
            ->map(fn (AgentDomain $domain) => [
                'id' => (int) $domain->id,
                'domain' => $domain->domain,
            ])
            ->values();
    }

    /**
     * 確認域名屬於該總代——擋下帶別條線的 agent_domain_id 進來讀寫別人的設定。
     *
     * @throws ApiException 域名不存在或不屬於該總代
     */
    public function assertDomainOwnedBy(int $agentDomainId, int $generalAgentId): AgentDomain
    {
        $domain = AgentDomain::query()
            ->where('id', $agentDomainId)
            ->where('agent_id', $generalAgentId)
            ->first();

        if (!$domain) {
            throw new ApiException('Domain does not belong to this agent');
        }

        return $domain;
    }

    /**
     * 某域名的設定（後台編輯用）。
     *
     * 依 SeoPageEnum::cases() 固定每頁一筆，沒設定過的頁面補空字串，前端不必處理缺頁。
     * is_default 為域名層級的旗標（存在每一列上），取任一列即可，沒列為 0。
     *
     * @return array{is_default: int, items: array<int, array{page: string, title: string, description: string, keywords: string}>}
     */
    public function list(int $agentDomainId): array
    {
        $rows = AgentDomainSeoMeta::query()
            ->where('agent_domain_id', $agentDomainId)
            ->get()
            ->keyBy('page');

        $items = array_map(function (SeoPageEnum $page) use ($rows) {
            /** @var AgentDomainSeoMeta|null $row */
            $row = $rows->get($page->value);

            return [
                'page' => $page->value,
                'title' => $row->title ?? '',
                'description' => $row->description ?? '',
                'keywords' => $row->keywords ?? '',
            ];
        }, SeoPageEnum::cases());

        return [
            'is_default' => (int) ($rows->first()?->is_default ?? 0),
            'items' => $items,
        ];
    }

    /**
     * 儲存某域名的設定。
     *
     * 每頁以 (agent_domain_id, page) upsert。設為預設時先取消同線其他域名的預設，
     * 確保同一總代線只有一個預設回退來源。
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function save(int $agentDomainId, int $generalAgentId, bool $isDefault, array $items, int $adminId): void
    {
        DB::transaction(function () use ($agentDomainId, $generalAgentId, $isDefault, $items, $adminId) {
            foreach ($items as $item) {
                AgentDomainSeoMeta::query()->updateOrCreate(
                    [
                        'agent_domain_id' => $agentDomainId,
                        'page' => (string) $item['page'],
                    ],
                    [
                        'general_agent_id' => $generalAgentId,
                        'title' => (string) ($item['title'] ?? ''),
                        'description' => (string) ($item['description'] ?? ''),
                        'keywords' => (string) ($item['keywords'] ?? ''),
                        'is_default' => (int) $isDefault,
                        'admin' => $adminId,
                    ],
                );
            }

            if ($isDefault) {
                // 同線只留一個預設：先取消其他域名，再把本域名所有頁面標為預設
                AgentDomainSeoMeta::query()
                    ->where('general_agent_id', $generalAgentId)
                    ->where('agent_domain_id', '!=', $agentDomainId)
                    ->where('is_default', 1)
                    ->update(['is_default' => 0, 'admin' => $adminId]);

                AgentDomainSeoMeta::query()
                    ->where('agent_domain_id', $agentDomainId)
                    ->update(['is_default' => 1]);
            } else {
                AgentDomainSeoMeta::query()
                    ->where('agent_domain_id', $agentDomainId)
                    ->update(['is_default' => 0]);
            }
        });
    }

    /**
     * 前台用：某域名應輸出的 SEO 元數據。
     *
     * 取值順序：域名自己的設定 → 該總代線的預設域名 → 空字串。只要域名自己有任一列就不回退，
     * 避免同一站點各頁混用兩個域名的文案。輸出固定每個 SeoPageEnum 一個 key，前台不必判斷缺 key。
     * 不加快取：template 接口本身即依域名查詢，此處為單表索引查詢。
     *
     * @return array<string, array{title: string, description: string, keywords: string}>
     */
    public function getFrontendMeta(AgentDomain $agentDomain): array
    {
        $rows = AgentDomainSeoMeta::query()
            ->where('agent_domain_id', $agentDomain->id)
            ->get(['page', 'title', 'description', 'keywords']);

        if ($rows->isEmpty()) {
            $generalAgentId = $agentDomain->agent?->general_agent_id;

            if ($generalAgentId) {
                $rows = AgentDomainSeoMeta::query()
                    ->where('general_agent_id', $generalAgentId)
                    ->where('is_default', 1)
                    ->get(['page', 'title', 'description', 'keywords']);
            }
        }

        $rows = $rows->keyBy('page');

        $result = [];

        foreach (SeoPageEnum::cases() as $page) {
            /** @var AgentDomainSeoMeta|null $row */
            $row = $rows->get($page->value);

            $result[$page->value] = [
                'title' => $row->title ?? '',
                'description' => $row->description ?? '',
                'keywords' => $row->keywords ?? '',
            ];
        }

        return $result;
    }
}
