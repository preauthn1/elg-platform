<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterDomainLogoTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_domains', function (Blueprint $table) {
            $table->string('logo_pc')
                ->nullable()
                ->after('template_id')
                ->comment('電腦版Logo');
            $table->string('logo_mobile')
                ->nullable()
                ->after('logo_pc')
                ->comment('手機版Logo');
            $table->string('title')
                ->nullable()
                ->after('logo_mobile')
                ->comment('網站名稱');
        });
    }
}
