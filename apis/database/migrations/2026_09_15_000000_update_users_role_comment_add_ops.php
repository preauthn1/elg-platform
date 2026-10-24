<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * users.role 新增角色 3（運維，UserRoleEnum::OPS），欄位 comment 同步。
 *
 * 只改 comment，型別／default 原樣重述（`change()` 是整句 MODIFY COLUMN，少寫一個屬性就是靜默改掉），
 * comment-only 變更走 ALGORITHM=INSTANT、不 rebuild；users 是小表，MDL 風險可忽略。
 * SchemaMonitor 被跳過時是靜默的，所以改完必須讀回 comment 斷言生效。
 */
return new class extends Migration
{
    private const string NEW_COMMENT = '角色 1:admin,2:superadmin,3:ops(運維)';

    private const string OLD_COMMENT = '角色 1:admin,2:superadmin';

    public function up(): void
    {
        SchemaMonitor::table('users', function (Blueprint $table) {
            $table->tinyInteger('role')->default(1)->comment(self::NEW_COMMENT)->change();
        });

        $this->assertRoleComment(self::NEW_COMMENT);
    }

    public function down(): void
    {
        SchemaMonitor::table('users', function (Blueprint $table) {
            $table->tinyInteger('role')->default(1)->comment(self::OLD_COMMENT)->change();
        });

        $this->assertRoleComment(self::OLD_COMMENT);
    }

    private function assertRoleComment(string $expected): void
    {
        $actual = DB::selectOne(
            'SELECT COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'role'],
        )?->comment;

        if ($actual !== $expected) {
            throw new RuntimeException("users.role comment 未生效（SchemaMonitor 可能靜默跳過），實際：{$actual}");
        }
    }
};
