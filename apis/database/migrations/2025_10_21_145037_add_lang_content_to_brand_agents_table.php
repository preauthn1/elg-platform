<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLangContentToBrandAgentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 檢查是否已經有這個欄位
        if (!Schema::hasColumn('brand_agents', 'lang_content')) {
            Schema::table('brand_agents', function (Blueprint $table) {
                $table->text('lang_content')
                    ->after('info')
                    ->nullable()
                    ->comment('語言內容');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('brand_agents', function (Blueprint $table) {
            $table->dropColumn('lang_content');
        });
    }
}
