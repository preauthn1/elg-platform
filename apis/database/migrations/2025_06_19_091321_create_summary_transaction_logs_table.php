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
        SchemaMonitor::create('summary_transaction_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('data_time')->useCurrentOnUpdate()->useCurrent()->index()->comment('統計時間');
            $table->integer('member_id')->index()->comment('會員編號');
            $table->integer('type')->index()->comment('類型');
            $table->integer('count')->index()->comment('交易次數');
            $table->decimal('point', 18, 4)->index()->comment('點數');
            $table->timestamp('first_transaction_at')->nullable()->comment('時間內首次交易時間');
            $table->timestamp('last_transaction_at')->nullable()->comment('時間內最後一次交易時間');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['data_time', 'member_id', 'type'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_transaction_logs');
    }
};
