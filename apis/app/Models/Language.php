<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property string $name 語言名稱
 * @property string $code 語言代碼
 * @property int $sort 排序
 * @property string|null $wcasino wcasino 遊戲語系
 * @property string|null $acewin acewin 遊戲語系
 * @property string|null $ace333sw ace333sw 遊戲語系
 * @property string|null $rcb988 rcb988 遊戲語系
 * @property string|null $gfg2 gfg2 遊戲語系
 * @property string|null $bolakk4d bolakk4d 遊戲語系
 * @property string|null $m8bet m8bet 遊戲語系
 * @property string|null $wbet wbet 遊戲語系
 * @property string|null $astar astar 遊戲語系
 * @property string|null $lfc888 lfc888 遊戲語系
 * @property string $ag AG LANG代碼
 * @property string|null $ace333 ace333遊戲語系
 * @property string|null $xe88 xe88遊戲語系
 * @property string|null $lucky Lucky語系
 * @property string|null $ebet ebet語系
 * @property string|null $isb isb遊戲語系
 * @property string|null $pt pt遊戲語系
 * @property string|null $sbo sbo遊戲語系
 * @property string|null $ygg ygg遊戲語系
 * @property string|null $wm wm遊戲語系
 * @property string|null $pg pg遊戲語系
 * @property string|null $ia ia遊戲語系
 * @property string|null $aes aes遊戲語系
 * @property string|null $target pgs遊戲語系
 * @property string|null $mt
 * @property string|null $igs igs遊戲語系
 * @property string|null $ct ct遊戲語系
 * @property string|null $dg dg遊戲語系
 * @property string|null $rm rm遊戲語系
 * @property string|null $allbet allbet遊戲語系
 * @property string|null $terra terra遊戲語系
 * @property string|null $sa sa遊戲語系
 * @property string $sky1388 sky1388 language code
 * @property string|null $fishing fishing遊戲語系
 * @property string|null $npt npt遊戲語系
 * @property string|null $v8 v8遊戲語系
 * @property string|null $saba saba遊戲語系
 * @property string|null $obet33 obet33遊戲語系
 * @property string|null $bola2 bola2遊戲語系
 * @property string|null $plus100 100Plus遊戲語系
 * @property string|null $spadegaming SpadeGaming遊戲語系
 * @property string|null $ns NextSpin遊戲語系
 * @property string|null $bng Booongo遊戲語系
 * @property string|null $vsport V-sport遊戲語系
 * @property string|null $pp PragmaticPlay遊戲語系
 * @property string|null $evo Evolution遊戲語系
 * @property string|null $pgslot PGSLOT遊戲語系
 * @property string|null $lbb18 LBB18遊戲語系
 * @property string|null $dct DCT遊戲語系
 * @property string|null $qtech QTECH遊戲語系
 * @property string|null $ds88 DS88遊戲語系
 * @property string|null $ky KY遊戲語系
 * @property string|null $epic EPIC WIN
 * @property string|null $uuslot UUSlot遊戲語系
 * @property string|null $megawin MegaWin遊戲語系
 * @property int $status 狀態 0:停用,1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property string|null $wfsw wfsw遊戲語系
 * @property string $j3 j3遊戲語系
 * @property string|null $clotsw clot遊戲語系
 * @property string|null $wesw Vpower遊戲語系
 * @property string|null $vpower Vpower遊戲語系
 * @property string|null $cr CR遊戲語系
 * @property string|null $boracayfish BoracayFish遊戲語系
 * @property string|null $motivationsw Vpower遊戲語系
 * @property string|null $bolesw bole遊戲語系
 * @property string|null $impsw impsw遊戲語系
 * @property string|null $tthsw tthsw遊戲語系
 *
 * @method static Builder<static>|Language newModelQuery()
 * @method static Builder<static>|Language newQuery()
 * @method static Builder<static>|Language query()
 *
 * @mixin \Eloquent
 */
class Language extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
