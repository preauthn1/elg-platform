<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrSettlement;

use App\Models\GgrPlan;
use App\Models\Member;
use App\Services\Agent\GgrSettlement\MemberEligibilityFilter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 4121 會員資格篩選：帳戶驗證限制（kyc_*，唯一性）。
 *
 * 規則來源＝2026-09-03 裁決（工單 #4121 未載明）：唯一性只在該代理的直屬
 * 下線之間比對、次數 0＝不限制。
 */
class MemberEligibilityTest extends TestCase
{
    private MemberEligibilityFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = app(MemberEligibilityFilter::class);
    }

    public function test_次數0時不做任何篩選(): void
    {
        // 兩人同 IP 又同信箱，但兩個次數都是 0＝不限制
        $a = $this->makeMember(['register_ip' => 3232235777, 'email' => 'user@example.invalid']);
        $b = $this->makeMember(['register_ip' => 3232235777, 'email' => 'user@example.invalid']);

        $plan = $this->makePlan([
            'kyc_unique_ip' => 1,
            'kyc_unique_email' => 1,
            'kyc_violate_count' => 0,
        ]);

        $result = $this->filter->filter($plan, [$a->id, $b->id]);

        $this->assertSame([$a->id, $b->id], $result['member_ids']);
        $this->assertSame(0, $result['kyc_excluded']);
    }

    public function test_撞值雙方都算違規並排除未撞值者保留(): void
    {
        $dup1 = $this->makeMember(['register_ip' => 3232235777]);
        $dup2 = $this->makeMember(['register_ip' => 3232235777]);
        $clean = $this->makeMember(['register_ip' => 3232235778]);

        $plan = $this->makePlan(['kyc_unique_ip' => 1, 'kyc_violate_count' => 1]);

        $result = $this->filter->filter($plan, [$dup1->id, $dup2->id, $clean->id]);

        $this->assertSame([$clean->id], $result['member_ids']);
        $this->assertSame(2, $result['kyc_excluded']);
    }

    public function test_違規項數未達門檻不排除(): void
    {
        // 只撞 IP 一項，門檻要求 2 項
        $a = $this->makeMember(['register_ip' => 3232235777, 'email' => 'user@example.invalid']);
        $b = $this->makeMember(['register_ip' => 3232235777, 'email' => 'user@example.invalid']);

        $plan = $this->makePlan([
            'kyc_unique_ip' => 1,
            'kyc_unique_email' => 1,
            'kyc_violate_count' => 2,
        ]);

        $result = $this->filter->filter($plan, [$a->id, $b->id]);

        $this->assertSame([$a->id, $b->id], $result['member_ids']);
    }

    public function test_撞滿兩項達門檻即排除(): void
    {
        $a = $this->makeMember(['register_ip' => 3232235777, 'email' => 'user@example.invalid']);
        $b = $this->makeMember(['register_ip' => 3232235777, 'email' => 'user@example.invalid']);

        $plan = $this->makePlan([
            'kyc_unique_ip' => 1,
            'kyc_unique_email' => 1,
            'kyc_violate_count' => 2,
        ]);

        $result = $this->filter->filter($plan, [$a->id, $b->id]);

        $this->assertSame([], $result['member_ids']);
        $this->assertSame(2, $result['kyc_excluded']);
    }

    public function test_空值不算撞值(): void
    {
        // 兩人 mobile 皆未填、姓名皆空：未填不構成重複
        $a = $this->makeMember(['mobile' => null, 'first_name' => null, 'last_name' => null]);
        $b = $this->makeMember(['mobile' => '', 'first_name' => '', 'last_name' => '']);
        $c = $this->makeMember(['register_ip' => 0]);
        $d = $this->makeMember(['register_ip' => null]);

        $plan = $this->makePlan([
            'kyc_unique_contact_no' => 1,
            'kyc_unique_name' => 1,
            'kyc_unique_ip' => 1,
            'kyc_violate_count' => 1,
        ]);

        $result = $this->filter->filter($plan, [$a->id, $b->id, $c->id, $d->id]);

        $this->assertSame([$a->id, $b->id, $c->id, $d->id], $result['member_ids']);
    }

    public function test_姓名以first與last合併比對(): void
    {
        $a = $this->makeMember(['first_name' => 'Wang', 'last_name' => 'Xiaoming']);
        $b = $this->makeMember(['first_name' => 'Wang', 'last_name' => 'Xiaoming']);
        $c = $this->makeMember(['first_name' => 'Wang', 'last_name' => 'Dahua']);

        $plan = $this->makePlan(['kyc_unique_name' => 1, 'kyc_violate_count' => 1]);

        $result = $this->filter->filter($plan, [$a->id, $b->id, $c->id]);

        $this->assertSame([$c->id], $result['member_ids']);
    }

    public function test_銀行帳號撞值以啟用中的帳號為限(): void
    {
        $a = $this->makeMember();
        $b = $this->makeMember();
        $c = $this->makeMember();

        $this->makeBankAccount($a->id, '9001', 1);
        $this->makeBankAccount($b->id, '9001', 1);
        // c 撞同一組號碼但已停用，不納入比對
        $this->makeBankAccount($c->id, '9001', 0);

        $plan = $this->makePlan(['kyc_unique_bank_account' => 1, 'kyc_violate_count' => 1]);

        $result = $this->filter->filter($plan, [$a->id, $b->id, $c->id]);

        $this->assertSame([$c->id], $result['member_ids']);
    }

    public function test_同一會員多筆銀行帳號同項只記一次違規(): void
    {
        $a = $this->makeMember();
        $b = $this->makeMember();

        // a 兩筆帳號都與 b 撞：仍只算 1 項違規，門檻 2 時不該被排除
        $this->makeBankAccount($a->id, '9001', 1);
        $this->makeBankAccount($a->id, '9002', 1);
        $this->makeBankAccount($b->id, '9001', 1);
        $this->makeBankAccount($b->id, '9002', 1);

        $plan = $this->makePlan([
            'kyc_unique_bank_account' => 1,
            'kyc_unique_ip' => 1,
            'kyc_violate_count' => 2,
        ]);

        $result = $this->filter->filter($plan, [$a->id, $b->id]);

        $this->assertSame([$a->id, $b->id], $result['member_ids']);
    }

    public function test_開關全關時次數不生效(): void
    {
        // 次數雖 >0，但該區沒開任何項目（前端不可能送出，後端仍須安全）
        $a = $this->makeMember(['register_ip' => 3232235777]);
        $b = $this->makeMember(['register_ip' => 3232235777]);

        $plan = $this->makePlan(['kyc_violate_count' => 1]);

        $result = $this->filter->filter($plan, [$a->id, $b->id]);

        $this->assertSame([$a->id, $b->id], $result['member_ids']);
    }

    public function test_空名單直接回空(): void
    {
        $plan = $this->makePlan(['kyc_unique_ip' => 1, 'kyc_violate_count' => 1]);

        $result = $this->filter->filter($plan, []);

        $this->assertSame([], $result['member_ids']);
    }

    /** @param array<string, mixed> $overrides */
    private function makeMember(array $overrides = []): Member
    {
        return Member::factory()->create(array_merge([
            'username' => 'target' . bin2hex(random_bytes(7)),
            // 本檔只驗 filter，掛在哪個代理下不影響判定（直屬過濾在 SettlementRunner）
            'parent' => 0,
        ], $overrides));
    }

    private function makeBankAccount(int $memberId, string $accountNo, int $status): void
    {
        DB::table('member_bank_accounts')->insert([
            'member_id' => $memberId,
            'bank_id' => 1,
            'account_name' => 'holder',
            'account_no' => $accountNo,
            'status' => $status,
            'sort' => 0,
            'operator_cs' => 0,
        ]);
    }

    /**
     * plan 不落 DB：filter 只讀屬性，避免拉整條 general_agent／group fixture。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makePlan(array $overrides = []): GgrPlan
    {
        return new GgrPlan(array_merge([
            'kyc_unique_bank_account' => 0,
            'kyc_unique_contact_no' => 0,
            'kyc_unique_email' => 0,
            'kyc_unique_ip' => 0,
            'kyc_unique_name' => 0,
            'kyc_violate_count' => 0,
        ], $overrides));
    }
}
