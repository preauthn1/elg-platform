<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * email 為 Office 365 SSO 的帳號綁定鍵，必須唯一（重複時綁定行為不可預期）。
     * 上線前已確認各白標 users.email 無重複資料。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
