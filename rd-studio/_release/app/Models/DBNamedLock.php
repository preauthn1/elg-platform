<?php


namespace App\Models;


class DBNamedLock
{
    private $lockNamed;
    private $lockTimeout = 5;
    public function __construct($name, $timeout=5)
    {
        $this->lockNamed = sprintf($name);
        $this->lockTimeout = $timeout;
        \DB::selectFromWriteConnection("SELECT GET_LOCK(?, ?)", [$this->lockNamed, $this->lockTimeout]);
    }

    public function unLock() {
        \DB::selectFromWriteConnection("SELECT RELEASE_LOCK(?)", [$this->lockNamed]);
    }
}