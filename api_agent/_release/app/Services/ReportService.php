<?php

namespace App\Services;

use App\Repositories\TransactionLogRepository;
use App\REDIS;
use Validator;

class ReportService extends Service
{
    protected $transactionLogRepository;

    public function __construct()
    {
        $this->transactionLogRepository = new TransactionLogRepository();
    }

    public function getSr14Report($request)
    {
        try {
            $rules = [
                'member_username' => 'required|r_alpha_num|between:0,30',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
                'page' => 'required|numeric',
                'per_page' => 'required|numeric'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                $result = [
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ];
            }

            $model = $this->transactionLogRepository->getSr14Report($request);
            $total = $model->count();
            $data = $model->orderBy('t.id', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
                                
            foreach ($data as $row) {

                $is_success_status = $row->status == 1000;
                if($row->type == 27){
                    //如果是單錢包轉帳1000是已鎖定 999是遊戲中
                    $row->is_success_status = $is_success_status;
                    $row->status_text = $is_success_status ? __('param.success') : __('param.gaming');
                    $row->remark = '';

                } else {
                    //洗分 [審核中]，在SR14 也要視為成功
                    if(!$is_success_status && $row->type == 5 && $row->status == 9)
                    {
                        $is_success_status = true;
                    }
                    //單錢包遊戲transaction_logs的remark是session_token不用顯示
                    if($row->type == 28 || $row->type == 29){
                        $row->remark = '';
                    }

                    //某些transaction_log 比較特殊，即使status 編號不為1000 也要顯示為 成功
                    $row->is_success_status = $is_success_status;
                    $row->status_text = $is_success_status ? __('param.success') : __('param.fail');
                }
            }

            $result = [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ];
            return $result;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            $result = [
                'code' => $info[0],
                'message' => $info[2]
            ];
            return $result;
        }
    }

    private function messages()
    {
        return [
            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'member_id.digits_between' => __('error_message.1067'),

            'agent_username.r_alpha_num' => __('error_message.1025'),
            'agent_username.between' => __('error_message.1024'),

            'member_username.r_alpha_num' => __('error_message.1025'),
            'member_username.between' => __('error_message.1024'),

            'member_nickname.between' => __('error_message.2024'),

            'transaction_no.digits_between' => __('error_message.1310'),

            'event_id.required' => __('error_message.1902'),
            'event_id.digits_between' => __('error_message.1903'),

            'source.required' => __('error_message.2035'),
            'source.in' => __('error_message.2035'),

            'start_date.required' => __('error_message.2011'),
            'start_date.date_format' => __('error_message.2013'),

            'end_date.required' => __('error_message.2012'),
            'end_date.date_format' => __('error_message.2014'),
            'end_date.after' => __('error_message.2015'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}