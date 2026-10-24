<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::table('new4d2026_bet_records', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent()->comment('建立日期')->change();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期')->change();
        });
        SchemaMonitor::table('new4d2026_accounts', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent()->comment('建立日期')->change();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
