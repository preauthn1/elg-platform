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
        SchemaMonitor::create('event_v2_detail_exclude_vendors', function (Blueprint $table) {
            $table->unsignedInteger('event_v2_detail_id');
            $table->unsignedInteger('vendor_id');

            $table->primary(['event_v2_detail_id', 'vendor_id']);
            $table->foreign('event_v2_detail_id')->references('id')->on('event_v2_details')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_v2_detail_exclude_vendors');
    }
};
