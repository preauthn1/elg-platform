<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $file_name
 * @property string $file_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|ProcedureHash newModelQuery()
 * @method static Builder<static>|ProcedureHash newQuery()
 * @method static Builder<static>|ProcedureHash query()
 *
 * @mixin \Eloquent
 */
class ProcedureHash extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
