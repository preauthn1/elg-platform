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
        SchemaMonitor::create('login_event_names', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('language_id')->default(0)->comment('語言系統編號');
            $table->integer('login_event_id')->default(0)->index()->comment('登入獎勵系統編號');
            $table->string('title')->comment('登入獎勵標題');
            $table->longText('content')->comment('登入獎勵內容');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('login_event_names');
    }
};
