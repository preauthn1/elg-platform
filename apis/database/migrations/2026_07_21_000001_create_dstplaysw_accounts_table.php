<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('dstplaysw_accounts', function (Blueprint $table) {
            $table->vendorAccountColumns(
                usernameLength: 50,
                uniqueUsername: true,
                withSessionToken: [REDACTED],
            );
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('dstplaysw_accounts');
    }
};
