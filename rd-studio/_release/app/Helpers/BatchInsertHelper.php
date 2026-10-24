<?php


namespace App\Helpers;


use DB;

class BatchInsertHelper {
    private $batchSize;
    public function __construct($batchSize = 1000)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * 批次新增數據
     * @param $datas array 數據field and value
     * @param $tableName string 資料表名
     * @return int 新增數據量
     */
    public function insertBatch($datas, $tableName) {
        $affectedRows = 0;
        $total = sizeof($datas);
        $batchTotal = ceil($total / $this->batchSize);
        for($i = 1; $i <= $batchTotal; $i ++) {
            $index = ($i - 1) * $this->batchSize;
            $batchData = array_slice($datas, $index, $this->batchSize);
            $affectedRows += $this->insertIgnore($batchData, $tableName);
        }
        return $affectedRows;
    }

    private function insertIgnore($datas, $tableName) {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO ".$tableName." (".join(',', $fields).")";
        $values = array();
        $bindings = array();
        foreach($datas as $row) {
            foreach($row as $v) {
                $bindings[] = $v;
            }
            $values[] = '('.implode(', ', array_map(function(){return "?";}, $row)).')';
        }
        $sql .= " VALUES " . implode(', ', $values);
        $affectedRows = DB::affectingStatement($sql, $bindings);
        //printf("dataCount=[%d], affectedRows=[%d]\n", sizeof($datas), $affectedRows);
        return $affectedRows;

    }
}