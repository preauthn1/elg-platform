<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 標準銀行表獨立化（銀行主檔地基第一步，2026-05-14）
 *
 * ┌──────────────────────────────────────────────────────────────┐
 * │  異動內容                                                      │
 * ├──────────────────────────────────────────────────────────────┤
 * │  standard_banks    │  建立新表（跨專案 MDM 銀行主檔，INT id） │
 * │  banks             │  新增 standard_bank_id 映射欄位          │
 * └──────────────────────────────────────────────────────────────┘
 *
 * ⚠️ 回滾不是零代價：down() 會 DROP 整張 standard_banks，連同 seeder 之後灌進去的主檔資料
 * （含 swift 等人工補齊的欄位）一起消失；同時刪掉 banks 的 standard_bank_id 欄位與索引，
 * 所有映射資料一併消失（不是欄位保留、值歸零）。上線後不可 migrate:rollback 本檔。
 *
 * ⚠️ 本 migration 僅建 schema；資料 backfill 透過 cash-flow:seed-standard-banks ＋ bank:sync-mappings 執行。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createStandardBanks();
        $this->addBanksMappingColumn();
    }

    public function down(): void
    {
        $this->dropBanksMappingColumn();
        SchemaMonitor::dropIfExists('standard_banks');
    }

    // =========================================================================
    // P0-S1 — 建立 standard_banks 表
    // =========================================================================

    private function createStandardBanks(): void
    {
        SchemaMonitor::create('standard_banks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 64)->comment('標準銀行代碼（全大寫，不含幣別前綴）');
            $table->unsignedInteger('currency_id')->comment('幣別 ID，對應 currencies.id');
            $table->string('currency_code', 10)->nullable()->comment('幣別代碼（冗餘欄位），如 MYR / VND / IDR');
            $table->tinyInteger('type')->default(1)->comment('類型; 1:銀行帳號, 2:手機/電子錢包, 3:加密貨幣');
            $table->string('name', 100)->comment('銀行英文名稱（預設顯示）');
            $table->string('swift', 11)->nullable()->comment('SWIFT BIC，可為 null');
            $table->json('aliases')->nullable()->comment('銀行關鍵字/縮寫陣列（JSON），供 mapping 正規化比對');
            $table->string('remark', 255)->nullable()->comment('備註');
            $table->tinyInteger('status')->default(1)->comment('狀態: 1=啟用, 0=停用');
            $table->timestamps();

            $table->unique(['code', 'currency_id'], 'standard_banks_code_currency_id_unique');
            $table->index('currency_id', 'standard_banks_currency_id_index');
            $table->index(['currency_id', 'type', 'status'], 'standard_banks_currency_id_type_status_index');
        });
    }

    // =========================================================================
    // P0-S2 — banks 表加入 standard_bank_id 映射欄位
    // 對應 Bank model PHPDoc：$standard_bank_id 對應 standard_banks.id
    // =========================================================================

    private function addBanksMappingColumn(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('banks', 'standard_bank_id')) {
                $table->unsignedInteger('standard_bank_id')
                    ->nullable()
                    ->after('id')
                    ->comment('對應 standard_banks.id；過渡期映射，Phase 5 隨 banks 退役一併移除');

                $table->index('standard_bank_id', 'idx_standard_bank');
            }
        });
    }

    private function dropBanksMappingColumn(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('banks', 'standard_bank_id')) {
                $table->dropIndex('idx_standard_bank');
                $table->dropColumn('standard_bank_id');
            }
        });
    }
};
