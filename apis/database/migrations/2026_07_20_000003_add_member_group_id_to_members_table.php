<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('members', 'member_group_id')) {
                $table->integer('member_group_id')->nullable()->index()->after('parent')->comment('會員群組(member_groups.id)');
            }
        }, maxRows: 100_000_000);
    }

    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('members', 'member_group_id')) {
                $table->dropIndex(['member_group_id']);
                $table->dropColumn('member_group_id');
            }
        }, maxRows: 100_000_000);
    }
};
