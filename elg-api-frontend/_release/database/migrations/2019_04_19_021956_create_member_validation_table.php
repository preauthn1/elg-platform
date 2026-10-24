<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberValidationTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('member_validations');
        Schema::create('member_validations', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('member_id')->comment('帳號系統編號(members)');
            $table->tinyInteger('type_id')->comment('驗證類型系統編號');
            $table->string('image')->comment('檔案位置');
            $table->string('remark')
                ->nullable()
                ->comment('備註');
            $table->tinyInteger('status')
                ->default(0)
                ->comment('是否通過審核 0: 未通過 1:通過 2:拒絕');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('member_validations');
    }
}
