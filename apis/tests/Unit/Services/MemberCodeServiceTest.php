<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MemberCode;
use App\Services\MemberCodeService;
use Tests\TestCase;

/**
 * MemberCode 集中入口契約測試
 *
 * php artisan test tests/Unit/Services/MemberCodeServiceTest.php
 */
class MemberCodeServiceTest extends TestCase
{
    private function makeService(): MemberCodeService
    {
        return new MemberCodeService;
    }

    /**
     * 會員尚無 member_code → get() 建立一筆並回傳
     */
    public function test_get_creates_single_code_when_none_exists(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $code = $this->makeService()->get($member);

        $this->assertSame($member->id, $code->member_id);
        $this->assertNotEmpty($code->agent_code);
        $this->assertSame(1, MemberCode::where('member_id', $member->id)->count());
    }

    /**
     * 會員已有 member_code → get() 回傳既有那筆、不重複建立
     */
    public function test_get_returns_existing_without_duplicating(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $existing = $this->createMemberCode($member);

        $code = $this->makeService()->get($member);

        $this->assertSame($existing->id, $code->id);
        $this->assertSame($existing->agent_code, $code->agent_code);
        $this->assertSame(1, MemberCode::where('member_id', $member->id)->count());
    }

    /**
     * 連續多次 get() 同一會員 → 只會有一筆 member_code（順序契約，bug 回歸）
     */
    public function test_repeated_get_does_not_create_duplicates(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $service = $this->makeService();
        $first = $service->get($member);
        $second = $service->get($member);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MemberCode::where('member_id', $member->id)->count());
    }

    /**
     * makeMemberAgentCode 生成的碼帶設定前綴
     */
    public function test_make_member_agent_code_has_configured_prefix(): void
    {
        $prefix = (string) config('custom.unlimit_prefix');
        $code = $this->makeService()->makeMemberAgentCode();

        $this->assertStringStartsWith(strtoupper($prefix), $code);
        $this->assertGreaterThan(5, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $code);
    }
}
