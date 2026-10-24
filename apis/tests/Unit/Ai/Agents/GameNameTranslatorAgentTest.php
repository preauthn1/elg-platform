<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\GameNameTranslatorAgent;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\StructuredAgentResponse;
use ReflectionClass;
use Tests\TestCase;

class GameNameTranslatorAgentTest extends TestCase
{
    public function test_implements_required_interfaces(): void
    {
        $agent = new GameNameTranslatorAgent;

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertInstanceOf(HasStructuredOutput::class, $agent);
    }

    public function test_has_use_cheapest_model_attribute(): void
    {
        $reflection = new ReflectionClass(GameNameTranslatorAgent::class);
        $attributes = $reflection->getAttributes(UseCheapestModel::class);

        $this->assertNotEmpty($attributes, 'GameNameTranslatorAgent must have #[UseCheapestModel] attribute');
    }

    public function test_fake_returns_structured_response(): void
    {
        GameNameTranslatorAgent::fake([
            [
                'translations' => [
                    ['original' => 'Triple Twister', 'translated' => '三重龍捲風'],
                    ['original' => 'Girls', 'translated' => '少女'],
                ],
            ],
        ]);

        $response = GameNameTranslatorAgent::make()
            ->prompt('Translate these game names to 繁體中文: ["Triple Twister", "Girls"]');

        $this->assertInstanceOf(StructuredAgentResponse::class, $response);
        $this->assertCount(2, $response['translations']);
        $this->assertSame('Triple Twister', $response['translations'][0]['original']);
        $this->assertSame('三重龍捲風', $response['translations'][0]['translated']);
    }
}
