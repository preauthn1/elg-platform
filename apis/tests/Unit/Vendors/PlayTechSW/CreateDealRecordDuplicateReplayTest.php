<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\PlayTechSW;

use App\Http\Controllers\External\SingleWallets\PlayTechController;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswDealRecord;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class CreateDealRecordDuplicateReplayTest extends TestCase
{
    /**
     * 建立 PlayTechController partial mock 並透過 Reflection 設 $account，
     * 避免 createDealRecord 在評估 insertDealRecord() 參數陣列時因 $this->account === null 觸發 PHP warning。
     *
     * Mock 調整說明：
     * - resolveDealAmounts 加入 onlyMethods：原實作存取 $this->vendorInfo->rate（未設成員），
     *   不 mock 會在 insertDealRecord 之前就 fatal，無法誠實驗證 1062 catch 分支。
     * - $this->account 透過 ReflectionProperty 設為 PtswAccount mock（disabled constructor），
     *   讓 username/token 屬性評估時不產生 PHP warning。
     */
    private function makeControllerMock(array $onlyMethods): PlayTechController
    {
        /** @var PlayTechController $controller */
        $controller = $this->getMockBuilder(PlayTechController::class)
            ->disableOriginalConstructor()
            ->onlyMethods($onlyMethods)
            ->getMock();

        // 設 $this->account 為 PtswAccount stub，讓 username/token 屬性可讀
        /** @var PtswAccount $account */
        $account = $this->getMockBuilder(PtswAccount::class)
            ->disableOriginalConstructor()
            ->getMock();
        // Eloquent Model::$attributes 有預設 []；__set → setAttribute 在 disabled constructor 後仍可運作
        $account->username = 'test_user';
        $account->token = [REDACTED]';

        $prop = new ReflectionProperty(PlayTechController::class, 'account');
        $prop->setValue($controller, $account);

        return $controller;
    }

    /**
     * 1062 duplicate INSERT → re-select 既有 deal record 返回（不 rollback、不拋）。
     */
    public function test_insert_1062_reselects_existing_deal_record(): void
    {
        // 純 TestCase 無 Laravel app boot，Eloquent resolver 未設，直接 new 會觸發 boot() fatal。
        // 以 getMockBuilder + disableOriginalConstructor 繞過，只驗物件同一性即可。
        /** @var PtswDealRecord $existing */
        $existing = $this->getMockBuilder(PtswDealRecord::class)
            ->disableOriginalConstructor()
            ->getMock();

        $controller = $this->makeControllerMock(['resolveDealAmounts', 'findExistingDealRecord', 'insertDealRecord']);

        // resolveDealAmounts 回傳 [betAmount, winAmount, jackpot, transactionCode, resolvedAction]
        $controller->method('resolveDealAmounts')
            ->willReturn(['0', '0', '0', 'tc-x', null]);

        // 首查 null（race 下沒查到）、次查命中（另一 tx 已 commit）
        $controller->method('findExistingDealRecord')
            ->willReturnOnConsecutiveCalls(null, $existing);

        $pdoEx = new PDOException('Duplicate entry');
        $pdoEx->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $controller->method('insertDealRecord')
            ->willThrowException(new QueryException('mysql', 'insert', [], $pdoEx));

        $m = new ReflectionMethod(PlayTechController::class, 'createDealRecord');
        $result = $m->invoke(
            $controller,
            'bet',
            ['gameRoundCode' => 'live_x', 'transactionCode' => 'tc-x', 'requestId' => 'r1'],
        );

        $this->assertSame($existing, $result, '1062 應 re-select 既有 deal record 返回');
    }

    /**
     * 非 1062 例外應原樣往上拋（不吞）。
     */
    public function test_non_1062_exception_is_rethrown(): void
    {
        $controller = $this->makeControllerMock(['resolveDealAmounts', 'findExistingDealRecord', 'insertDealRecord']);

        $controller->method('resolveDealAmounts')
            ->willReturn(['0', '0', '0', 'tc-x', null]);

        $controller->method('findExistingDealRecord')
            ->willReturn(null);

        $pdoEx = new PDOException('Deadlock');
        $pdoEx->errorInfo = ['40001', 1213, 'Deadlock found'];
        $queryException = new QueryException('mysql', 'insert', [], $pdoEx);

        $controller->method('insertDealRecord')
            ->willThrowException($queryException);

        $this->expectException(QueryException::class);

        $m = new ReflectionMethod(PlayTechController::class, 'createDealRecord');
        $m->invoke(
            $controller,
            'bet',
            ['gameRoundCode' => 'live_x', 'transactionCode' => 'tc-x', 'requestId' => 'r1'],
        );
    }
}
