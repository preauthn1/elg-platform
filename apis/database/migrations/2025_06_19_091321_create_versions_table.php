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
        SchemaMonitor::create('versions', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('agent_group_id', 20)->comment('agent_groups.id');
            $table->integer('agent_id')->comment('代理id');
            $table->string('ios_version', 20)->default('1.0.0')->comment('ios版本');
            $table->string('ios_link', 200)->nullable()->comment('ios下載連結');
            $table->string('android_version', 20)->default('1.0.0')->comment('android版本');
            $table->string('android_link', 200)->nullable()->comment('android下載連結');
            $table->unsignedTinyInteger('type')->default(1)->comment('類型; 1:真實版本號, 2:臨時版本號');
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
        SchemaMonitor::dropIfExists('versions');
    }
};
