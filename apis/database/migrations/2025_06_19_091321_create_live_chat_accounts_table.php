<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('live_chat_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->unique()->comment('會員系統編號');
            $table->timestamp('creation_date')->useCurrentOnUpdate()->useCurrent()->comment('response creation_date');
            $table->integer('license_id')->default(0)->index()->comment('LiveChat license id');
            $table->string('access_token', 191)->comment('response access_token');
            $table->string('entity_id', 191)->comment('response entity_id');
            $table->integer('expires_in')->comment('response expires_in');
            $table->string('token_type', 191)->comment('response token_type');
            $table->string('lc_cid', 191)->comment('cookie lc_cid');
            $table->string('lc_cst', 191)->comment('cookie lc_cst');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('live_chat_accounts');
    }
};
