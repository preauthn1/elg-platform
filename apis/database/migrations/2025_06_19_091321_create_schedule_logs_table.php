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
        SchemaMonitor::create('schedule_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('schedule_name', 191)->index();
            $table->text('input');
            $table->integer('exit_code')->index();
            $table->dateTime('start_time')->index();
            $table->dateTime('end_time');
            $table->decimal('usages')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('schedule_logs');
    }
};
