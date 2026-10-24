<?php

namespace App;

use DB;
use App\Models\Ncpay;
use App\Helpers\Common;

class PaymentNcpay
{

    public function test()
    {
        try {
            $url = 'V1/Merchant/Hello';
            $data = array();
            return $this->post($url, $data);
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

    public function transfer()
    {
        try {
            $url = 'V1/Merchant/Transfer';
            $data = array();
            $data['orderid'] = date('YmdHis') . rand(1000, 9999);
            $data['to_bank'] = 'pbb';
            $data['to_account'] = '101302226985';
            $data['amount'] = 100;
            return $this->post($url, $data);
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

    public function transferCheck()
    {
        try {
            $url = 'V1/Merchant/TransferCheck';
            $data = array();
            $data['transferid'] = '24901';
            return $this->post($url, $data);
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

    public function transferList()
    {
        try {
            $url = 'V1/Merchant/TransferList';
            $data = array();
            return $this->post($url, $data);
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

    public function deposit($orderId, $amount, $bank)
    {
        try {
            $url = 'V1/Merchant/Deposit';
            $data = array();
            $data['orderid'] = $orderId;
            $data['amount'] = $amount;
            $data['bank'] = $bank;
            $result = $this->post($url, $data);
            if (is_array($result)) {
                $data = array();
                $data['order_id'] = substr($orderId, -7);
                $data['ncpay_status'] = $result['status'];
                $data['error'] = $result['error'];
                $data['code'] = $result['code'];
                if (isset($result['status']) && $result['status'] == 'ok') {
                    $data['ncpay_deposit_id'] = $result['data']['depositid'];
                    $data['ncpay_order_id'] = $result['data']['orderid'];
                    $data['third_party_id'] = $result['data']['thirdpartyid'];
                    $data['bank'] = $result['data']['bank'];
                    $data['amount'] = $result['data']['amount'];
                    $data['fee'] = $result['data']['fee'];
                    $data['index'] = $result['data']['index'];
                    $rs = [
                        'code' => 1000,
                        'msg' => $result['data']['redirect']
                    ];
                } else {
                    $rs = [
                        'code' => $result['status'],
                        'msg' => $result['code']
                    ];
                }
                Ncpay::create($data);
            } else {
                $rs = [
                    'code' => 9999,
                    'msg' => 'unknow'
                ];
            }
            return $rs;
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

    public function depositCheck()
    {
        try {
            $url = 'V1/Merchant/DepositCheck';
            $data = array();
            $data['depositid'] = '86835';

            return $this->post($url, $data);
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

    public function depositList()
    {
        try {
            $url = 'V1/Merchant/DepositList';
            $data = array();

            return $this->post($url, $data);
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

    public function bankList()
    {
        try {
            $url = 'V1/Merchant/DepositBankList';
            $data = array();

            return $this->post($url, $data);
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

    private function post($url, $data)
    {
        $data['apikey'] = env('NCPAY_PUBLIC_KEY');
        $data['timestamp'] = date('U');
        $user = rand(10000, 99999) . rand(10000, 99999);
        // 取簽名
        $sign = sha1($user . json_encode($data) . env('NCPAY_PRIVATE_KEY'));

        Common::log(0, $data,  'Ncpay_' . $url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('NCPAY_URL') . $url . '?user=' . $user . '&sign=' . $sign);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result,  'Ncpay_' . $url);
        return json_decode($result, true);
    }
}