<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\Traits\DistributedLock;
use App\Helpers\Traits\GetContextTrait;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use DistributedLock;
    use GetContextTrait;
}
