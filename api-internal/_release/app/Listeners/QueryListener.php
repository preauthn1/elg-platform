<?php
namespace App\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

class QueryListener
{
    public function handle(QueryExecuted $event)
    {
        if (env('APP_ENV', 'production') == 'local') {
            $sql = str_replace('?', "'%s'", $event->sql);
            $log = vsprintf($sql, $event->bindings);
            Log::channel('sql')->info($log);
        }
    }
}
