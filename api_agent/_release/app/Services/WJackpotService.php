<?php

namespace App\Services;

use Illuminate\Http\Request;
use App\Repositories\WJackpotRepository;

class WJackpotService extends Service
{
    public function __construct()
    {
        $this->repository = new WJackpotRepository();
    }

    public function dropdownList()
    {
        return $this->repository->getWJackpotPeriod();
    }
}
