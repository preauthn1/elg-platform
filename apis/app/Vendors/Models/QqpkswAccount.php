<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QqpkswAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QqpkswAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QqpkswAccount query()
 *
 * @mixin \Eloquent
 */
class QqpkswAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
