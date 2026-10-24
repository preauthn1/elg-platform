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
        SchemaMonitor::create('member_restrictions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->integer('account_status')->default(0)->comment('帳號狀態; 1:Suspend(懷疑),2:active');
            $table->integer('previous_account_status')->default(0)->comment('上一次的帳號狀態');
            $table->integer('risk')->default(0)->comment('風險評估; 1:normal,2:high');
            $table->integer('previous_risk')->default(0)->comment('上一次的風險評估');
            $table->string('remark', 300)->comment('備註');
            $table->string('previous_remark', 300)->comment('上一次的備註');
            $table->tinyInteger('amend')->default(0)->comment('是否修改過; 0:否, 1:是');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_restrictions');
    }
};
