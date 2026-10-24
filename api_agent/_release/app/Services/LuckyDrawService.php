<?php

namespace App\Services;

use Illuminate\Http\Request;
use App\Repositories\LuckyDrawRepository;

class LuckyDrawService extends Service
{
    public function __construct()
    {
        $this->repository = new LuckyDrawRepository();
    }

    public function dropdownList()
    {
        return $this->repository->getLuckyDrawPeriod();
    }
}
