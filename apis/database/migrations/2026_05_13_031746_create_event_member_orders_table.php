<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'event_member_orders';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->default(0)->comment('events.id');
            $table->integer('member_id')->default(0)->comment('members.id');
            $table->decimal('accumulated_amount', 18, 4)->default(0)->comment('累積儲值金額');
            $table->tinyInteger('is_first_day')->default(1)->comment('是否為當次進度的第一天');
            $table->dateTime('last_deposit_time')->nullable()->comment('最後儲值時間');
            $table->integer('last_order_id')->default(0)->comment('orders.id');
            $table->integer('consecutive_times')->default(0)->comment('累積連續儲值次數');
            $table->integer('max_consecutive_times')->default(0)->comment('當次進度的最大連續儲值次數');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['event_id', 'member_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};
