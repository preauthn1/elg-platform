<?php

namespace App\ThirdPartyApi;

use App\Helpers\RandomHelper;
use App\Models\WithdrawalOrder;
use App\Helpers\Common;
use App\ThirdPartyApi\Models\ThirdPartyApiPayoutResult;
use DB;

class GoldPayIntegrationHelper
{
    private static $cash_flow_id = 14;
    private static $withdrawal_type = 712;
    private static $error_code = array(
        '20001' => '发送模式错误',
        '20002' => '不接受的 content-type',
        '20003' => '没有取得参数',
        '20004' => '参数异常',
        '20005' => '没有取得商号',
        '20041' => '没有取得 sign',
        '20042' => 'sign 不相符',
        '20091' => '币别禁用',
        '20092' => '权限禁用',
        '20093' => '商户禁用',
        '20094' => '交易类型禁用',
        '20095' => '平台 API 线禁用',
        '20096' => '商户 API 线禁用',
        '20097' => '商户单点 API 禁用',
        '21011' => '没有取得商户平台订单号',
        '21012' => '商户平台订单号长度小于 1 字元',
        '21013' => '商户平台订单号长度大于 20 字元',
        '21014' => '商户平台订单号重复',
        '21016' => '没有取得支付类型',
        '21017' => '支付类型不是数字',
        '21018' => '支付类型不存在',
        '21019' => '支付类型未启用',
        '21020' => '支付类型错误',
        '21021' => '没有取得回调连结',
        '21022' => '回调连结长度大于 100 字元',
        '21026' => '没有取得跳转连结',
        '21027' => '跳转连结长度大于 100 字元',
        '21031' => '没有取得金额',
        '21032' => '金额不是数字',
        '21033' => '金额小于单笔最小量',
        '21034' => '金额大于单笔最大量',
        '21035' => '金额大于单日交易最大量',
        '21036' => '没有取得客户端 IP',
        '21037' => '客户端 IP 长度大于 40 字元',
        '21041' => '没有取得时间戳记',
        '21042' => '时间戳记不是数字',
        '21046' => '没有取得附言',
        '21047' => '附言长度大于 100 字元',
        '21071' => '起始时间长度大于 19 字元',
        '21072' => '起始时间格式错误',
        '21076' => '截止时间长度大于 19 字元',
        '21077' => '截止时间格式错误',
        '21081' => '页数不是数字',
        '21086' => '行数不是数字',
        '21087' => '行数小于 1',
        '21088' => '行数大于 1000',
        '21101' => '没有取得收款人开户姓名',
        '21102' => '收款人开户姓名长度小于 1 字元',
        '21103' => '收款人开户姓名长度大于 50 字元',
        '21106' => '没有取得收款人银行帐号',
        '21107' => '收款人银行帐号长度小于 1 字元',
        '21108' => '收款人银行帐号长度大于 20 字元',
        '21111' => '没有取得银行编号',
        '21112' => '银行编号不是数字',
        '21113' => '银行编号不存在',
        '21114' => '银行编号未启用',
        '21115' => '银行编号错误',
        '21116' => '没有取得开户支行',
        '21117' => '开户支行长度小于 1 字元',
        '21118' => '开户支行长度大于 20 字元',
        '21121' => '没有取得开户行所在省份',
        '21122' => '开户行所在省份长度小于 1 字元',
        '21123' => '开户行所在省份长度大于 20 字元',
        '21126' => '没有取得开户行所在城市',
        '21127' => '开户行所在城市长度小于 1 字元',
        '21128' => '开户行所在城市长度大于 20 字元',
        '21136' => '不允许的 IP',
        '30001' => '商户不存在',
        '30002' => '商户没有开通此交易渠道',
        '30003' => '商户没有设定此交易渠道',
        '30004' => '商户没有开通此交易类型',
        '30011' => '交易单建立失败',
        '30020' => '商户余额不足',
        '30021' => '平台 API 没有支援此币别',
        '30100' => '支付通道阻塞',
        '30901' => '订单失效',
        '30906' => '登入失败',
        '30907' => '餘額不足',
        '30911' => '验证失败',
        '30916' => '交易失败',
        '30921' => '交易超时',
        '90001' => '维护中',
        '90091' => 'API IP 跳转过多',
        '90092' => 'API IP 在黑名单中',
        '90093' => '没有提供收无银台模式',
        '90901' => '金流通道回传错误格式',
        '90902' => '金流通道没有回传结果',
        '90903' => '金流通道回传失败',
        '90904' => '金流通道没有回传连结',
        '90905' => '金流通道回传连结为空',
        '99999' => '没有此 API'
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
                !array_key_exists('api_url' , $vendor_setting) ||
                !array_key_exists('merchant_no' , $vendor_setting) ||
                !array_key_exists('merchant_id' , $vendor_setting) ||
                !array_key_exists('secret_key' , $vendor_setting)) {
                $result->is_success = false;
                $result->display_error_msg = 'agent_cash_flow info json not set / not completed. (' . $agentID . ')';
                return $result;
            }

