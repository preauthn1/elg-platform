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
        SchemaMonitor::create('summary_shareholders', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index()->comment('代理ID');
            $table->integer('parent_id')->index()->comment('父代理ID');
            $table->integer('shareholder_category_id')->default(0);
            $table->string('hierarchy_path', 191)->index()->comment('代理階層路徑');
            $table->integer('upper_share_point')->comment('上級分桶分成比例');
            $table->integer('share_point')->comment('分桶分成比例');
            $table->decimal('rate', 5, 4)->default(0)->comment('佔比');
            $table->decimal('direct_turnover', 18, 4)->default(0)->comment('自己有效流水');
            $table->decimal('direct_rate', 5, 4)->default(0);
            $table->decimal('direct_nett_profit', 18, 4)->default(0);
            $table->decimal('team_turnover', 18, 4)->default(0);
            $table->decimal('team_rate', 5, 4)->default(0);
            $table->decimal('team_nett_profit', 18, 4)->default(0)->comment('利潤(含團隊)');
            $table->decimal('nett_profit', 18, 4)->default(0)->comment('實際淨利潤');
            $table->text('remark')->nullable();
            $table->boolean('status')->default(false)->index()->comment('派發狀態');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'shareholder_category_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_shareholders');
    }
};
