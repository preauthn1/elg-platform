<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n\Segments;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\Report\Sr02nSegmentEnum;
use Illuminate\Database\Query\Builder;

/**
 * SR02N 四段 UNION 的單一段（對應 SR02 的一個 builder）。
 */
interface Sr02nSegmentInterface
{
    public function segment(): Sr02nSegmentEnum;

    /** 本段在此篩選下是否併入 UNION（不併＝不查，不是查出來再丟） */
    public function enabled(Sr02nFilter $filter): bool;

    /** 列表：欄位依 {@see AbstractSr02nSegment::COLUMNS} 順序與別名輸出 */
    public function listQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder;

    /** 合計：回一列 total_in／total_out／total_tax／total_actual */
    public function totalQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder;
}
