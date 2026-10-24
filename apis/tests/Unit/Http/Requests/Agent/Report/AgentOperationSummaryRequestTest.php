<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Agent\Report;

use App\Http\Requests\Agent\Report\AgentOperationSummaryRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * SR28 查詢期間對齊整點（target/tickets#4362，使用者 2026-10-07 定案）：起始校正為 HH:00:00、結束校正為 HH:59:59，
 * 格式不符的原值不動，交給 date_format 規則擋。
 *
 * php artisan test --compact tests/Unit/Http/Requests/Agent/Report/AgentOperationSummaryRequestTest.php
 */
class AgentOperationSummaryRequestTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed, mixed, mixed}>
     */
    public static function cases(): array
    {
        return [
            '帶分秒：起始捨去、結束補到 59:59' => ['2026-10-06 13:27:45', '2026-10-07 08:05:00', '2026-10-06 13:00:00', '2026-10-07 08:59:59'],
            '已是整點：不變' => ['2026-10-06 00:00:00', '2026-10-07 23:59:59', '2026-10-06 00:00:00', '2026-10-07 23:59:59'],
            '結束剛好整點 00 分：補到同一小時 59:59' => ['2026-10-06 00:00:00', '2026-10-07 23:00:00', '2026-10-06 00:00:00', '2026-10-07 23:59:59'],
            '格式不符：原值不動' => ['2026-10-06 13:27', 'abc', '2026-10-06 13:27', 'abc'],
            '溢位時刻：原值不動' => ['2026-10-06 25:00:00', '2026-10-07 23:59:59', '2026-10-06 25:00:00', '2026-10-07 23:59:59'],
            '非字串：原值不動' => [null, 123, null, 123],
        ];
    }

    #[DataProvider('cases')]
    public function test_period_is_aligned_to_hour(mixed $start, mixed $end, mixed $expectedStart, mixed $expectedEnd): void
    {
        $request = AgentOperationSummaryRequest::create('/', 'POST', ['start_time' => $start, 'end_time' => $end]);

        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        $this->assertSame($expectedStart, $request->input('start_time'));
        $this->assertSame($expectedEnd, $request->input('end_time'));
    }
}
