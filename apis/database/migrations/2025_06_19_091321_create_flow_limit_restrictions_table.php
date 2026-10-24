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
        SchemaMonitor::create('flow_limit_restrictions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('flow_limit_id')->index()->comment('稽核流水編號');
            $table->integer('vendor_id')->comment('遊戲商編號');
            $table->integer('type_id')->index()->comment('遊戲類別編號');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('flow_limit_restrictions');
    }
};
