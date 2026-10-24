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
        SchemaMonitor::create('temp_lottery_tickets_4d', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id');
            $table->string('ticket_number', 6);
            $table->unsignedInteger('sort');
            $table->integer('status')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('temp_lottery_tickets_4d');
    }
};
