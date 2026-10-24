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
        SchemaMonitor::create('organization_languages', function (Blueprint $table) {
            $table->tinyInteger('organization_id')->comment('organization.id');
            $table->tinyInteger('language_id')->comment('languages.id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('organization_languages');
    }
};
