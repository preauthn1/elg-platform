<?php

namespace App\Http\Controllers;

use App\Models\AgentAnnouncement;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\Models\Agent;
use App\Helpers\SQLHelper;
use Validator;
use Exception;
use DB;
use log;

class AnnouncementController extends Controller
{
    public function list(Request $request)
    {
        try {
            $model = new Announcement();
            $model = $model->where('status', 1);

            if (isset($request->type) && $request->type != 0) {
                $model = $model->where('type', $request->type);
            }
            $total = $model->count();

            $data = $model->orderBy('id', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function view(Request $request)
    {
        try {
            $model = new Announcement();
            $data = $model->find($request->id);

            $agent_ids = AgentAnnouncement::where('announcement_id', $request->id)->pluck('agent_id')->toArray();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ['announcement' => $data, 'agent_ids' => $agent_ids],
            ]);
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function create(Request $request)
    {
        try {
            $agent_ids = $request->agent_ids;
            $data = array();
            $data['type'] = $request->type_id;
            $data['content'] = $request->announcement_content;
            $data['status'] = 1;
            $data['admin'] = $request->ID;
            $addAnnouncement = Announcement::create($data);
            if ($addAnnouncement) {
                foreach ($agent_ids as $agent_id) {
                    $data = array();
                    $data['agent_id'] = $agent_id;
                    $data['announcement_id'] = $addAnnouncement->id;
                    $data['status'] = 1;
                    $data['admin'] = $request->ID;
                    AgentAnnouncement::create($data);
                }

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function update(Request $request)
    {
        try {
            $model = Announcement::find($request->id);
            $agent_ids = $request->agent_ids;
            $data = array();
            $data['type'] = $request->type_id;
            $data['content'] = $request->announcement_content;
            $data['admin'] = $request->ID;
            $updateAnnouncement = $model->update($data);
            if ($updateAnnouncement) {
                $deleteSql = AgentAnnouncement::where('announcement_id', $request->id)->delete();
                if ($deleteSql) {
                    foreach ($agent_ids as $agent_id) {
                        $data = array();
                        $data['agent_id'] = $agent_id;
                        $data['announcement_id'] = $request->id;
                        $data['status'] = 1;
                        $data['admin'] = $request->ID;
                        AgentAnnouncement::create($data);
                    }
                }
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function delete(Request $request)
    {
        try {
            $model = Announcement::find($request->id);
            $updateAnnouncement = $model->update(['status' => 0]);
            if ($updateAnnouncement) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getGeneralAgent(Request $request)
    {
        try {
            $child = $general_agent = array();
            $sql = Agent::where('parent', 1)->where('host_id', 0);
            $general_agent = $sql->select('id', 'username')->get();
            if (count($general_agent) > 0) {
                $general_agent_ids = $sql->pluck('id')->toArray();
                $general_agent_child = Agent::whereIn('host_id', $general_agent_ids)
                    ->select('id', 'parent', 'username')
                    ->get();
                if (count($general_agent_child) > 0) {
                    foreach ($general_agent_child as $row) {
                        $child[$row['parent']][] = $row;
                    }
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ['host' => $general_agent, 'child' => $child]
            ]);
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function showByAgentId(Request $request)
    {
        try {
            if ($request->HOST != 1) {
                $model = new AgentAnnouncement();
                $data = $model->join('announcements as a', function ($join) {
                    $join->on('a.id', 'agent_announcements.announcement_id')
                        ->where('a.status', 1);
                })
                    ->where('agent_announcements.agent_id', $request->ID)
                    ->whereIn('agent_announcements.status', [1, 2])
                    ->orderBy('a.id', 'DESC')
                    ->select('a.*')
                    ->limit(100)
                    ->get();
            } else {
                $model = new Announcement();
                $data = $model->where('status', 1)->orderBy('id', 'DESC')->limit(100)->get();
            }

            //把這個代理的訊息都設定為已讀
            DB::table('agent_announcements')
                ->where('agent_id', $request->ID)
                ->where('status', 1)
                ->update(['status' => 2]);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function checkUnread(Request $request)
    {
        try {
            $model = new AgentAnnouncement();

            $data = $model->where('agent_id', $request->ID)
                ->where('status', 1)
                ->count();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateAgentAnnouncementStatus(Request $request)
    {
        try {
            $model = AgentAnnouncement::where('announcement_id', $request->id)->where('agent_id', $request->ID)->get();

            if (count($model) > 0) {
                $updateAnnouncement = $model->update(['status' => 2]);
                if (!$updateAnnouncement) {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
            ]);

        } catch (QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}