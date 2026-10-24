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
        SchemaMonitor::create('temp_four_lottery_tickets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id');
            $table->string('ticket_number', 6);
            $table->unsignedInteger('sort');
            $table->integer('status')->default(0);
            $table->tinyInteger('weeks_of_use')->default(1)->comment('使用週數; 1:單週, 2:雙週');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('temp_four_lottery_tickets');
    }
};
