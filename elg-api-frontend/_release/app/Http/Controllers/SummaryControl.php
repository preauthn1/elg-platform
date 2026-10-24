<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SummaryControlLog;
use App\Models\SummaryControlTable;
use App\Models\TotalBetRecord;

class SummaryControl extends Controller
{
	/**
	 *
	 */
	public function list(Request $request)
	{
		
	}
	
	/**
	 *
	 */
    public function show(Request $request)
    {
    	
    }
    
	/**
	 *
	 */
    public function create(Request $request)
    {
    	$model = new SummaryControlTable();
    	
    	$data = array();
    	$data['vendor_id'] = 1;
    	$data['vendor_code'] = 'ABC';
    	$data['stat_start_time'] = date("Y-m-d");
    	$data['stat_end_time'] = date("Y-m-d");
    	$data['exec_start_time'] = date("Y-m-d");
    	$data['exec_end_time'] = date("Y-m-d");
    	
    	
    	$model = new TotalBetRecord();
    	
    	$data = array();
    	$data['summary_time'] = date("Y-m-d");
    	$data['member_id'] = 1;
    	$data['vendor_id'] = 2;
    	$data['game_id'] = 3;
    	$data['reference_no'] = 'abc123';
    	$data['bet'] = 1.0001;
    	$data['valid_bet'] = 2.0002;
    	$data['win_lose'] = 3.0003;
    	$data['bet_time'] = date("Y-m-d");;
    	$data['payout_time'] = date("Y-m-d");;
    	$data['admin'] = 4;
    	
    	$result = $model->create($data);
    	
    	return $result;
    }
    
	/**
	 *
	 */
    public function update(Request $request)
    {
    	
    }
    
	/**
	 *
	 */
    public function delete(Request $request)
    {
    	
    }
}
