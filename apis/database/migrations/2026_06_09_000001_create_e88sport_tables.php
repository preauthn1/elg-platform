<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('e88sport_accounts', function (Blueprint $table) {
            $table->vendorAccountColumns(
                usernameLength: 50,
                uniqueUsername: true,
            );
        });

        SchemaMonitor::create('e88sport_bet_records', function (Blueprint $table) {
            $table->vendorBetRecordColumns();
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('e88sport_bet_records');
        SchemaMonitor::dropIfExists('e88sport_accounts');
    }
};
