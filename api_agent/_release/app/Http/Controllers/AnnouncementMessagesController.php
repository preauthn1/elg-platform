<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AnnouncementMessages;
use App\Models\AnnouncementMessagesParticipants;
use App\Repositories\AgentRepository;
use App\Repositories\AnnouncementMessagesRepository;
use App\REDIS;
use Hash;
use Validator;
use DB;

class AnnouncementMessagesController extends Controller
{

    /**
     * announcement-messages/list
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {            
            $myAgentID = $request->ID;

            $rep = new AgentRepository();
            $queryAgent =  $rep->getById($myAgentID);
            $isSuperAdmin =  $rep->isSuperAdminOrSubAccount($myAgentID);

            //目前先寫成
            // 1. 超級管理員->可以查全部
            // 2. 其他帳號->只能看到自己組織            
            $query = DB::table("announcement_messages as am")            
                ->join('announcement_messages_participants as ap', 'am.id', 'ap.announcement_message_id')
                ->leftJoin('organizations as o', 'ap.organization_id', 'o.id')
                ->leftJoin('agents as a', 'ap.hierarchy_agent_id', 'a.id')
                ->leftJoin('agents as op', 'am.created_by', 'op.id')
                ->where("am.status" , 1);

            if(!$isSuperAdmin)
            {
                $query = $query->where("am.organization_id" ,  $queryAgent->organization_id);
            }

            //類型 1: 針對chat_group  2. 針對代理樹狀  3. 針對單一代理  4. 針對特定Member  99. 針對組織 
            $total = $query->count();            
            $result = $query->select("am.*" , "ap.type as participants_type" , "a.username as target_name" , "op.username as op_username" , "o.name as organization_name")
                        ->orderBy('am.id', 'DESC')
                        ->skip(($request->page - 1) * $request->per_page)
                        ->take($request->per_page)
                        ->get();                                  
            
            foreach($result as $item)
            {
                //統一顯示在target_name 方便後台顯示
                if($item->participants_type == 99)
                {
                    $item->target_name =  $item->organization_name;
                }
            }


            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
            

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }           
    }


    /**
     * announcement-messages/create
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          
     *        }
     *    ]
     * }
     */
    public function create(Request $request)
    {
        try {            
            $model = new AnnouncementMessages();            

            $validator = Validator::make($request->all(), $model->rules_create_and_participants, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if($request->type == 99)
            { 
                $validatorTypeOfOrg = Validator::make($request->all(), $model->rules_typeOf_organization_id, $this->messages());

                if (!$validatorTypeOfOrg->passes()) {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                        'data' => $validatorTypeOfOrg->errors()
                            ->all()
                    ]);
                }                
            }

            if($request->type == 2)
            { 
                $validatorTypeOfHierarchy = Validator::make($request->all(), $model->rules_typeOf_hierarchy_agent_id, $this->messages());

                if (!$validatorTypeOfHierarchy->passes()) {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                        'data' => $validatorTypeOfHierarchy->errors()
                            ->all()
                    ]);
                }                
            }

            $myAgentID = $request->ID;
            
            $rep = new AgentRepository();
            $queryAgent =  $rep->getById($myAgentID);

            $insert = array();
            $insert["title"] = $request->title;
            $insert["message"] = $request->message;
            $insert["memo"] = $request->memo;
            $insert["organization_id"] = $queryAgent->organization_id;     
            $insert["start_time"] = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $insert["end_time"] = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'));

            //如果有選擇發送平台 所屬組織以介面中選擇的為主
            if(isset($request->organization_id) && $request->organization_id > 0)
            {
                $insert["organization_id"] = $request->organization_id;
            }

            $insert["created_by"] = $request->ID;
            $insert["updated_by"] = $request->ID;
            $insert["status"] = 1;

            $result = AnnouncementMessages::create($insert);

            $insert_parti = array();
            $insert_parti["announcement_message_id"] = $result->id;
            $insert_parti["type"] = $request->type;
            $insert_parti["chat_group_id"] = $this->returnDefinedValueOrNull($request->chat_group_id);
            $insert_parti["hierarchy_agent_id"] =  $this->returnDefinedValueOrNull($request->hierarchy_agent_id);
            $insert_parti["single_agent_id"] = $this->returnDefinedValueOrNull( $request->single_agent_id);
            $insert_parti["member_id"] =  $this->returnDefinedValueOrNull($request->member_id);
            $insert_parti["organization_id"] =  $this->returnDefinedValueOrNull($request->organization_id);

            if(AnnouncementMessagesParticipants::create($insert_parti))
            {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            }
            else
            {                    
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),
                ]);
            }

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }           
    }



     /**
     * announcement-messages/delete
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          
     *        }
     *    ]
     * }
     */
    public function delete(Request $request)
    {
        try {            

            if (!isset($request->announcement_message_id) || $request->announcement_message_id <= 0) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => ""
                ]);
            }
            
            $rep = new AnnouncementMessagesRepository();

            $rep->DeleteMessage($request->announcement_message_id);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }           
    }




    private function returnDefinedValueOrNull($value)
    {
        if(isset($value) && $value != 0)
            return $value;
            
        return null;
    }



    private function messages()
    {
        return [                       
            'type.min' => __('error_message.1503'),
            'type.required' => __('error_message.1503'),
            'id.digits_between' => __('error_message.2018'),
        ];
    }
}