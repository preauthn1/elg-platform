<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('bank_mapping_logs', function (Blueprint $table) {
            $table->comment('bank:sync-mappings 回填審計（banks.standard_bank_id 映射紀錄）');
            $table->increments('id');
            $table->string('connection', 50)->nullable()->comment('執行當下的 DB connection（白標）');
            $table->integer('bank_id')->comment('回填的 banks.id');
            $table->unsignedInteger('standard_bank_id')->comment('映射到的 standard_banks.id');
            $table->string('tier', 20)->comment('code_direct / code_hop / name_hop / alias / reviewed');
            $table->unsignedSmallInteger('confidence')->comment('匹配信心度 0-100');
            $table->string('matched_by', 30)->comment('exact_code / hop_code / hop_name / name / alias / manual');
            $table->string('action', 30)->comment('auto_fill / reviewed_fill / reverted');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->index('bank_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('bank_mapping_logs');
    }
};
