<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\Advertisement;
use App\Models\AnnouncementMessagesReads;
use App\Repositories\AgentRepository;
use App\REDIS;
use Validator;
use App\Helpers\Common;
use DB;

/**
 * @group Advertisements
 */
class AnnouncementMessagesController extends Controller
{

    /**
     * announcement-messages/list
     * - (前台)群發公告列表   (目前只會回復單筆資料)
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  [
     *        {
     *        },
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $rep = new AgentRepository();
            
            $queryMyAgent = $rep->getAgentByMemberID($request->ID);
            $agentIDs_hierarchy = $this->getAgentArrayByHierarchy($queryMyAgent->hierarchy);
            $member_id = $request->ID;

            $utcNow = gmdate('Y-m-d H:i:s');

            $query = DB::table('announcement_messages as am')
                ->join('announcement_messages_participants as ap', 'am.id', 'ap.announcement_message_id')
                ->leftJoin('announcement_messages_reads as a_read', function ($join) use ($member_id) {
                    $join->on('am.id', 'a_read.announcement_message_id')
                        ->where('a_read.member_id', $member_id);
                })
                ->where('am.status', 1)
                ->where('am.start_time', '<', $utcNow)
                ->where('am.end_time', '>', $utcNow)
                ->where(function ($query) use ($agentIDs_hierarchy, $queryMyAgent, $member_id) {
                    $query->where([['ap.type', 1], ['ap.chat_group_id', $queryMyAgent->chat_group_id]])
                        ->orWhere(function ($que) use ($agentIDs_hierarchy) {
                            $que->where('ap.type', 2)
                                ->WhereIn('ap.hierarchy_agent_id', $agentIDs_hierarchy);
                        })
                        ->orWhere([['ap.type', 3], ['ap.single_agent_id', $queryMyAgent->id]])
                        ->orWhere([['ap.type', 4], ['ap.member_id', $member_id]])
                        ->orWhere([['ap.type', 99], ['ap.organization_id', $queryMyAgent->organization_id ]]);
                })
                ->whereNull('a_read.member_id') //未讀代表null
                ->select(
                    'am.id as announcement_message_id',
                    'am.title',
                    'am.message',
                    'am.created_at',
                    'ap.type'
                )
                ->orderBy('am.created_at', 'ASC')
                ->first();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $query
            ]);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * announcement-messages/read
     * - 設置群發公告已讀
     *
     * @bodyParam announcement_message_id integer required 群發訊息編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  [
     *        {
     *           
     *        },
     *    ]
     * }
     */
    public function read(Request $request)
    {
        try {
            if (!isset($request->announcement_message_id) || $request->announcement_message_id == 0) {
                response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => ""
                ]);
            }

            $member_id = $request->ID;

            $queryExist = AnnouncementMessagesReads::where('announcement_message_id', $request->announcement_message_id)
                ->where('member_id', $member_id)
                ->first();

            if (!is_object($queryExist)) {
                $insert = array();
                $insert["announcement_message_id"] = $request->announcement_message_id;
                $insert["member_id"] = $member_id;
                AnnouncementMessagesReads::create($insert);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ""
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

    private function getAgentArrayByHierarchy($agentHierarchy)
    {
        $agentHierarchy = str_replace("[", "", $agentHierarchy);
        $agentHierarchy = str_replace("]", "", $agentHierarchy);
        $array = explode(",", $agentHierarchy);
        $array = array_diff($array, ["-1", "-2"]);
        return $array;
    }

    private function messages()
    {
        return [
            'path_id.required' => __('error_message.1103'),
            'path_id.digits_between' => __('error_message.1104')
        ];
    }
}
