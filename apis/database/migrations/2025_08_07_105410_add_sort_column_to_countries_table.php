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
        if (!Schema::hasColumn('countries', 'sort')) {
            SchemaMonitor::table('countries', function (Blueprint $table) {
                $table->integer('sort')
                    ->default(0)
                    ->comment('排序權重')
                    ->after('status')
                    ->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('countries', 'sort')) {
            SchemaMonitor::table('countries', function (Blueprint $table) {
                $table->dropColumn('sort');
            });
        }
    }
};
