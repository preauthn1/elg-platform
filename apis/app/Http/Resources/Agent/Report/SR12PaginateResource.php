<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Report;

use Illuminate\Http\Resources\Json\ResourceCollection;

class SR12PaginateResource extends ResourceCollection
{
    protected $summary;

    protected $pagination;

    public function __construct($resource, $summary, $pagination)
    {
        parent::__construct($resource);
        $this->summary = $summary;
        $this->pagination = $pagination;
    }

    public function toArray($request)
    {
        return [
            'summary' => $this->summary,
            'items' => $this->collection,
            'pagination' => $this->pagination,
        ];
    }
}
