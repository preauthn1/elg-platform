<?php

namespace App\Models;

class VendorAccountNamedLock
{
    private $lockNamed;
    private $lockTimeout = 5;
    public function __construct($memberId, $vendorId)
    {
        $this->lockNamed = sprintf("get_game_account_%d_%d", $memberId, $vendorId);
        \DB::selectFromWriteConnection("SELECT GET_LOCK(?, ?)", [$this->lockNamed, $this->lockTimeout]);
    }

    public function unLock() {
        \DB::selectFromWriteConnection("SELECT RELEASE_LOCK(?)", [$this->lockNamed]);
    }
}