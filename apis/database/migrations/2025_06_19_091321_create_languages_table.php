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
        SchemaMonitor::create('languages', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('name', 32)->comment('語言名稱');
            $table->string('code', 10)->comment('語言代碼');
            $table->tinyInteger('sort')->default(0)->comment('排序');
            $table->string('wcasino', 191)->nullable()->default('2')->comment('wcasino 遊戲語系');
            $table->string('acewin', 191)->nullable()->default('en-US')->comment('acewin 遊戲語系');
            $table->string('ace333sw', 191)->nullable()->default('1')->comment('ace333sw 遊戲語系');
            $table->string('rcb988', 191)->nullable()->default('en')->comment('rcb988 遊戲語系');
            $table->string('gfg2', 191)->nullable()->default('en_us')->comment('gfg2 遊戲語系');
            $table->string('bolakk4d', 191)->nullable()->default('en-us')->comment('bolakk4d 遊戲語系');
            $table->string('m8bet', 191)->nullable()->default('EN-US')->comment('m8bet 遊戲語系');
            $table->string('wbet', 191)->nullable()->default('en')->comment('wbet 遊戲語系');
            $table->string('astar', 191)->nullable()->default('en-us')->comment('astar 遊戲語系');
            $table->string('lfc888', 191)->nullable()->default('en')->comment('lfc888 遊戲語系');
            $table->string('ag', 10)->default('3')->comment('AG LANG代碼');
            $table->string('ace333', 10)->nullable()->comment('ace333遊戲語系');
            $table->string('xe88', 10)->nullable()->comment('xe88遊戲語系');
            $table->string('lucky', 10)->nullable()->comment('Lucky語系');
            $table->string('ebet', 10)->nullable()->comment('ebet語系');
            $table->string('isb', 10)->nullable()->comment('isb遊戲語系');
            $table->string('pt', 10)->nullable()->comment('pt遊戲語系');
            $table->string('sbo', 10)->nullable()->comment('sbo遊戲語系');
            $table->string('ygg', 10)->nullable()->comment('ygg遊戲語系');
            $table->string('wm', 10)->nullable()->comment('wm遊戲語系');
            $table->string('pg', 10)->nullable()->comment('pg遊戲語系');
            $table->string('ia', 10)->nullable()->comment('ia遊戲語系');
            $table->string('aes', 10)->nullable()->comment('aes遊戲語系');
            $table->string('target', 10)->nullable()->comment('pgs遊戲語系');
            $table->string('mt', 10)->nullable();
            $table->string('igs', 10)->nullable()->comment('igs遊戲語系');
            $table->string('ct', 10)->nullable()->comment('ct遊戲語系');
            $table->string('dg', 10)->nullable()->comment('dg遊戲語系');
            $table->string('rm', 191)->nullable()->comment('rm遊戲語系');
            $table->string('allbet', 10)->nullable()->comment('allbet遊戲語系');
            $table->string('terra', 10)->nullable()->comment('terra遊戲語系');
            $table->string('sa', 10)->nullable()->comment('sa遊戲語系');
            $table->string('sky1388', 5)->default('eng')->comment('sky1388 language code');
            $table->string('fishing', 191)->nullable()->comment('fishing遊戲語系');
            $table->string('npt', 191)->nullable()->comment('npt遊戲語系');
            $table->string('v8', 191)->nullable()->comment('v8遊戲語系');
            $table->string('saba', 191)->nullable()->comment('saba遊戲語系');
            $table->string('obet33', 191)->nullable()->comment('obet33遊戲語系');
            $table->string('bola2', 191)->nullable()->comment('bola2遊戲語系');
            $table->string('plus100', 191)->nullable()->comment('100Plus遊戲語系');
            $table->string('spadegaming', 191)->nullable()->comment('SpadeGaming遊戲語系');
            $table->string('ns', 191)->nullable()->comment('NextSpin遊戲語系');
            $table->string('bng', 191)->nullable()->comment('Booongo遊戲語系');
            $table->string('vsport', 191)->nullable()->comment('V-sport遊戲語系');
            $table->string('pp', 191)->nullable()->comment('PragmaticPlay遊戲語系');
            $table->string('evo', 191)->nullable()->comment('Evolution遊戲語系');
            $table->string('pgslot', 191)->nullable()->comment('PGSLOT遊戲語系');
            $table->string('lbb18', 191)->nullable()->comment('LBB18遊戲語系');
            $table->string('dct', 191)->nullable()->comment('DCT遊戲語系');
            $table->string('qtech', 191)->nullable()->comment('QTECH遊戲語系');
            $table->string('ds88', 191)->nullable()->comment('DS88遊戲語系');
            $table->string('ky', 191)->nullable()->comment('KY遊戲語系');
            $table->string('epic', 10)->nullable()->default('en-us')->comment('EPIC WIN');
            $table->string('uuslot', 191)->nullable()->comment('UUSlot遊戲語系');
            $table->string('megawin', 191)->nullable()->comment('MegaWin遊戲語系');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->string('wfsw', 191)->nullable()->comment('wfsw遊戲語系');
            $table->string('j3', 191)->default('0')->comment('j3遊戲語系');
            $table->string('clotsw', 191)->nullable()->default('en-us')->comment('clot遊戲語系');
            $table->string('wesw', 191)->nullable()->comment('Vpower遊戲語系');
            $table->string('vpower', 191)->nullable()->comment('Vpower遊戲語系');
            $table->string('cr', 191)->nullable()->comment('CR遊戲語系');
            $table->string('boracayfish', 191)->nullable()->comment('BoracayFish遊戲語系');
            $table->string('motivationsw', 191)->nullable()->comment('Vpower遊戲語系');
            $table->string('bolesw', 191)->nullable()->default('zh_CN')->comment('bole遊戲語系');
            $table->string('impsw', 191)->nullable()->default('en')->comment('impsw遊戲語系');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('languages');
    }
};
