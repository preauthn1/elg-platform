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
        SchemaMonitor::create('member_missions', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time');
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('mission_id');
            $table->decimal('progress', 18, 4)->default(0);
            $table->tinyInteger('is_claimed')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['summary_time', 'member_id', 'mission_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_missions');
    }
};
