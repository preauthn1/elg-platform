<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'agent_invite_codes';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create('agent_invite_codes', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->nullable()->index('idx_agent_codes_agent_id')->comment('代理系統編號(agents)');
            $table->tinyInteger('type')->default(0)->index('idx_agent_codes_type')->comment('推廣碼類型; 0:一般, 1:佔成');
            $table->string('code', 10)->unique('code_unique')->comment('推廣碼');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};
