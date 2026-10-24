<?php

namespace App\Repositories;

use App\Models\AnnouncementMessages;


class AnnouncementMessagesRepository extends Repository
{
    /**
     * AgentRepository constructor.
     * @param AnnouncementMessages $model
     */
    public function __construct()
    {
        $this->model = new AnnouncementMessages();
    }


    public function DeleteMessage($message_id)
    { 
        $find = $this->model::where("id" , $message_id)->first();

        if(!is_object($find))
        {
            return;
        }

        $update = array();
        $update["status"] = 0;

        $find->update($update);     
    }


}