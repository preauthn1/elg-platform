<?php

declare(strict_types=1);

namespace App\Models;

class SimpleNamedLock
{
    private $lockNamed;

    private $lockTimeout = 10;

    public function __construct(string $key)
    {
        $this->lockNamed = $key;
        \DB::selectFromWriteConnection('SELECT GET_LOCK(?, ?)', [$this->lockNamed, $this->lockTimeout]);
    }

    public function unLock()
    {
        \DB::selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$this->lockNamed]);
    }
}
