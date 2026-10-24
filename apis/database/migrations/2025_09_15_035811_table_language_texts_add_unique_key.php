<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private $table = 'language_texts';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        DB::statement("
            DELETE t1
            from {$this->table} t1 
            join {$this->table} t2 
                on t1.language_text_code = t2.language_text_code 
                and t1.language_id = t2.language_id 
                and t1.id < t2.id
        ");

        SchemaMonitor::table($this->table, function (Blueprint $table) {
            $table->unique(['language_text_code', 'language_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table($this->table, function (Blueprint $table) {
            $table->dropUnique(['language_text_code', 'language_id']);
        });
    }
};
