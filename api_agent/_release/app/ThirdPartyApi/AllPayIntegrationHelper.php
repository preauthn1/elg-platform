<?php

namespace App\ThirdPartyApi;

use App\Helpers\RandomHelper;
use App\Helpers\Common;
use App\Models\WithdrawalOrder;
use App\ThirdPartyApi\Models\ThirdPartyApiPayoutResult;
use target\support\Helpers\VendorSend;
use Exception;
use DB;

class AllPayIntegrationHelper
{
    private static $cash_flow_id = 23;
    private static $error_code = array(
        '000' => '成功',
        '101' => 'API不存在',
        '202' => '查无商户号',
        '203' => '商户停用中',
        '204' => '商户锁定中',
        '205' => '商户冻结中',
        '206' => '商户配置错误',
        '301' => 'version 参数错误',
        '302' => 'merchantNo 参数错误',
        '303' => 'request 参数错误',
        '304' => 'signMsg 错误',
        '306' => 'signMsg 不可为空',
        '401' => '验签失败',
        '402' => '加解密失败',
        '403' => '密文格式错误',
        '404' => '密文格式拼接错误',
        '999' => '系统内部错误'
    );

    private static $response_error_code = array(
        '00' => '成功',
        '01' => '订单建立错误',
        '02' => '订单建立错误',
        '20' => '公对私错误',
        '21' => '快捷支付错误',
        '22' => '扫码错误'
    );

    public static function RequestWithdrawalOrder($withdrawal_orders_id)
    {
        $result = new ThirdPartyApiPayoutResult();
        try {
            $query_order = WithdrawalOrder::where('wo.id', $withdrawal_orders_id)
                ->leftJoin('members as m', 'wo.member_id', 'm.id')
                ->select(
                    'wo.*',
                    'm.parent as agent_id'
                )
                ->first();

            $agentID = $query_order->agent_id;

            $vendor_setting = self::getVendorApiSettings($agentID);

            if ($vendor_setting == null ||
                !array_key_exists('MerchantNo' , $vendor_setting) ||
                !array_key_exists('MerchantKey' , $vendor_setting) ||
                !array_key_exists('APIUrl' , $vendor_setting)) {
                $result->is_success = false;
                $result->display_error_msg = 'agent_cash_flow info json not set / not completed. (' . $agentID . ')';
                return $result;
            }

            $url = $vendor_setting['APIUrl'] . '/payout';

            $notifyUrl = env('API_EXTERNAL_URL') . 'api/AllPay/withdrawal/callback';

            $transaction_no = RandomHelper::getOrderNoByPrefix('AP');
            $bankCode = self::convertToBankCode($query_order->bank_code, $vendor_setting['available_banks']);

            $data = array();
            $data['amount'] = $query_order->amount; //金额，限额依据商户设定，小数点两位
            $data['bankCode'] = $bankCode; //商户客户提款银行代码，请参考 5.4银行代码
            //$data['bankBranch'] = ''; //分行名称(非必傳)
            $data['bankAccountNo'] = trim($query_order->account_no); //商户客户提款银行账号
            $data['bankAcoountName'] = trim($query_order->account_name); //商户客户提款银行户名
            $data['orderNo'] = $transaction_no; //商户订单号，不能重复
            $data['orderTime'] = date('YmdHis', strtotime("now +8 hours")); //商户系统发起订单时间，格式为yyyyMMddHHmmss
            $data['notifyUrl'] = $notifyUrl; //通知商户系统订单完成网址
            $data['memberCode'] = $query_order->member_id; //商户平台会员账号，风控用途
            //MD5加密簽
            $form_body_text = self::makeRequestBody($vendor_setting, $data);

            //發出API請求
            $sender = new VendorSend();
            $response = $sender->send('AllPayWithdrawal_API', $url, 'POST', $form_body_text);
            /*
             * {
             *  version: '1.0', //系统版本号，当前最新版本为 1.0
             *  merchantNo: 'GBP111222', //商户号，注册完毕后提供，GBP 开头共九码
             *  signMsg: '...', //MD5 验签，长度为 32，为防止传输过程中被篡改，签名方法请参考 2.2 验签说明
             *  resCode: 200, //系统回应三位数代码，详细参考 5.1 商户检验回应代码表
             *  resMsg: '成功', //系统回应代码说明，详细参考 5.1 商户检验回应代码表
             *  response: ..., //请求结果内容，请参考 3. API 列表 回应的 Json，根据商户
             *                  的设定选择是否需要解密或解码，预设为 Base64 Decode 也
             *                  可向商务申请开启 RSA，存提款分开
             * }
             * */
            $json = json_decode($response, true);
            $third_party_order_no = '';
            $third_party_response = '';
            $error_code = $json['resCode'];
            $error_msg = isset(self::$error_code[$error_code])
                ? self::$error_code[$error_code]
                : '';
            if ($json['resCode'] == '000') {
                /*
                 * {
                 *   code: '0000', //四位数 API 处理代码，请参考 5.2 订单检验回应代码表，0000 代表成功，其他皆为失败
                 *   msg: '订单建立成功', //API 处理代码说明
                 *   amount: 100.00, //此笔订单的交易金额
                 *   fee: 2.00, //手续费，根据商户设定
                 *   orderNo: 'Order123456789', //商户提交的订单号
                 *   gbpOrderNo: '190101185511101', //此笔订单的我方系统订单号，不会重复
                 *   createTime: '20101231032055' //我方系统建立订单时间
                 * }
                 * */
                $response_data = json_decode(base64_decode($json['response']), true);
                $error_code = $response_data['code'];
                $result->is_success = $response_data['code'] == '0000';
                $third_party_order_no = ($result->is_success)
                    ? $response_data['gbpOrderNo']
                    : '';
                $third_party_response = json_encode($response_data);
                $error_msg = $response_data['msg'];
                $error_msg = ($error_msg == '' && isset(self::$response_error_code[substr($error_code, 0, 2)]))
                        ? self::$response_error_code[substr($error_code, 0, 2)]
                        : $error_msg;
            }

            $result->third_party_order_no = $third_party_order_no;
            $result->third_party_response = $third_party_response;

            $insert = array();
            $insert['cash_flow_id'] = self::$cash_flow_id;
            $insert['withdrawal_order_id'] = $query_order->id;
            $insert['transaction_no'] = $transaction_no;
            $insert['third_party_transaction_no'] = $third_party_order_no;
            $insert['payout_order_request_body'] = json_encode($form_body_text);
            $insert['payout_order_response'] = $third_party_response;
            $insert['is_success_payout'] = 0;

            if (!$result->is_success) {
                $insert['info_1'] = $error_code . $error_msg;
                $result->display_error_msg = $error_code . ':' . $error_msg;
            }

            //寫入一筆代付請求紀錄
            DB::table('withdrawal_payout_records')->insert($insert);
            return $result;
        } catch (Exception $exception) {
            $result->is_success = false;
            $result->display_error_msg = 'msg:' . $exception->getMessage() . ' line:' . $exception->getLine();
            return $result;
        }
    }

