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
        SchemaMonitor::create('missions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 191);
            $table->integer('agent_id')->comment('代理ID');
            $table->tinyInteger('status')->default(0)->comment('0: never started, 1: active, 2: ended, -1: inactive');
            $table->string('reset_type', 191)->default('0')->comment('0: daily, 1: weekly, 2: monthly');
            $table->string('action_type', 191)->comment('操作類型, 0: deposit, 1: bet, 2: game');
            $table->string('condition_type', 191);
            $table->decimal('condition_value', 18, 4);
            $table->text('description');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->json('info')->nullable()->comment('任務資訊, all setting infos like condition, reward, etc.');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('missions');
    }
};
