<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sms_send_records` 記下「這一則實際套用的簡訊商設定與成本」target/tickets#4424。
 *
 * 目前簡訊成本沒有在發送當下凍結，要靠彙總時用「代理的通道組＋簡訊商」反推單價；
 * 同一家簡訊商在同一個通道組可以有多國費率（TPower 通道組 675 的通道 11 就有越南 0.4595
 * 與印尼 0.3061 兩列），反推時兩列都命中，一則被算成 0.7656。凍結成本之後，彙總改為
 * 直接加總 `cost`，那三個 join 連同這個問題一起消失。
 *
 * **正式站六站已先用後台的加欄位工具加好**（`ALGORITHM=INSTANT`，附加在最後、序位 13／14），
 * 本 migration 是給測試庫與新環境用的，故先判斷欄位在不在、已存在就跳過：VPlus33、Me77、
 * Alfawin77 三站列數低於 SchemaMonitor 的 50 萬門檻，`SchemaMonitor::table()` 會真的執行，
 * 不先判斷就會撞 duplicate column。
 *
 * ⚠️ **不可加 `->after()`**：後台工具一律把欄位附加在最後，測試庫若指定位置，
 * 欄位序位會與正式站不同。加入順序也必須是先 `cost`、後 `sms_gateway_info_id`。
 *
 * SchemaMonitor 對超過門檻的表是**靜默跳過、不拋錯**（migration 仍記為已執行），
 * 所以每個操作後面都有 `Schema::hasColumn` 斷言，避免「跑過了但欄位沒建成」無人察覺。
 */
return new class extends Migration
{
    private const string TABLE = 'sms_send_records';

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, 'cost')) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table): void {
                $table->decimal('cost', 10, 4)->default(0)->comment('發送當下的簡訊手續費, 取自 sms_gateway_infos.cost');
            });
        }

        $this->assertColumn('cost');

        if (!Schema::hasColumn(self::TABLE, 'sms_gateway_info_id')) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedInteger('sms_gateway_info_id')->default(0)->comment('簡訊商商戶id, 0為未知');
            });
        }

        $this->assertColumn('sms_gateway_info_id');
    }

    public function down(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(['sms_gateway_info_id', 'cost']);
        });
    }

    /**
     * 生效斷言：SchemaMonitor 靜默跳過時，這裡是唯一會叫的地方。
     */
    private function assertColumn(string $column): void
    {
        if (!Schema::hasColumn(self::TABLE, $column)) {
            throw new RuntimeException(sprintf(
                '%s.%s 未生效（SchemaMonitor 可能因列數門檻靜默跳過，且正式站尚未以後台工具補上），中止',
                self::TABLE,
                $column,
            ));
        }
    }
};
