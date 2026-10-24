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
        SchemaMonitor::create('temp_agent_hierarchy', function (Blueprint $table) {
            $table->comment('馬修測試效能用 20200804');
            $table->integer('id', true);
            $table->integer('root_agent');
            $table->integer('agent_id');
            $table->integer('agent_id_child')->index();
            $table->string('agent_name_child', 100)->nullable();
            $table->string('agent_nickname_child', 100)->nullable();
            $table->string('category', 45);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('temp_agent_hierarchy');
    }
};
