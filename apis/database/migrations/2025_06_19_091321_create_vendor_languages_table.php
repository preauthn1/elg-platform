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
        SchemaMonitor::create('vendor_languages', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('vendor_id')->default(0)->comment('遊戲商編號');
            $table->string('third_party_code', 45)->nullable()->comment('三方代碼');
            $table->integer('language_id')->comment('語言編號; 0為預設');
            $table->string('code', 10)->comment('語言代碼');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['third_party_code', 'language_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_languages');
    }
};
