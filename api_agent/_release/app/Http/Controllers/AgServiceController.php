<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AgService;

class AgServiceController extends Controller
{

    //確認或新增帳戶
    function checkOrCreateAccount(Request $request)
    {
        if (isset($request->username) AND isset($request->password)) {
            $ag = new AgService();
            $rs = $ag->checkOrCreateAccount($request->username, $request->password, $request->oddType);
            var_dump($rs);
            if ((string)$rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'checkOrCreateAccount =>' . $request->username . '|' . $log_data);
        return $log_data;
    }

    //查詢帳號餘額
    function getBalance(Request $request)
    {
        if (isset($request->username) AND isset($request->password)) {
            $ag = new AgService();
            $rs = $ag->getBalance($request->username, $request->password);
            if (is_numeric((string)$rs->attributes()->info)) {
                $log_data = '0,' . (string)$rs->attributes()->info;
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'getBalance =>' . $request->username . '|' . $log_data);
        return $log_data;
    }

    //預備轉帳
    function prepareTransfer(Request $request)
    {
        if (isset($request->username) AND isset($request->password) AND isset($request->bill_no) AND isset($request->type) AND isset($request->credit)) {
            $ag = new AgService();
            $rs = $ag->prepareTransfer($request->username, $request->password, $request->bill_no, $request->type, $request->credit);
            if ((string)$rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'prepareTransfer =>' . $request->username . '|' . $request->bill_no . '|' . $request->credit . '|' . $log_data);
        return $log_data;
    }

    //確認轉帳
    function confirmTransfer(Request $request)
    {
        if (isset($request->username) AND isset($request->password) AND isset($request->bill_no) AND isset($request->type) AND isset($request->credit)) {
            $ag = new AgService();
            $rs = $ag->confirmTransfer($request->username, $request->password, $request->bill_no, $request->type, $request->credit);
            if ((string)$rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'confirmTransfer =>' . $request->username . '|' . $request->bill_no . '|' . $request->credit . '|' . $log_data);
        return $log_data;
    }

    //查詢轉帳狀態
    function transferStatus(Request $request)
    {
        if (isset($request->bill_no)) {
            $ag = new AgService();
            $rs = $ag->confirmTransfer($request->bill_no);
            if ((string)$rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'transferStatus =>' . $request->bill_no . '|' . $log_data);
        return $log_data;
    }

    //取遊戲URL
    function forwardGame(Request $request)
    {
        if (isset($request->username) AND isset($request->password) AND isset($request->sid)) {
            $ag = new AgService();
            $rs = $ag->forwardGame($request->username, $request->password, $request->sid, $request->game_type);
            $log_data = '0,' . $rs;
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'forwardGame =>' . $request->username . '|' . $log_data);
        return $log_data;
    }

    //跳轉至遊戲
    function goToGame(Request $request)
    {
        if (isset($request->feature) AND isset($request->id) AND isset($request->type) AND isset($request->stamp)) {
            $ag = new AgService();
            $ag->api_log($this->getIp(), 'goToGame =>' . $request->id . '|' . $request->type . '|' . $request->stamp . '|' . $request->feature);
            $rs = $ag->goToGame($request->feature, $request->id, $request->type, $request->stamp);
            $log_data = $rs;
        } else {
            $log_data = '999,參數有誤';
        }
        $ag->api_log($this->getIp(), 'goToGame =>' . $request->id . '|' . $request->type . '|' . $request->stamp . '|' . $request->feature . '|' . $log_data);
        return $log_data;
    }

}
