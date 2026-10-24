<?php

namespace App\Services;

use App\Repositories\AgentRepository;

class AgentService extends Service
{
    public function __construct()
    {
        $this->repository = new AgentRepository();
    }
    
	public function isChild($parentId, $childId)
    {
        return $this->repository->isChild($parentId, $childId);
    }
}
