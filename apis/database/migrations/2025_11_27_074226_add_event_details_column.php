<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('event_details', 'global_ip_block') === false) {
            SchemaMonitor::table('event_details', function (Blueprint $table) {
                $table->tinyInteger('global_ip_block')->after('ip_block')->default(0)->comment('全站限制IP 0:不阻擋 1:阻擋');
            });
        }

        if (Schema::hasColumn('event_details', 'global_phone_block') === false) {
            SchemaMonitor::table('event_details', function (Blueprint $table) {
                $table->tinyInteger('global_phone_block')->after('phone_block')->default(0)->comment('全站限制電話 0:不阻擋 1:阻擋');
            });
        }

        if (Schema::hasColumn('event_details', 'global_account_block') === false) {
            SchemaMonitor::table('event_details', function (Blueprint $table) {
                $table->tinyInteger('global_account_block')->after('account_block')->default(0)->comment('全站限制真實姓名 0:不阻擋 1:阻擋');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('event_details', 'global_ip_block') === true) {
            SchemaMonitor::table('event_details', function (Blueprint $table) {
                $table->dropColumn('global_ip_block');
            });
        }

        if (Schema::hasColumn('event_details', 'global_phone_block') === true) {
            SchemaMonitor::table('event_details', function (Blueprint $table) {
                $table->dropColumn('global_phone_block');
            });
        }

        if (Schema::hasColumn('event_details', 'global_account_block') === true) {
            SchemaMonitor::table('event_details', function (Blueprint $table) {
                $table->dropColumn('global_account_block');
            });
        }
    }
};
