<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThirdPartyAgentTransactionTest extends TestCase
{
    private Agent $fromAgent;

    private Agent $toAgent;

    const API_AGENT_TRANSACTION = '/frontend/api/third-party/agent-transaction';

    const API_NEW_AGENT_TRANSACTION = '/frontend/api/third-party/new-agent-transaction';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fromAgent = $this->createAgentAndDomain(['balance' => 1000, 'is_balance_unlimited' => 0]);
        $this->domain = Str::random(12) . '.test.local';
        $this->toAgent = $this->createAgentAndDomain(['balance' => 500, 'is_balance_unlimited' => 0]);
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);
    }

    public function test_agent_transaction_success_does_not_change_balance()
    {
        $response = $this->requestApi(self::API_AGENT_TRANSACTION, [
            'from' => $this->fromAgent->username,
            'to' => $this->toAgent->username,
            'type' => 1,
            'amount' => 100,
            'from_limit_of_credit' => 900,
            'from_used_credit' => 0,
            'to_limit_of_credit' => 600,
            'to_used_credit' => 0,
            'status' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('transaction_log_agents', [
            'from_agent_id' => $this->fromAgent->id,
            'to_agent_id' => $this->toAgent->id,
            'type' => 1,
            'amount' => 100,
            'from_limit_of_credit' => 900,
            'to_limit_of_credit' => 600,
        ]);

        $this->fromAgent->refresh();
        $this->toAgent->refresh();
        $this->assertEquals(1000, $this->fromAgent->balance);
        $this->assertEquals(500, $this->toAgent->balance);
    }

    public function test_agent_transaction_failed_agent_not_found()
    {
        $response = $this->requestApi(self::API_AGENT_TRANSACTION, [
            'from' => $this->fromAgent->username,
            'to' => 'no-such-agent',
            'type' => 1,
            'amount' => 100,
            'from_limit_of_credit' => 900,
            'from_used_credit' => 0,
            'to_limit_of_credit' => 600,
            'to_used_credit' => 0,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1005')]);
    }

    public function test_new_agent_transaction_type_1_deducts_from_and_credits_to()
    {
        $response = $this->requestApi(self::API_NEW_AGENT_TRANSACTION, [
            'from' => $this->fromAgent->username,
            'to' => $this->toAgent->username,
            'type' => 1,
            'amount' => 100,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->fromAgent->refresh();
        $this->toAgent->refresh();
        $this->assertEquals(900, $this->fromAgent->balance);
        $this->assertEquals(600, $this->toAgent->balance);

        $this->assertDatabaseHas('transaction_log_agents', [
            'from_agent_id' => $this->fromAgent->id,
            'to_agent_id' => $this->toAgent->id,
            'type' => 1,
            'amount' => 100,
            'from_limit_of_credit' => 900,
            'to_limit_of_credit' => 600,
        ]);
    }

    public function test_new_agent_transaction_type_2_reverses_direction()
    {
        $response = $this->requestApi(self::API_NEW_AGENT_TRANSACTION, [
            'from' => $this->fromAgent->username,
            'to' => $this->toAgent->username,
            'type' => 2,
            'amount' => 100,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->fromAgent->refresh();
        $this->toAgent->refresh();
        $this->assertEquals(1100, $this->fromAgent->balance);
        $this->assertEquals(400, $this->toAgent->balance);
    }

    public function test_new_agent_transaction_failed_from_agent_insufficient_balance()
    {
        $response = $this->requestApi(self::API_NEW_AGENT_TRANSACTION, [
            'from' => $this->fromAgent->username,
            'to' => $this->toAgent->username,
            'type' => 1,
            'amount' => 5000,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1022')]);

        $this->fromAgent->refresh();
        $this->toAgent->refresh();
        $this->assertEquals(1000, $this->fromAgent->balance);
        $this->assertEquals(500, $this->toAgent->balance);
    }

    public function test_new_agent_transaction_skips_balance_check_when_from_agent_unlimited()
    {
        $this->fromAgent->is_balance_unlimited = 1;
        $this->fromAgent->save();

        $response = $this->requestApi(self::API_NEW_AGENT_TRANSACTION, [
            'from' => $this->fromAgent->username,
            'to' => $this->toAgent->username,
            'type' => 1,
            'amount' => 5000,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->fromAgent->refresh();
        $this->toAgent->refresh();
        // 無限額代理完全略過扣款呼叫，餘額維持不變
        $this->assertEquals(1000, $this->fromAgent->balance);
        $this->assertEquals(5500, $this->toAgent->balance);
    }

    public function test_new_agent_transaction_failed_agent_not_found()
    {
        $response = $this->requestApi(self::API_NEW_AGENT_TRANSACTION, [
            'from' => 'no-such-agent',
            'to' => $this->toAgent->username,
            'type' => 1,
            'amount' => 100,
        ]);

        $response->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value)
            ->assertJson(['message' => __('error_message.1005')]);
    }
}
