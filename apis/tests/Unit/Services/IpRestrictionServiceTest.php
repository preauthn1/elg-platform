<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\IpRestriction;
use App\Services\Agent\IpRestrictionService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 風控IP限制後台 CRUD service（4096）。
 *
 * php artisan test tests/Unit/Services/IpRestrictionServiceTest.php
 */
class IpRestrictionServiceTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->createAgentAndDomain();
    }

    private function service(): IpRestrictionService
    {
        return app(IpRestrictionService::class);
    }

    #[TestDox('create：IPv6 以 canonical form 落庫、admin 為操作者')]
    public function test_create_stores_canonical_ip(): void
    {
        $rule = $this->service()->create($this->agent, [
            'ip' => '2001:DB8:0000::1',
            'block_items' => ['login'],
            'remark' => 'r',
            'status' => 1,
        ]);

        $this->assertSame('2001:db8::1', $rule->ip);
        $this->assertSame($this->agent->id, (int) $rule->admin);
        $this->assertSame(0, (int) $rule->repeat_count);
    }

    #[TestDox('create：同 IP（含變體）已存在拋 duplicate')]
    public function test_create_duplicate_throws(): void
    {
        $this->service()->create($this->agent, ['ip' => '2001:db8::1', 'block_items' => ['login'], 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.ip_restriction.duplicate'));

        $this->service()->create($this->agent, ['ip' => '2001:DB8:0:0:0:0:0:1', 'block_items' => ['login'], 'status' => 1]);
    }

    #[TestDox('update：可改 ip/remark/status，admin 換為操作者')]
    public function test_update_changes_fields(): void
    {
        $rule = $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);

        $other = $this->createAgentAndDomain2();
        $updated = $this->service()->update($other, $rule->id, [
            'ip' => '0.0.0.0',
            'block_items' => ['login'],
            'remark' => 'fixed typo',
            'status' => 0,
        ]);

        $this->assertSame('0.0.0.0', $updated->ip);
        $this->assertSame('fixed typo', $updated->remark);
        $this->assertSame(0, (int) $updated->status);
        $this->assertSame($other->id, (int) $updated->admin);
    }

    #[TestDox('update：改回自己原 IP 不算重複（self-exclusion）')]
    public function test_update_self_ip_allowed(): void
    {
        $rule = $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);

        $updated = $this->service()->update($this->agent, $rule->id, [
            'ip' => '0.0.0.0',
            'block_items' => ['login'],
            'remark' => 'only remark changed',
            'status' => 1,
        ]);

        $this->assertSame('only remark changed', $updated->remark);
    }

    #[TestDox('update：改成既存他筆 IP 拋 duplicate')]
    public function test_update_to_existing_ip_throws(): void
    {
        $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);
        $rule = $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.ip_restriction.duplicate'));

        $this->service()->update($this->agent, $rule->id, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);
    }

    #[TestDox('update：不存在的 id 拋 1010')]
    public function test_update_missing_throws(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1010'));

        $this->service()->update($this->agent, 999999, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);
    }

    #[TestDox('update：repeat_count 不被覆寫')]
    public function test_update_preserves_repeat_count(): void
    {
        $rule = $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);
        IpRestriction::query()->toBase()->where('id', $rule->id)->update(['repeat_count' => 5]);

        $this->service()->update($this->agent, $rule->id, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);

        $this->assertSame(5, (int) $rule->fresh()->repeat_count);
    }

    #[TestDox('find：查無拋 1010')]
    public function test_find_missing_throws(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1010'));

        $this->service()->find(999999);
    }

    #[TestDox('paginate：ip 關鍵字／status 過濾、id 倒序')]
    public function test_paginate_filters_and_order(): void
    {
        $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);
        $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 0]);
        $this->service()->create($this->agent, ['ip' => '0.0.0.0', 'block_items' => ['login'], 'status' => 1]);

        $all = $this->service()->paginate(['per_page' => 20]);
        $this->assertSame(3, $all->total());
        // id 倒序：最後建立的排最前
        $this->assertSame('0.0.0.0', $all->items()[0]->ip);

        $byIp = $this->service()->paginate(['per_page' => 20, 'ip' => '1.2.']);
        $this->assertSame(2, $byIp->total());

        $byStatus = $this->service()->paginate(['per_page' => 20, 'status' => 0]);
        $this->assertSame(1, $byStatus->total());
        $this->assertSame('0.0.0.0', $byStatus->items()[0]->ip);
    }

    /** 第二個代理（換 domain 避免 createAgentAndDomain 撞 unique） */
    private function createAgentAndDomain2(): Agent
    {
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';

        return $this->createAgentAndDomain();
    }
}
