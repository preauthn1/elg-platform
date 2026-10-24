<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor;

use App\Ai\Agents\GameNameTranslatorAgent;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Language;
use Database\Seeders\LanguageSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/Vendor/TranslateGameNamesTest.php
 */
class TranslateGameNamesTest extends TestCase
{
    private int $vendorId = 233;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);

        // Fake agent — pass a Closure directly so it's called on every invocation (per-language)
        GameNameTranslatorAgent::fake(
            fn (string $prompt) => [
                'translations' => collect(json_decode(
                    substr($prompt, strpos($prompt, '['))
                ))->map(fn ($name) => [
                    'original' => $name,
                    'translated' => '【翻譯】' . $name,
                ])->values()->all(),
            ]
        );
    }

    public function test_translates_missing_game_names_for_all_languages(): void
    {
        $game = Game::create([
            'vendor_id' => $this->vendorId,
            'type_id' => 1,
            'game_type' => 'test_game_' . uniqid(),
            'name' => 'Triple Twister',
            'status' => 1,
            'admin' => 0,
        ]);

        $languageCount = Language::where('id', '!=', 1)->count();

        $this->artisan('vendor:translate-game-names', ['vendorId' => $this->vendorId])
            ->assertSuccessful();

        // 每個非英文語言都應寫入一筆 game_names
        $this->assertSame(
            $languageCount,
            GameName::where('game_id', $game->id)->count()
        );
    }

    public function test_skips_already_translated_games(): void
    {
        $game = Game::create([
            'vendor_id' => $this->vendorId,
            'type_id' => 1,
            'game_type' => 'test_game_' . uniqid(),
            'name' => 'Emperor Gate',
            'status' => 1,
            'admin' => 0,
        ]);

        // 預先寫入繁中翻譯
        $zhTwId = Language::where('code', 'zh-tw')->value('id');
        GameName::create([
            'language_id' => $zhTwId,
            'game_id' => $game->id,
            'name' => '皇帝之門',
            'admin' => 0,
        ]);

        $this->artisan('vendor:translate-game-names', ['vendorId' => $this->vendorId])
            ->assertSuccessful();

        // 繁中仍只有 1 筆（未重複寫入）
        $this->assertSame(
            1,
            GameName::where('game_id', $game->id)->where('language_id', $zhTwId)->count()
        );
    }

    public function test_only_translates_specified_language_when_option_given(): void
    {
        $game = Game::create([
            'vendor_id' => $this->vendorId,
            'type_id' => 1,
            'game_type' => 'test_game_' . uniqid(),
            'name' => 'Girls',
            'status' => 1,
            'admin' => 0,
        ]);

        $zhTwId = Language::where('code', 'zh-tw')->value('id');

        $this->artisan('vendor:translate-game-names', [
            'vendorId' => $this->vendorId,
            '--language' => $zhTwId,
        ])->assertSuccessful();

        // 只寫了繁中，其他語言不應有資料
        $this->assertSame(1, GameName::where('game_id', $game->id)->count());
        $this->assertSame(1, GameName::where('game_id', $game->id)->where('language_id', $zhTwId)->count());
    }

    public function test_exits_with_failure_when_vendor_has_no_games(): void
    {
        $this->artisan('vendor:translate-game-names', ['vendorId' => 99999])
            ->assertFailed();
    }
}
