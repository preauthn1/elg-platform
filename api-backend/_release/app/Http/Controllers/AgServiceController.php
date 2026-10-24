<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\AgService;

class AgServiceController
{

    // 確認或新增帳戶
    function checkOrCreateAccount(Request $request)
    {
        if (isset($request->username) and isset($request->password)) {
            $rs = AgService::checkOrCreateAccount($request->username, $request->password, $request->oddType);
            if ((string) $rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'checkOrCreateAccount =>' . $request->username . '|' . $log_data);
        return $log_data;
    }

    // 查詢帳號餘額
    function getBalance(Request $request)
    {
        if (isset($request->username) and isset($request->password)) {
            $rs = AgService::getBalance($request->username, $request->password);
            if (is_numeric((string) $rs->attributes()->info)) {
                $log_data = '0,' . (string) $rs->attributes()->info;
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'getBalance =>' . $request->username . '|' . $log_data);
        return $log_data;
    }

    // 預備轉帳
    function prepareTransfer(Request $request)
    {
        if (isset($request->username) and isset($request->password) and isset($request->bill_no) and isset($request->type) and isset($request->credit)) {
            $rs = AgService::prepareTransfer($request->username, $request->password, $request->bill_no, $request->type, $request->credit);
            if ((string) $rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'prepareTransfer =>' . $request->username . '|' . $request->bill_no . '|' . $request->credit . '|' . $log_data);
        return $log_data;
    }

    // 確認轉帳
    function confirmTransfer(Request $request)
    {
        if (isset($request->username) and isset($request->password) and isset($request->bill_no) and isset($request->type) and isset($request->credit)) {
            $rs = AgService::confirmTransfer($request->username, $request->password, $request->bill_no, $request->type, $request->credit);
            if ((string) $rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'confirmTransfer =>' . $request->username . '|' . $request->bill_no . '|' . $request->credit . '|' . $log_data);
        return $log_data;
    }

    // 查詢轉帳狀態
    function transferStatus(Request $request)
    {
        if (isset($request->bill_no)) {
            $rs = AgService::confirmTransfer($request->bill_no);
            if ((string) $rs->attributes()->info === '0') {
                $log_data = '0,' . $rs['msg'];
            } else {
                $log_data = '1000,' . $rs['msg'];
            }
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'transferStatus =>' . $request->bill_no . '|' . $log_data);
        return $log_data;
    }

    // 取遊戲URL
    function forwardGame(Request $request)
    {
        if (isset($request->username) and isset($request->password) and isset($request->sid)) {
            $rs = AgService::forwardGame($request->username, $request->password, $request->sid, $request->game_type);
            $log_data = '0,' . $rs;
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'forwardGame =>' . $request->username . '|' . $log_data);
        return $log_data;
    }

    // 跳轉至遊戲
    function goToGame(Request $request)
    {
        if (isset($request->feature) and isset($request->id) and isset($request->type) and isset($request->stamp)) {
            AgService::api_log($this->getIp(), 'goToGame =>' . $request->id . '|' . $request->type . '|' . $request->stamp . '|' . $request->feature);
            $rs = AgService::goToGame($request->feature, $request->id, $request->type, $request->stamp);
            $log_data = $rs;
        } else {
            $log_data = '999,參數有誤';
        }
        AgService::api_log($this->getIp(), 'goToGame =>' . $request->id . '|' . $request->type . '|' . $request->stamp . '|' . $request->feature . '|' . $log_data);
        return $log_data;
    }

    function getIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && isset($_SERVER['HTTP_VIA'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
}
