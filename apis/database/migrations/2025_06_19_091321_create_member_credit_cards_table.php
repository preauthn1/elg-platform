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
        SchemaMonitor::create('member_credit_cards', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('user_id')->comment('帳號系統編號(User)');
            $table->string('card_number', 191)->comment('信用卡號');
            $table->string('card_holder', 191)->comment('持卡人姓名');
            $table->date('expire_date')->comment('過期日');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_credit_cards');
    }
};
