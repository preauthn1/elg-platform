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
        SchemaMonitor::create('rolling_chip_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0);
            $table->integer('member_id')->default(0)->index();
            $table->integer('vendor_id')->default(0);
            $table->integer('type')->default(0)->index();
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('before_balance', 18, 4)->default(0);
            $table->decimal('balance', 18, 4)->default(0);
            $table->integer('status')->default(0);
            $table->string('remark', 191)->nullable();
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('rolling_chip_logs');
    }
};
