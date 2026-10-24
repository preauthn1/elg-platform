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
        SchemaMonitor::table('withdrawal_payout_records', function (Blueprint $table) {
            $table->text('info_1')->change();
            $table->text('info_2')->change();
            $table->text('info_3')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('withdrawal_payout_records', function (Blueprint $table) {
            $table->string('info_1', 200)->change();
            $table->string('info_2', 200)->change();
            $table->string('info_3', 200)->change();
        });
    }
};
