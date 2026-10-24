<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGamesTable extends Migration
{
    protected $table_name = 'games';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->default(0)->comment('遊戲商系統編號');
            $table->integer('type_id')->comment('遊戲類型');
            $table->boolean('join_bet_event')->default(0)->comment('是否可參加投注額活動，0:不可，1:可以');
            $table->boolean('join_win_event')->default(0)->comment('是否可參加贏分活動，0:不可，1:可以');
            $table->string('game_type')->index()->comment('遊戲代碼');
            $table->string('name')->comment('名稱');
            $table->decimal('lottery_pool', 11, 3)->default(0)->nullable()->comment('彩池金額');
            $table->string('image')->nullable()->comment('遊戲圖片');
            $table->boolean('is_new')->nullable()->default(0)->comment('是否為新遊戲(0:否,1:是)');
            $table->boolean('is_hot')->nullable()->default(0)->comment('是否為熱門遊戲(0:否,1:是)');
            $table->boolean('open_window')->default(0)->comment('是否為另開視窗(0:否,1:是)');
            $table->tinyInteger('status')->default(0)->comment('狀態：0停用，1啟用');
            $table->integer('sort')->nullable()->default(0)->comment('排序');
            $table->text('info')->nullable()->comment('info');
            $table->string('remark')->nullable()->comment('備註');
            $table->text('memo')->nullable()->comment('備註');
            $table->decimal('valid_bet_rate', 4, 2)->nullable()->default(1.00)->comment('有效投注轉換比例');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['vendor_id', 'game_type']);
        });

        DB::table($this->table_name)->update([
            'vendor_id' => 1,
            'type_id' => 2,
            'game_type' => 'ag',
            'name' => 'ag',
            'admin' => 0
        ]);

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
