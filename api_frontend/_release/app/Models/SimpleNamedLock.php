<?php

namespace App\Models;

class SimpleNamedLock
{
    private $lockNamed;
    private $lockTimeout = 10;
    public function __construct($filename, $lockId, $key)
    {
        $this->lockNamed = sprintf("%s_%d_%s", $filename, $lockId, $key);
        \DB::selectFromWriteConnection("SELECT GET_LOCK(?, ?)", [$this->lockNamed, $this->lockTimeout]);
    }

    public function unLock() {
        \DB::selectFromWriteConnection("SELECT RELEASE_LOCK(?)", [$this->lockNamed]);
    }
}