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
        SchemaMonitor::create('vendor_infos', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('name', 32)->comment('方便識別的名稱');
            $table->tinyInteger('type')->comment('遊戲商環境，0:測試，1:正式');
            $table->integer('vendor_id')->comment('遊戲商系統編號');
            $table->string('currency', 5)->comment('幣種代碼');
            $table->decimal('rate', 11, 5)->default(1)->comment('轉換率');
            $table->decimal('transfer_min_amount', 11, 5)->nullable()->default(0.01);
            $table->text('info')->comment('連接資訊');
            $table->integer('prepared_account_number')->default(100)->comment('預備帳號數量');
            $table->string('account_prefix', 10)->comment('帳號前綴');
            $table->decimal('cost', 10, 4)->default(0);
            $table->text('remark')->nullable();
            $table->boolean('status')->default(false)->comment('狀態，0:停用、1:啟用');
            $table->boolean('rolling_chip_status')->default(true)->comment('禮碼開關 1:開啟 0:關閉');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
            $table->decimal('deposit_limit', 18, 4)->default(0)->comment('存款限制, 0 = 無限制');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_infos');
    }
};
