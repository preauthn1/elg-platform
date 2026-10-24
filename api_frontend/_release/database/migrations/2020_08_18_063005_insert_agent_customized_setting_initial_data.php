<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentCustomizedSettingInitialData extends Migration
{ 
    
    protected $table_name = 'agent_customized_setting';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable($this->table_name)) {


            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 2001,
                    'sub_category' => '',
                    'value' => '1',
                    'memo' => '是否顯示客服icon',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 2002,
                    'sub_category' => '',
                    'value' => 'https://example.invalid/601119006244',
                    'memo' => '客服連結',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 3001,
                    'sub_category' => '1',
                    'value' => 'V-Point',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 3001,
                    'sub_category' => '2',
                    'value' => '威幣',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 3001,
                    'sub_category' => '3',
                    'value' => '威币',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 3001,
                    'sub_category' => '4',
                    'value' => 'V-Point',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 3001,
                    'sub_category' => '5',
                    'value' => 'V-Point',
                    'memo' => '錢包文字',
                ));


            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 1,
                    'setting_key' => 3001,
                    'sub_category' => '6',
                    'value' => 'V-Point',
                    'memo' => '錢包文字',
                ));



            //二號樣板 (越南站)
            //二號樣板 (越南站)
            //二號樣板 (越南站)
            //二號樣板 (越南站)

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 2001,
                    'sub_category' => '',
                    'value' => '0',
                    'memo' => '是否顯示客服icon',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 2002,
                    'sub_category' => '',
                    'value' => '',
                    'memo' => '客服連結',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 3001,
                    'sub_category' => '1',
                    'value' => 'M-Point',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 3001,
                    'sub_category' => '2',
                    'value' => 'M幣',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 3001,
                    'sub_category' => '3',
                    'value' => 'M币',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 3001,
                    'sub_category' => '4',
                    'value' => 'M-Point',
                    'memo' => '錢包文字',
                ));

            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 3001,
                    'sub_category' => '5',
                    'value' => 'M-Point',
                    'memo' => '錢包文字',
                ));


            DB::table($this->table_name)
                ->insert(array(
                    'template_id' => 2,
                    'setting_key' => 3001,
                    'sub_category' => '6',
                    'value' => 'M-Point',
                    'memo' => '錢包文字',
                ));
            
        }
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