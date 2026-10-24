<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableLanguageText extends Migration
{
    protected $table_name = 'language_texts';      

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
           Schema::create($this->table_name, function (Blueprint $table) {            
            $table->increments('id');
            $table->string('language_text_code')->comment('多語系對應代碼');
            $table->integer('language_id')->comment('語言代碼');
            $table->string('text' , 1000)->comment('多語系文字');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
        });

        DB::statement("ALTER TABLE `$this->table_name` comment '綜合性的多語系文字定義'");       
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
