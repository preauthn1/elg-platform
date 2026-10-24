<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\PlayTechSW;

use App\Vendors\PlayTechSW\GameRoundLock;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * GameRoundLock::key() 的鎖域必須與 DB 的同局判定一致。
 *
 * ptsw_deal_records.bet_id／username 與 ptsw_bet_records.bet_id 都是 utf8mb4_unicode_ci（不分大小寫）：
 * PT 若對同一局送出大小寫不同的 gameRoundCode 或 username，DB 會當成同一局／同一帳號，
 * 鎖 key 若照原字串 md5 就會拿到兩把不同的鎖，同局併發正好繞過序列化。
 *
 * php artisan test tests/Unit/Vendors/PlayTechSW/GameRoundLockTest.php --compact
 */
final class GameRoundLockTest extends TestCase
{
    #[TestDox('key：gameRoundCode 與 username 只差大小寫時算同一把鎖')]
    public function test_key_ignores_case_of_round_code_and_username(): void
    {
        $this->assertSame(
            GameRoundLock::key('live_15006767178082#269E5FEB', 'PTSW_Player01'),
            GameRoundLock::key('live_15006767178082#269e5feb', 'ptsw_player01'),
        );
    }

    #[TestDox('key：不同局或不同帳號各自一把鎖')]
    public function test_key_differs_between_rounds_and_between_usernames(): void
    {
        $base = GameRoundLock::key('live_1#a', 'player01');

        $this->assertNotSame($base, GameRoundLock::key('live_1#b', 'player01'));
        $this->assertNotSame($base, GameRoundLock::key('live_1#a', 'player02'));
    }

    #[TestDox('key：帶 ptsw: 前綴且長度固定，不會超過 MySQL GET_LOCK 的 64 字元上限')]
    public function test_key_is_prefixed_and_bounded(): void
    {
        $key = GameRoundLock::key(str_repeat('x', 200), str_repeat('y', 200));

        $this->assertStringStartsWith('ptsw:', $key);
        $this->assertLessThanOrEqual(64, strlen($key));
    }
}
