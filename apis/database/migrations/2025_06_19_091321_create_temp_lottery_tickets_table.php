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
        SchemaMonitor::create('temp_lottery_tickets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->default(0);
            $table->string('ticket_number', 6);
            $table->unsignedInteger('sort');
            $table->tinyInteger('status')->default(0);
            $table->boolean('weeks_of_use')->nullable()->default(true)->index()->comment('使用週數; 1:單週, 2:雙週');

            $table->index(['status', 'sort']);
            $table->unique(['event_id', 'ticket_number', 'weeks_of_use'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('temp_lottery_tickets');
    }
};
