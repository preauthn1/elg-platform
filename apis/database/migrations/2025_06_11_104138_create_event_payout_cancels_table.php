<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateEventPayoutCancelsTable extends Migration
{
    private $table_name = 'event_payout_cancels';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!SchemaMonitor::hasTable($this->table_name)) {
            SchemaMonitor::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('event_id')->default(0)->comment('events.id');
                $table->integer('order_id')->default(0)->comment('orders.id');
                $table->integer('member_id')->default(0)->comment('members.id')->index();
                $table->integer('type')->default(0)->comment('重複類型; 1: 真實姓名, 2: 註冊IP, 3: 手機號碼');
                $table->integer('block_member_id')->default(0)->comment('重複的會員id');
                $table->text('remark')->nullable()->comment('備註');
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        SchemaMonitor::dropIfExists($this->table_name);
    }
}
