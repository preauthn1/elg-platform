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
        SchemaMonitor::create('order_type_names', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('language_id')->index()->comment('語言系統編號');
            $table->integer('type_id')->index()->comment('訂單類型系統編號');
            $table->string('name', 191)->comment('訂單類型名稱');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['language_id', 'type_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('order_type_names');
    }
};
