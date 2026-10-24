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
        SchemaMonitor::create('agent_hierarchys', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->nullable()->index();
            $table->integer('sub_agent_id')->nullable()->index();
            $table->tinyInteger('level')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_hierarchys');
    }
};
