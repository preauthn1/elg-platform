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
        SchemaMonitor::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->comment('標籤名稱');
            $table->tinyInteger('status')->default(1)->index()->comment('狀態 0:停用 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立時間');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新時間');
            $table->integer('admin')->default(0)->comment('最後操作人員');
        });

        SchemaMonitor::create('member_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->integer('tag_id')->default(0)->index()->comment('tags.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立時間');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新時間');
            $table->integer('admin')->default(0)->comment('最後操作人員');

            $table->unique(['member_id', 'tag_id'], 'unique_member_tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_tags');
        SchemaMonitor::dropIfExists('tags');
    }
};
