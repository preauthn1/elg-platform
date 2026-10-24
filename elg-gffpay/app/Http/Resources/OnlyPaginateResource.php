<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @mixin LengthAwarePaginator
 */
class OnlyPaginateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = $this->total();
        $pages = $this->lastPage();
        $current = $this->currentPage();

        return [
            /**
             * @var int 單頁筆數
             *
             * @example 10
             */
            'per_page' => $this->perPage(),
            /**
             * @var int 總筆數
             *
             * @example 100
             */
            'total' => $total,
            /**
             * @var int 總頁數
             *
             * @example 5
             */
            'pages' => $pages,
            /**
             * @var int 上一頁數
             *
             * @example 1
             */
            'previous' => ($current != 1 && $total > 0) ? ($current - 1) : '',
            /**
             * @var int 當前頁數
             *
             * @example 2
             */
            'current' => $current,
            /**
             * @var int 下一頁數
             *
             * @example 3
             */
            'next' => ($pages > $current) ? ($current + 1) : '',
        ];
    }
}
