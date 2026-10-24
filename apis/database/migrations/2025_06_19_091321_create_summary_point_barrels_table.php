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
        SchemaMonitor::create('summary_point_barrels', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time');
            $table->integer('agent_id')->default(0);
            $table->integer('parent_id')->default(0);
            $table->integer('vendor_id')->nullable()->default(0);
            $table->string('hierarchy_path', 200);
            $table->integer('share_point')->nullable()->default(0);
            $table->decimal('total_turnover', 18, 4)->default(0);
            $table->decimal('total_nett_profit', 18, 4)->default(0);
            $table->decimal('self_turnover', 18, 4)->default(0);
            $table->decimal('self_nett_profit', 18, 4)->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'vendor_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_point_barrels');
    }
};
