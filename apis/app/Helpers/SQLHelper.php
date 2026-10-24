<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class SQLHelper
{
    public static function insertIgnore(string $tableName, array $datas): int
    {
        if (empty($datas)) {
            return 0;
        }

        $fields = array_keys($datas[0]);
        $bindingSegments = '(' . implode(', ', array_map(function () {
            return '?';
        }, $fields)) . ')';

        $sql = "INSERT IGNORE INTO {$tableName} (" . implode(',', $fields) . ')';
        $values = [];
        $bindings = [];
        foreach ($datas as $row) {
            foreach ($row as $v) {
                $bindings[] = $v;
            }
            $values[] = $bindingSegments;
        }
        $sql .= ' VALUES ' . implode(', ', $values);
        $affectedRows = DB::affectingStatement($sql, $bindings);

        return $affectedRows;
    }
}
