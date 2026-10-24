<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\VendorInfo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vendor_info_id
 * @property int $member_id
 * @property string $username
 * @property int $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Member|null $member
 * @property-read VendorInfo|null $vendorInfo
 *
 * @mixin \Eloquent
 */
class RgswAccount extends AbstractAccount
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected ?string $vendorUsernameColumn = 'username';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function vendorInfo(): BelongsTo
    {
        return $this->belongsTo(VendorInfo::class, 'vendor_info_id');
    }
}
