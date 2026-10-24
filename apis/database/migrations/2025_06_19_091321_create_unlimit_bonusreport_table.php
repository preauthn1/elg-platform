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
        SchemaMonitor::create('unlimit_bonusreport', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->default(0)->index()->comment('玩家編號');
            $table->dateTime('summary_date')->index()->comment('時間(日)');
            $table->decimal('performance', 18, 4)->default(0)->comment('業績-自己');
            $table->decimal('performance_direct', 18, 4)->default(0)->comment('業績-直屬');
            $table->decimal('performance_team', 18, 4)->default(0)->comment('業績-團隊');
            $table->decimal('bonus_itself', 18, 4)->default(0)->comment('獎金-自身回水');
            $table->decimal('bonus_direct', 18, 4)->default(0)->comment('獎金-直屬');
            $table->decimal('bonus_team', 18, 4)->default(0)->comment('獎金-團隊');
            $table->decimal('bonus_amount', 10)->default(0)->comment('該級距獎勵金額-參考unlimit_systemrate');
            $table->tinyInteger('correction')->default(0)->comment('0:否, 1:是');
            $table->string('memo', 191)->nullable()->comment('備註');
            $table->timestamp('created_at')->nullable()->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('unlimit_bonusreport');
    }
};
