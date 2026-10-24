<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->dropIndex(['input']);
            $table->text('input')->change();
        });
    }

    public function down(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->string('input', 100)->index()->change();
        });
    }
};