    private static function convertToBankCode($withdrawal_order_bank_code, $vendor_setting_support_banks)
    {
        //target bankCode 轉成 三方的bankCode
        foreach ($vendor_setting_support_banks as $each_bank) {               
            if ($each_bank["BankCode_ELG"] == $withdrawal_order_bank_code) {
                return $each_bank["BankCode_Third"];
            }
        }

        return '';
    }

    private static function makeRequestBody($info, $params)
    {
        $request = base64_encode(json_encode($params));
        $requestBody = [
            'version' => '1.0',
            'merchantNo' => $info['MerchantNo'],
            'request' => $request
        ];
        ksort($requestBody);
        $signMsg = '';
        foreach ($requestBody as $k => $v) {
            $signMsg .= $k . ':' . $v . '&';
        }
        $signMsg .= 'key:' . $info['MerchantKey'];
        $requestBody['signMsg'] = strtoupper(md5($signMsg));

        Common::log('0' , [
            'str' => $signMsg,
            'sign' => $requestBody['signMsg']
        ] , 'AllPayIntegrationHelper_md5');

        return $requestBody;
    }

    private static function getVendorApiSettings($agent_id)
    {
        $query = DB::table('agents as a')
            ->leftJoin('agent_cash_flows as acf', function ($join) {
                $join->on('a.cash_flow_group_id', 'acf.agent_group_id')
                    ->where('acf.cash_flow_id', self::$cash_flow_id);
            })
            ->where('a.id', $agent_id)
            ->select('example.invalid')
            ->first();

        if (!is_object($query) || Common::IsNullOrEmptyString($query->info)) {
            return null;
        }

        $jsonSetting = json_decode($query->info , true);
        return $jsonSetting;
    }
}