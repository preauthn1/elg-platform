<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'wallet_types';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('錢包種類\n1:平台幣(BC)\n2~1000 法定貨幣(USD, MYR, CNY)\n1001~2000 體驗金, 泥碼, 免費幣, BC\n2001~3000 穩定加密貨幣(USDT, USDC, UST, USDD)\n3001~ 加密貨幣(BTC, ETH, DOGE, SHIB, ....)');
            $table->increments('id')->comment('系統編號');
            $table->string('code', 50)->nullable()->unique('uni_wallet_types_code')->comment('錢包代碼');
            $table->string('name', 50)->nullable()->comment('錢包名稱');
            $table->text('info')->comment('錢包資訊');
            $table->tinyInteger('status')->default(0)->index('idx_wallet_types_status')->comment('帳號狀態 0:未認證,1:正常,2:鎖定(密碼輸入錯六次, 系統自動變更)');
            $table->string('memo', 191)->nullable()->comment('方便技術記錄用');
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
        Schema::dropIfExists($this->table_name);
    }
};
