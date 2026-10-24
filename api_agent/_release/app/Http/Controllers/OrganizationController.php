<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AnnouncementMessages;
use App\Models\AnnouncementMessagesParticipants;
use App\Repositories\AgentRepository;
use App\REDIS;
use Hash;
use Validator;
use DB;

class OrganizationController extends Controller
{
    /**
     * organization/dropdown
     * -組織列表 
     *
     * @bodyParam id integer 營運商系統編號 Example: 1
     * @bodyParam username string 帳號 Example: xxx
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
    public function dropdownList(Request $request)
    {
        try {            
            $myAgentID = $request->ID;

            $rep = new AgentRepository();
            $queryAgent =  $rep->getById($myAgentID);

            $isSuperAdmin =  $rep->isSuperAdminOrSubAccount($myAgentID);

            $query = DB::table("organizations as o");

            if(!$isSuperAdmin)
            {
                //非超級admin的話，只能查特定的
                $query =  $query->where("o.id" ,  $queryAgent->organization_id);
            }

            $result = $query->select("o.id" , "o.name")->get();               

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result,
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


    private function messages()
    {
        return [                       
            'type.min' => __('error_message.1503'),
            'type.required' => __('error_message.1503'),
            'id.digits_between' => __('error_message.2018'),
        ];
    }
}