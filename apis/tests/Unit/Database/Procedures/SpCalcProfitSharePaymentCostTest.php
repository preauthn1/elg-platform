<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Procedures;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SP_calc_profit_share_payment_cost 的支付成本來源測試。
 *
 * 守的是「成本取訂單上凍結的 payment_cost，不是以點數加總 × 當下的 agent_cash_flows.cost 現算」
 * （target/tickets#4300，原因見 SP 檔頭註記：現算會讓重算歷史日期套到改過的新費率）。
 *
 * 為什麼驗 routine 定義而不是跑 SP：這支 SP 的游標會掃過測試庫裡**所有**佔成總代（實測 79 筆），
 * 每圈都 `DELETE ... WHERE agent_id IN (hierarchy_path LIKE CONCAT(路徑, '%'))`。共用測試庫的既有
 * 代理資料會讓自建夾具被其他圈刪掉，CALL 完整張表為空——同目錄的 {@see SpProfitShareEndToEndTest}
 * 也呼叫這支 SP，但沒有任何斷言，所以一直沒暴露。在那個前提下，端到端斷言只會變成隨環境紅綠的
 * 假訊號；這裡改守「算法來源」這件事本身，環境零依賴，有人改回現算就會紅。
 *
 * ```bash
 * php artisan test tests/Unit/Database/Procedures/SpCalcProfitSharePaymentCostTest.php
 * ```
 */
class SpCalcProfitSharePaymentCostTest extends TestCase
{
    private const string PROCEDURE = 'SP_calc_profit_share_payment_cost';

    #[Test]
    public function it_sums_frozen_payment_cost_from_orders_and_withdrawal_orders(): void
    {
        $body = $this->procedureBody();

        $this->assertStringContainsString(
            'SUM(o.payment_cost)',
            $body,
            '代收成本應加總 orders.payment_cost（入帳當下凍結的渠道費率）'
        );
        $this->assertStringContainsString(
            'SUM(payment_cost)',
            $body,
            '代付成本應加總 withdrawal_orders.payment_cost（派款成功時凍結）'
        );
    }

    #[Test]
    public function it_no_longer_recalculates_cost_with_current_channel_rate(): void
    {
        $body = $this->procedureBody();

        $this->assertStringNotContainsString(
            '* acf.cost',
            $body,
            '不得再以「點數 × 當下的 agent_cash_flows.cost」現算成本：重算歷史日期時會套到改過的新費率，算出來的不是當時的真實成本'
        );
    }

    /**
     * 取測試庫裡該 procedure 的定義；沒同步過就直接讓測試紅，不靜默跳過。
     */
    private function procedureBody(): string
    {
        $row = DB::selectOne(
            'SELECT ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?',
            [self::PROCEDURE]
        );

        $this->assertNotNull(
            $row,
            self::PROCEDURE . ' 不存在於測試庫，請先跑 php artisan db:seed --class=SyncProcedureSeeder --env=testing'
        );

        return (string) $row->body;
    }
}
