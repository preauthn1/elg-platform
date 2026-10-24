<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 列表排序方向（與 ElTable sort-change 的 ascending / descending 對應，前端轉成 asc / desc 送入）。
 */
enum SortDirectionEnum: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
