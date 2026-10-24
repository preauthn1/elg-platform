<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agents', 'email_gateway_group_id')) {
                $table->integer('email_gateway_group_id')
                    ->nullable()
                    ->after('sms_gateway_group_id')
                    ->index()
                    ->comment('電子郵件群組ID(agent_groups.id)');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agents', 'email_gateway_group_id')) {
                $table->dropColumn('email_gateway_group_id');
            }
        });
    }
};
