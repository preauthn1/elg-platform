<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLoginEventColumn20220114 extends Migration
{
    protected $table_name = 'login_events';
    protected $column_name = 'vendor_id';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->column_name = 'title';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string($this->column_name, 255)
                    ->after('id')
                    ->comment('登入活動名稱');
            });
        }
        $this->column_name = 'content';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->longText($this->column_name)
                    ->after('title')
                    ->comment('登入活動內容');
            });
        }
        $this->column_name = 'vendor_id';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string($this->column_name, 255)
                    ->after('repeat_hour')
                    ->nullable()
                    ->comment('試玩廠商');
            });
        }
        $this->column_name = 'admin';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name)
                    ->after('updated_at')
                    ->default(0)
                    ->comment('更新者ID');
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
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
    }
}
