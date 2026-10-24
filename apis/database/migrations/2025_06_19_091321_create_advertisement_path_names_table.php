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
        SchemaMonitor::create('advertisement_path_names', function (Blueprint $table) {
            $table->increments('id');
            $table->tinyInteger('language_id')->comment('語言id(languages.id)');
            $table->tinyInteger('advertisement_path_id')->comment('版面位置id(advertisement_paths.id)');
            $table->string('name', 50)->nullable()->comment('name');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('advertisement_path_names');
    }
};
