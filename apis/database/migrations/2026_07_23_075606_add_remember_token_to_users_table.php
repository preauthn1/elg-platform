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
        SchemaMonitor::table('users', function (Blueprint $table) {
            $table->rememberToken()->after('password')->comment('記住我 token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('users', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }
};
