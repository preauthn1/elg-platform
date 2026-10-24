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
        SchemaMonitor::create('domains', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('domain_type_id')->index()->comment('網域類型系統編號;');
            $table->string('domain', 191)->unique()->comment('網址名稱');
            $table->tinyInteger('status')->default(1)->index()->comment('0未使用,1使用中');
            $table->integer('sort')->default(0)->index()->comment('排序');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(1)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('domains');
    }
};
