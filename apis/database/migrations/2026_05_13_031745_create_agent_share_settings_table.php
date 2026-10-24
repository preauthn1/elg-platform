<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'agent_share_settings';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('代理帳號個人設置');
            $table->increments('id');
            $table->integer('agent_group_id')->unique('agent_group_id_unique')->comment('代理組別id');
            $table->string('value', 200)->comment('設置value');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->comment('建立人');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_share_settings');
    }
};
