<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * allowed_vendor_ids 原為 NOT NULL（建表時即要求必填清單），但新增的排除遊戲商
 * （excluded_vendor_ids）需要 allowed_vendor_ids 可留空才能互斥：只填排除清單、
 * 不填限制清單時，後端會存入 allowed_vendor_ids=null，DB 層仍會以 23000 擋下寫入。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('newbie_challenge_configs', function (Blueprint $table): void {
            $table->json('allowed_vendor_ids')->nullable()->comment('第二階段挑戰流水計算範圍的遊戲商/遊戲ID清單，如FUMA、WUDI；null=不限制(與 excluded_vendor_ids 互斥)')->change();
        });

        $this->assertNullable(true);
    }

    public function down(): void
    {
        SchemaMonitor::table('newbie_challenge_configs', function (Blueprint $table): void {
            $table->json('allowed_vendor_ids')->comment('第二階段挑戰流水計算範圍的遊戲商/遊戲ID清單，如FUMA、WUDI')->change();
        });

        $this->assertNullable(false);
    }

    /**
     * SchemaMonitor::table() 在 production 遇到列數超限或列數查詢失敗時會直接 return，
     * migration 仍會被記為已執行；欄位若沒真的改成 nullable，程式層已經在存 null，
     * 寧可 migration 當場失敗讓人處理，而不是留著隱性的 23000 錯誤。
     */
    private function assertNullable(bool $expected): void
    {
        $actual = (string) (DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['newbie_challenge_configs', 'allowed_vendor_ids']
        )->IS_NULLABLE ?? '');

        $expectedValue = $expected ? 'YES' : 'NO';

        if ($actual !== $expectedValue) {
            throw new RuntimeException(sprintf(
                'newbie_challenge_configs.allowed_vendor_ids IS_NULLABLE 為 %s、預期 %s：ALTER 未實際生效（可能被 SchemaMonitor 跳過），請人工處理。',
                $actual === '' ? '(查不到)' : $actual,
                $expectedValue
            ));
        }
    }
};
