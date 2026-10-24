<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\ApiException;
use App\Exceptions\LockTimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * LockTimeoutException 型別契約守門。
 *
 * PlayTechController 的套鎖入口靠「LockTimeoutException 是 ApiException 的獨立子型別」
 * 這個關係成立：
 *  - is-a ApiException → 仍走既有 render/toArray 管道、不破壞整合；
 *  - 獨立型別 → bet()/settlement() 外層 catch 得以只收窄承接「取鎖失敗」，
 *    其他 ApiException 維持冒至 callAction → INTERNAL_ERROR（還原套鎖前語意）。
 * 若日後有人讓它不再繼承 ApiException、或把它併回 ApiException，本測試會擋下。
 *
 * 註：真正的併發 timeout 路徑（GET_LOCK 取不到 → 拋此例外）無法在單進程 PHPUnit
 * 重現，未在此驗證（與實作計畫「測試策略誠實聲明」一致）。
 */
class LockTimeoutExceptionTest extends TestCase
{
    public function test_is_a_distinct_subtype_of_api_exception(): void
    {
        $exception = new LockTimeoutException('Concurrent request on game round');

        $this->assertInstanceOf(ApiException::class, $exception, 'LockTimeoutException 必須 is-a ApiException 以維持既有整合');
        $this->assertNotSame(ApiException::class, $exception::class, 'LockTimeoutException 必須是獨立型別，外層 catch 才能收窄');
        $this->assertSame('Concurrent request on game round', $exception->getMessage());
    }
}
