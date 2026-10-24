<?php


namespace target\support\Helpers\Utils;

/*
 * DB query debug usage :
 * Utils::startDebugQueries();
 * ...
 * ...
 * ...
 * Utils::logDebugQueries("------------------")
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Debug
{
    /**
     * 啟用 Query debug
     */
    public static function startDebugQueries()
    {
        DB::enableQueryLog();
    }

    /**
     * 打印 Query
     *
     * @param string $prefix
     */
    public static function logDebugQueries(string $prefix)
    {
        $queries = Utils::parseQueries(DB::getQueryLog());
        Log::debug($prefix, ["queries"=>$queries]);
    }

    /**
     * 解析 Queries 為綁定參數後的sql
     *
     * @param  $queries
     * @return array
     */
    public static function parseQueries($queries)
    {
        function getPureSql($sql, $binds)
        {
            $result = "";

            $sql_chunks = explode('?', $sql);
            foreach ($sql_chunks as $key => $sql_chunk) {
                if (isset($binds[$key])) {
                    $result .= $sql_chunk . '"' . $binds[$key] . '"';
                } else {
                    $result .= $sql_chunk;
                }
            }

            return $result;
        }
        $result = [];
        foreach ($queries as $row) {
            $result[] = getPureSql($row['query'], $row['bindings']);
        }
        return $result;
    }
}