            $url = $vendor_setting['api_url'] . '/applyfor';

            $notifyUrl = env('API_EXTERNAL_URL') . 'api/GoldPay/withdrawal/callback';

            $transaction_no = RandomHelper::getOrderNoByPrefix("GP");
            $bankCode = self::convertToATPayBankCode($query_order->bank_code, $vendor_setting['available_banks']);

            $post = array();
            $post['uid'] = $vendor_setting['merchant_id'];  //商户ID
            $post['orderid'] = $transaction_no;  //商户平台生成的订单号
            $post['channel'] = self::$withdrawal_type;  //支付类型
            $post['notify_url'] = $notifyUrl;  //异步通知地址
            $post['amount'] = $query_order->amount;  //商家提交金额，接受小数点后两位, 但三方會直接捨去小數
            $post['userip'] = '0.0.0.0';  //商家会员 ip 地址
            $post['timestamp'] = time();  //GMT 时间戳
            $post['custom'] = '';  //自定义
            $post['bank_account'] = trim($query_order->account_name);  //收款人开户姓名
            $post['bank_no'] = trim($query_order->account_no);  //收款人银行帐号
            $post['bank_id'] = $bankCode;
            $post['bank_province'] = '';  //开户行所在省份
            $post['bank_city'] = '';  //开户行所在城市
            $post['bank_sub'] = '';  //开户支行

            //字母順序排序
            ksort($post);
            $form_body_text = "";
            $loopIndex = 0;

            //串成&拼接的文字
            foreach ($post as $key => $vale) {
                $loopIndex++;

                if ($loopIndex > 1) {
                    $form_body_text .= '&';
                }
                $form_body_text .= $key . '=' . $vale;
            }
            $beforeMD5 = $form_body_text . '&key=' . $vendor_setting['secret_key'];

            //MD5加密簽
            $sign_value = strtoupper(md5($beforeMD5));
            $post['sign'] = $sign_value;  //32 位大写 MD5 签名值
            Common::log('0' , [
                'str' => $beforeMD5,
                'sign' => $sign_value
            ] , 'GoldPayIntegrationHelper_md5');

            //組出最後的postBody
            $form_body_text = $form_body_text . '&sign=' . $sign_value;

            //發出API請求
            $api_response = self::curlPost($url, $form_body_text);
            $result->is_success = $api_response['status'] == 10000;
            $third_party_order_no = ($result->is_success)
                ? $api_response['result']['transactionid']
                : '';
            $result->third_party_order_no = $third_party_order_no;

            $result->third_party_response = json_encode($api_response);

            $insert = array();
            $insert['cash_flow_id'] = self::$cash_flow_id;
            $insert['withdrawal_order_id'] = $query_order->id;
            $insert['transaction_no'] = $transaction_no;
            $insert['third_party_transaction_no'] = $third_party_order_no;
            $insert['payout_order_request_body'] = $form_body_text;
            $insert['payout_order_response'] = $result->third_party_response;
            $insert['is_success_payout'] = 0;

            if (!$result->is_success) {
                $msg = isset(self::$error_code[$api_response['status']]) ? self::$error_code[$api_response['status']] : '';
                $insert['info_1'] = $api_response['status'] . $msg;
                $result->display_error_msg = $msg;
            }

            //寫入一筆代付請求紀錄
            DB::table('withdrawal_payout_records')->insert($insert);
            return $result;
        } catch (\Exception $e) {
            $result->is_success = false;
            $result->display_error_msg = 'msg:' . $e->getMessage() . ' line:' . $e->getLine();
            return $result;
        }
    }

    private static function convertToATPayBankCode($withdrawal_order_bank_code, $vendor_setting_support_banks)
    {
        //target bankCode 轉成 三方的bankCode
        foreach ($vendor_setting_support_banks as $each_bank) {               
            if ($each_bank["BankCode_ELG"] == $withdrawal_order_bank_code) {
                return $each_bank["BankCode_Third"];
            }
        }

        return '';
    }

    private static function curlPost($url, $form_body)
    {
        Common::log('0', $form_body, 'GoldPayIntegrationHelper_Before');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form_body);

        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/x-www-form-urlencoded'
            )
        );

        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);

        curl_close($ch);
        $result = json_decode($result, true);

        Common::log('0', $result, 'GoldPayIntegrationHelper_After');
        return $result;
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