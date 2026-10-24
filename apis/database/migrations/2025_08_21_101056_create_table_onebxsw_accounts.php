<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('onebxsw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->comment('商戶編號')->index();
            $table->string('username', 32)->comment('玩家帳號');
            $table->integer('member_id')->default(0)->comment('會員系統編號')->index();
            $table->boolean('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->integer('admin')->default(0)->comment('管理員編號');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

            $table->unique('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('onebxsw_accounts');
    }
};
