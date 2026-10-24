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
            if (!SchemaMonitor::hasColumn('members', 'password_md5')) {
                $table->char('password_md5', 32)->nullable()->index()->after('password')->comment('密碼MD5(風控比對同密碼帳號用，不參與登入驗證)');
            }
        }, maxRows: 100_000_000);
    }

    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('members', 'password_md5')) {
                $table->dropIndex(['password_md5']);
                $table->dropColumn('password_md5');
            }
        }, maxRows: 100_000_000);
    }
};
