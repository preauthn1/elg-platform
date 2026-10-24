<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('bank_accounts', 'qrcode') === false) {
            SchemaMonitor::table('bank_accounts', function (Blueprint $table) {
                $table->text('qrcode')->after('amount')->nullable()->comment('QR Code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bank_accounts', 'qrcode') === true) {
            SchemaMonitor::table('bank_accounts', function (Blueprint $table) {
                $table->dropColumn('qrcode');
            });
        }
    }
};
