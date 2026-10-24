<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventInviteRecordsTable extends Migration
{
    public $set_schema_table = 'event_invite_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable($this->set_schema_table)) {
            return;
        }

        Schema::create($this->set_schema_table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('event_id')->default(0)->index()->comment('events.id');
            $table->unsignedBigInteger('member_id')->index()->comment('members.id(被邀請人)');
            $table->unsignedBigInteger('invite_member_id')->index()->comment('members.id(邀請人)');
            $table->integer('ip_duplicate')->default(0)->comment('註冊IP是否重複;1:是、0:否');
            $table->integer('phone_duplicate')->default(0)->comment('手機號碼是否重複;1:是、0:否');
            $table->integer('realname_duplicate')->default(0)->comment('真實姓名是否重複;1:是、0:否');
            $table->integer('telegram_id_duplicate')->default(0)->comment('Telegram ID是否重複;1:是、0:否');
            $table->string('block_member_id', 191)->nullable()->comment('members.id(阻擋人)');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期')
                ->index();
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->set_schema_table);
    }
}
