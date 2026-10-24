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
        SchemaMonitor::create('summary_point_barrel_winloses', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time')->index();
            $table->integer('agent_id')->default(0)->index();
            $table->integer('vendor_id')->default(0)->index();
            $table->decimal('turnover', 18, 4)->default(0);
            $table->decimal('game_spending', 18, 4)->default(0);
            $table->decimal('game_profit', 18, 4)->default(0);
            $table->string('memo', 200)->default('');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_point_barrel_winloses');
    }
};
