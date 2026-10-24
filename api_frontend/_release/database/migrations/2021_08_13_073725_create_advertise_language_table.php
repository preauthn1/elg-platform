<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAdvertiseLanguageTable extends Migration
{
    protected $table_name = 'advertise_languages';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('advertise_id')->default(0)->comment('廣告ID(advertises.id)');
                $table->integer('language_id')->default(0)->comment('語言ID, 0:全語系, 1~N:參考languages.id');
                $table->integer('advertise_image_id')->default(0)->comment('圖片ID(advertise_images.id)');
                $table->integer('advertise_image_pc_id')->default(0)->comment('PC圖片ID(advertise_images.id)');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->comment('操作人員');
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
        Schema::dropIfExists($this->table_name);
    }
}
