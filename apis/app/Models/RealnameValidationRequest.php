<?php

declare(strict_types=1);

namespace App\Models;

use App\Facades\FileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $member_id
 * @property int|null $validator_id 審核人員ID
 * @property string $realname 真實姓名
 * @property int|null $bank_id 銀行id
 * @property string $bank_name 銀行名稱
 * @property string $bank_account 銀行帳號
 * @property string|null $id_number 證件號碼
 * @property string|null $birthday 出生日期
 * @property string|null $id_front_image 證件正面照片
 * @property string|null $id_back_image 證件反面照片
 * @property int $status 狀態, 0: 待審核, 1: 通過, 2: 拒絕
 * @property string|null $remark 備註
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $username 會員帳號
 *
 * @method static Builder<static>|RealnameValidationRequest newModelQuery()
 * @method static Builder<static>|RealnameValidationRequest newQuery()
 * @method static Builder<static>|RealnameValidationRequest query()
 *
 * @mixin \Eloquent
 */
class RealnameValidationRequest extends Model
{
    protected $table = 'realname_validation_requests';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function idFrontImage(): Attribute
    {
        return $this->imageAttribute();
    }

    protected function idBackImage(): Attribute
    {
        return $this->imageAttribute();
    }

    private function imageAttribute(): Attribute
    {
        return Attribute::make(
            set: function (?string $value, array $attributes) {
                if ($value === null || !Str::startsWith($value, 'data:')) {
                    return $value;
                }

                return FileStorage::storeBase64Image(
                    $value,
                    'kyc/' . ($attributes['member_id'] ?? 0) . '/' . Str::uuid()->toString()
                );
            }
        );
    }
}
