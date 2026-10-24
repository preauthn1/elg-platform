<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentAddNeedValidateColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('email_validate')
                ->after('frontend_register')
                ->default(0)
                ->comment('電子郵件是否需要認證0:不需要,1:需要');
            $table->boolean('mobile_validate')
                ->after('email_validate')
                ->default(0)
                ->comment('電子郵件是否需要認證0:不需要,1:需要');
        });
    }
}
