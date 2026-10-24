<?php

namespace App;

class AgentChild
{
    private $id = '';

    public function __construct($parentId)
    {
        $this->id = $parentId;
    }

    public function listChild($parentId = null)
    {
        static $result = array();
        if (!isset($parentId) && isset($result[$this->id])) {
            return $result;
        }
        $parentId = $parentId ? $parentId : $this->id;
        $result[$this->id][] = $parentId;
        $rows = Agent::where('parent', $parentId)->get();
        foreach ($rows as $row) {
            $this->listChild($row['id']);
        }
        return $result;
    }
}