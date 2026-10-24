<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAdvertisementTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('advertisement_types', function (Blueprint $table) {
            $table->integer('language_id')
                ->after('path_id')
                ->comment('語系系統編號');
            $table->integer('country_id')
                ->after('language_id')
                ->nullable()
                ->default(0)
                ->comment('國家系統編號');
            $table->integer('agent_id')
                ->after('country_id')
                ->nullable()
                ->default(0)
                ->comment('代理系統編號');
        });

        Schema::table('advertisements', function (Blueprint $table) {
            $table->dropColumn([
                'language_id',
                'country_id',
                'agent_id'
            ]);
        });
    }
}
