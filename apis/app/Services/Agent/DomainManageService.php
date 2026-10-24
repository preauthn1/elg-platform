<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\DomainTypeEnum;
use App\Models\Domain;
use App\Models\DomainAgent;
use App\Models\DomainType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 域名管理（4035）：系統管理 → 域名管理。
 *
 * 管理 `domains`（後台系統自身的網域，如代理後台 / 客服後台網址），
 * 與 `agent_domains`（玩家站台網域）為不同資料，勿混用。
 */
class DomainManageService
{
    /** 可搜尋欄位（模糊）*/
    private const LIKE_FIELDS = ['domain', 'domain_name'];

    /**
     * `domain_agents.domain_type_info_id` 固定值。
     *
     * 該欄位為 NOT NULL 外鍵，但 `domain_type_infos` 的內容（logo/title 等）
     * 全庫無任何程式讀取，僅作為 `api/domain/info` 的 join 條件；
     * 現有資料（含客服後台）一律為 1，故新增時沿用。
     */
    private const DEFAULT_DOMAIN_TYPE_INFO_ID = 1;

    /** 本功能管理的類型：1 代理後台、3 客服後台（2 前台屬玩家站台，不在此管理）*/
    public const ALLOWED_TYPE_IDS = [
        DomainTypeEnum::AGENT_BACKEND->value,
        DomainTypeEnum::CS_BACKEND->value,
    ];

    /**
     * 列表 + 搜尋（域名 / 名稱模糊、類型與狀態精確）。
     *
     * @param  array{domain?:string, domain_name?:string, domain_type_id?:int|string, status?:int|string, page?:int, per_page?:int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Domain::query()->with(['type', 'agents.agent:id,username']);

        foreach (self::LIKE_FIELDS as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, 'like', '%' . $value . '%');
            }
        }

        if (isset($filters['domain_type_id']) && $filters['domain_type_id'] !== '') {
            $query->where('domain_type_id', (int) $filters['domain_type_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query
            ->orderByDesc('sort')
            ->orderBy('id')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 20),
                page: (int) ($filters['page'] ?? 1),
            );
    }

    /**
     * 類型下拉（名稱以中文 memo 呈現）。
     *
     * @return Collection<int, array{id:int, name:string, needs_dark_logo:bool, needs_theme_color:bool}>
     */
    public function typeOptions(): Collection
    {
        return DomainType::query()
            ->where('status', 1)
            ->whereIn('id', self::ALLOWED_TYPE_IDS)
            ->orderBy('id')
            ->get(['id', 'name', 'memo'])
            ->map(fn (DomainType $type) => [
                'id' => (int) $type->id,
                'name' => (string) ($type->memo ?: $type->name),
                // 前端據此決定顯示雙 LOGO（代理後台）或單一 LOGO（客服後台）
                'needs_dark_logo' => DomainTypeEnum::from((int) $type->id)->needsDarkLogo(),
                // 色系僅客服後台使用
                'needs_theme_color' => DomainTypeEnum::from((int) $type->id)->needsThemeColor(),
            ]);
    }

    public function find(int $id): ?Domain
    {
        return Domain::query()->with(['type', 'agents'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $adminId): Domain
    {
        return DB::transaction(function () use ($data, $adminId) {
            $domain = Domain::create($this->fill($data) + ['admin' => $adminId]);
            $this->syncAgent($domain, (int) $data['agent_id'], $adminId);

            return $domain;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Domain $domain, array $data, int $adminId): Domain
    {
        return DB::transaction(function () use ($domain, $data, $adminId) {
            $domain->update($this->fill($data) + ['admin' => $adminId]);

            if (array_key_exists('agent_id', $data)) {
                $this->syncAgent($domain, (int) $data['agent_id'], $adminId);
            }

            return $domain->refresh();
        });
    }

    /**
     * 綁定域名所屬代理（一域名對一代理）。
     *
     * `domain_agents` 是後台依域名取得設定的必要關聯：代理後台 `api/domain/info`
     * 與客服後台 `api/agentSetting/view` 皆需 join 此表，未綁定則整支查無。
     * 改綁時覆蓋舊關聯，避免殘留多筆造成 join 撈出重複資料。
     */
    private function syncAgent(Domain $domain, int $agentId, int $adminId): void
    {
        DomainAgent::query()->where('domain_id', $domain->id)->delete();

        DomainAgent::create([
            'domain_id' => $domain->id,
            'agent_id' => $agentId,
            // 版型資訊無實質設定用途，僅為 join 條件；沿用現有資料一律為 1
            'domain_type_info_id' => self::DEFAULT_DOMAIN_TYPE_INFO_ID,
            'status' => 1,
            'admin' => $adminId,
        ]);
    }

    /**
     * 只取本功能負責的欄位，避免請求夾帶其他欄位被寫入（Model 為 $guarded 白名單外全開）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fill(array $data): array
    {
        $fields = ['domain', 'domain_type_id', 'domain_name', 'logo_lm', 'logo_dm', 'icon', 'theme_color', 'sort', 'status'];
        $filled = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $filled[$field] = $data[$field];
            }
        }

        $type = isset($filled['domain_type_id'])
            ? DomainTypeEnum::from((int) $filled['domain_type_id'])
            : null;

        if ($type !== null) {
            // 客服後台只有單一 LOGO（存 logo_lm），深色欄位一律清空避免殘留
            if (!$type->needsDarkLogo()) {
                $filled['logo_dm'] = null;
            }
            // 色系僅客服後台使用，代理後台一律清空
            if (!$type->needsThemeColor()) {
                $filled['theme_color'] = null;
            }
        }

        return $filled;
    }
}
