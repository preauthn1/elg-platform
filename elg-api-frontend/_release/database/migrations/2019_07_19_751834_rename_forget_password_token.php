<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameForgetPasswordToken extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('forget_password_tokens', 'validate_tokens');
        Schema::table('validate_tokens', function (Blueprint $table) {
            $table->tinyInteger('type_id')
                ->after('member_id')
                ->default(0)
                ->comment('類型(十位數-1:資料驗證2:忘記密碼3:找回密碼，個位數-1:手機2:Email)');
        });
    }
}
