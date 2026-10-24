<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterVendorTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('rate', 10, 2)
                ->after('name')
                ->comment('平台點數與遊戲點數的比例');
            $table->decimal('percentage', 10, 2)
                ->after('rate')
                ->comment('拆帳比例');
        });
    }
}
