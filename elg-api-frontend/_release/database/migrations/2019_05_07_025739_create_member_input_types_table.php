<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberInputTypesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('member_input_types', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('type', 80)->comment('類型名稱');
            $table->string('code')->comment('程式碼');
            $table->boolean('status')->comment('狀態');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        DB::table('member_input_types')->insert(array(
            'type' => 'text',
            'code' => '<input type="text">',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('member_input_types')->insert(array(
            'type' => 'checkbox',
            'code' => '<input type="checkbox">',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('member_input_types')->insert(array(
            'type' => 'radio',
            'code' => '<input type="radio">',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('member_input_types')->insert(array(
            'type' => 'textarea',
            'code' => '<textarea>{title}</textarea>',
            'status' => 1,
            'admin' => 0
        ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('member_input_types');
    }
}
