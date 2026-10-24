<?php

namespace App;

use App\Models\SmsSendRecord;
use App\Helpers\Common;
use App\Services\TelegramService;
use DB;

class SmsApi
{
    /* 使用簡訊商Infobip發送簡訊 */
    public function sendByInfobip($memberId, $mobile, $text, $sms_gateway)
    {
        try {
            $data = array();
            $url = $sms_gateway->url . 'sms/1/text/single';
            $data['from'] = 'InfoSMS';
            $data['to'] = $mobile;
            $data['text'] = '+' . $text;

            //寫入簡訊紀錄
            $record_id = $this->addSmsSendRecord($memberId, $mobile, $data['text'], $sms_gateway->sms_gateway_id);

            $result = $this->postToInfobip($url, $data, $sms_gateway);

            $transaction_id = '';
            $status = 2;
            if (is_array($result)) {
                if (isset($result['messages'])) {
                    $rs = [
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ];
                    $status = ($result['messages'][0]['status']['id'] == 7) ? 1 : 2;
                } else {
                    $rs = [
                        'code' => $result->code,
                        'message' => $result->msg
                    ];
                }
            } else {
                $rs = [
                    'code' => 9999,
                    'message' => 'unknow'
                ];
            }

            $data = json_encode($result);
            //更新簡訊紀錄
            $this->updateSmsSendRecord($record_id, $status, $data, $transaction_id);

            return $rs;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    #infobip 簽名
    private function getInfobipSign($info)
    {
        $json = json_decode($info);
        $user = isset($json->user) ? $json->user : '';
        $password = [REDACTED] ? $json->password : '';
        $string = $user . ':' . $password;
        return base64_encode($string);
    }

    private function postToInfobip($url, $data, $sms_gateway)
    {
        Common::log(0, $data, 'infobip');
        $data_string = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization:Basic ' . $this->getInfobipSign($sms_gateway->info)
        ));
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'infobip');
        curl_close($ch);
        return json_decode($result, true);
    }

    /* 使用簡訊商SMS-Get發送簡訊 */
    public function sendBySmsGet($memberId, $mobile, $text, $sms_gateway)
    {
        try {
            $data = array();
            $url = $sms_gateway->url . 'api_send.php';
            $data['method'] = 1; //1:立即發送，2:預約發送
            $data['sms_msg'] = '++' . $text;
            $data['phone'] = $mobile;

            $json = json_decode($sms_gateway->info);
            $data['username'] = isset($json->username) ? $json->username : '';
            $data['password'] = isset($json->password) ? $json->password : '';

            //寫入簡訊紀錄
            $record_id = $this->addSmsSendRecord($memberId, $mobile, $data['sms_msg'], $sms_gateway->sms_gateway_id);
            $result = $this->postToSmsGet($url, $data);
            $transaction_id = '';
            $status = 2;
            if (is_array($result)) {
                if ($result['stats'] == 1) {
                    $rs = [
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ];
                    $status = 1;
                } else {
                    $rs = [
                        'code' => 9999,
                        'message' => 'unknow'
                    ];
                }
            } else {
                $rs = [
                    'code' => 9999,
                    'message' => 'unknow'
                ];
            }
            $data = json_encode($result);

            //更新簡訊紀錄
            $this->updateSmsSendRecord($record_id, $status, $data, $transaction_id);

            return $rs;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function postToSmsGet($url, $data)
    {
        Common::log(0, $data, 'sms-get');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ((strtolower(substr($url, 0, 5)) == 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'sms-get');
        curl_close($ch);
        return json_decode($result, true);
    }

    /* 使用簡訊商SMS-WORKS發送簡訊 */
    public function sendBySmsWorks($memberId, $mobile, $text, $sms_gateway)
    {
        try {
            $data = array();
            $url = $sms_gateway->url . 'ReceiveSMS.aspx';
            $data['ContentType'] = 1; //1:ASCII text, 2:UCS formatted
            $data['MsgText'] = '+++' . $text;
            $data['MobileNumber'] = $mobile;

            $json = json_decode($sms_gateway->info);
            $data['username'] = isset($json->username) ? $json->username : '';
            $data['password'] = isset($json->password) ? $json->password : '';

            //寫入簡訊紀錄
            $record_id = $this->addSmsSendRecord($memberId, $mobile, $data['MsgText'], $sms_gateway->sms_gateway_id);
            $result = $this->postToSmsWorks($url, $data, $sms_gateway);
            $transaction_id = '';
            $status = 2;
            if (is_array($result)) {
                $transaction_id = $result[1];
                if ($result[0] == 1) {
                    $rs = [
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ];
                    $status = 1;
                } else {
                    $rs = [
                        'code' => 9999,
                        'message' => 'unknow'
                    ];
                }
            } else {
                $rs = [
                    'code' => 9999,
                    'message' => 'unknow'
                ];
            }

            $data = json_encode($result);
            //update sms send records
            $this->updateSmsSendRecord($record_id, $status, $data, $transaction_id);

            return $rs;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function postToSmsWorks($url, $data)
    {
        Common::log(0, $data, 'sms-works');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ((strtolower(substr($url, 0, 5)) == 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'sms-works');
        curl_close($ch);
        return explode(':', $result);
    }

    /* 使用簡訊商1Code發送簡訊 */
    public function sendByOneCode($memberId, $mobile, $text, $sms_gateway)
    {
        try {
            $data = array();
            $url = $sms_gateway->url;

            $json = json_decode($sms_gateway->info);

            $data['method'] = 1; //1:立即發送, 2:預約發送
            $data['sms_msg'] = $text;
            $data['sms_msg'] = isset($json->prefix) ? $json->prefix . ' ' . $text : $text;
            $data['phone'] = $mobile;
            $data['email'] = isset($json->email) ? $json->email : '';
            $data['secret'] = isset($json->secret) ? $json->secret : '';

            $left_credit_alert = isset($json->left_credit_alert) ? $json->left_credit_alert : 100;

            //寫入簡訊紀錄
            $record_id = $this->addSmsSendRecord($memberId, $mobile, $data['sms_msg'], $sms_gateway->sms_gateway_id);
            $result = $this->postToOneCode($url, $data, $sms_gateway);
            $transaction_id = '';
            $status = 2;
            /*
             * stats        True/False 發送成功或失敗
             * error_code   000 成功
             *              001 參數錯誤
             *              002 預約時間錯誤
             *              003 預約時間已過期
             *              004 發送名稱錯誤
             *              005 發送名稱過長
             *              006 發送名稱非正確店號
             *              101 帳號不存在
             *              102 帳號已停用
             *              103 Secret 錯誤
             *              104 IP 位址不允許
             *              201 無任何點數
             *              202 號碼錯誤或所屬國家未開通
             *              203 該國家點數不足
             *              204 無正確收件者
             *              205 收件者超過 250 筆
             * error_msg    發送結果說明，請參考 error_code
             * data         發送成功時提供
             *              sms_id 訊息 ID
             *              sent_num 發送比數
             *              access_ip 存取 IP
             *              use_credit_list 使用點數及剩餘點數
             * */
            $code = 9999;
            $message = 'unknow';
            if (is_array($result)) {
                if ($result['stats'] == true) {
                    $data = $result['data'];
                    $transaction_id = $data['sms_id'];
                    $status = 1;

                    //低於警告值(預設100)時發送餘額不足通知
                    $use_credit_list = $data['use_credit_list'][0];
                    if ($use_credit_list['left_credits'] < $left_credit_alert) {
                        $message = '【1code餘額不足】 ' .
                            '國家名稱:' . $use_credit_list['country_name'] . '(' . $use_credit_list['country_code'] . ') ' .
                            '電話前綴:' . $use_credit_list['prefix'] .
                            '目前餘額為：' . $use_credit_list['left_credits'];
                        $tgService = new TelegramService();
                        $tgService->sendMessage($message, 'warn');
                    }
                    $code = 1000;
                    $message = __('error_message.1000');
                } else {
                    $message = $result['error_msg'];
                }
            }

            $data = json_encode($result);
            //update sms send records
            $this->updateSmsSendRecord($record_id, $status, $data, $transaction_id);

            return [
                'code' => $code,
                'message' => $message
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function postToOneCode($url, $data)
    {
        $action = '1Code';
        Common::log(0, $data, $action);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ((strtolower(substr($url, 0, 5)) == 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);

        Common::log(0, ['request' => $data, 'response' => $result], $action);
        curl_close($ch);
        return json_decode($result, true);
    }

    /* 使用簡訊商chuanxsms發送簡訊 */
    public function sendByChuanxSms($memberId, $mobile, $text, $sms_gateway)
    {
        try {
            $data = array();
            $url = $sms_gateway->url . 'batch/v2';
            $json = json_decode($sms_gateway->info);

            //应用key
            $data['appkey'] = isset($json->appkey) ? $json->appkey : '';
            //应用秘钥
            $data['appsecret'] = isset($json->appsecret) ? $json->appsecret : '';
            $data['appcode'] = isset($json->appcode) ? $json->appcode : '';
            //手机号码, 短信内容长度不超过1000个字（包括1000字）,每个英文或阿拉伯字符算1个字
            //Q:电话需要带国码? A:0084加手机号码
            $data['phone'] = isset($mobile) ? '00' . $mobile : '';
            //下发短信内容
            $data['msg'] = $text;
            //扩展号，该参数是显示在接收手机上的主叫尾号，可用于上行信息匹配，若不填写则认为不进行扩展（不填写不影响发送）
            $data['extend'] = '';

            //寫入簡訊紀錄
            $record_id = $this->addSmsSendRecord($memberId, $mobile, $data['msg'], $sms_gateway->sms_gateway_id);
            $result = $this->postToChuanxSms($url, $data, $sms_gateway);
            $transaction_id = '';
            $status = 2;
            /*
             * code   00000	提交成功
             *        F0001	参数appkey未填写
             *        F0002	参数appcode未填写
             *        F0003	参数phone未填写
             *        F0004	参数sign未填写
             *        F0005	参数timestamp未填写
             *        F0006	appkey不存在
             *        F0007	账号已经关闭
             *        F0008	sign检验错误
             *        F0009	账号下没有业务
             *        F0010	业务不存在
             *        F0011	手机号码超过1000个
             *        F0012	timestamp不是数字
             *        F0013	timestamp过期超过5分钟
             *        F0014	请求ip不在白名单内
             *        F0015	余额不足
             * */
            $code = 9999;
            $message = 'unknow';
            //{"code":"00000","desc":"提交成功","uid":"fe0304d3aae84c7ea69e92fc873c5517","result":[{"status":"00000","phone":"84586061296","desc":"提交成功"}]}
            if (is_array($result)) {
                if ($result['code'] == '00000') {
                    $transaction_id = $result['uid'];
                    $status = 1;
                    $code = 1000;
                    $message = __('error_message.1000');
                } else {
                    $message = $result['code'] . ' ' . $result['desc'];
                }
            }
            $this->updateSmsSendRecord($record_id, $status, json_encode($result), $transaction_id);

            return [
                'code' => $code,
                'message' => $message
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function postToChuanxSms($url, $data)
    {
        $action = 'ChuanxSms';
        $url .= '?' . http_build_query($data);
        Common::log(0, $url, $action);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ((strtolower(substr($url, 0, 5)) == 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);

        Common::log(0, ['request' => $data, 'response' => $result], $action);
        curl_close($ch);
        return json_decode($result, true);
    }

    public function sendByICT($memberId, $mobile, $code, $sms_gateway)
    {
        try {
            $data = array();
            $url = $sms_gateway->url;

            $json = json_decode($sms_gateway->info);

            $data['action'] = 'send_otp';
            $data['destination'] = $mobile;
            $data['otp'] = $code;

            //$left_credit_alert = isset($json->left_credit_alert) ? $json->left_credit_alert : 100;

            //寫入簡訊紀錄
            $record_id = $this->addSmsSendRecord($memberId, $mobile, $data['otp'], $sms_gateway->sms_gateway_id);
            $result = $this->postToICT($url, $data, $sms_gateway);
            $transaction_id = '';
            $status = 2;
            $code = 9999;
            $message = 'unknow';
            if ($result == 200) {

                $data = $result['data'];
                $transaction_id = $data['sms_id'];
                $status = 1;

                //低於警告值(預設100)時發送餘額不足通知

                $code = 1000;
                $message = __('error_message.1000');


            } else {
                $message = $result;
            }

            $data = json_encode($result);
            //update sms send records
            $this->updateSmsSendRecord($record_id, $status, $data, $transaction_id);

            return [
                'code' => $code,
                'message' => $message
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function postToICT($url, $data)
    {
        $action = 'postToICT';
        Common::log(0, $data, $action);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ((strtolower(substr($url, 0, 5)) == 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 对认证证书来源的检查
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpcode == 200) {
            \Log::info($action, ['request' => $data, 'response' => $result]);
        } else {
            \Log::error($action, ['request' => $data, 'response' => $result]);
        }
        curl_close($ch);
        return $httpcode;
    }

    private function addSmsSendRecord($member_id, $mobile, $text, $sms_gateway_id)
    {
        try {
            $record_data = array();
            $record_data['sms_gateway_id'] = $sms_gateway_id;
            $record_data['member_id'] = $member_id;
            $record_data['mobile'] = $mobile;
            $record_data['message_content'] = $text;

            $sms_send_record = SmsSendRecord::create($record_data);
            return $sms_send_record->id;
        } catch (\Illuminate\Database\QueryException $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function updateSmsSendRecord($record_id, $status, $data, $transaction_id = '')
    {
        if ($record_id != false) {
            SmsSendRecord::find($record_id)->update(
                [
                    'status' =>  $status,
                    'response_message' => $data,
                    'transaction_id' => $transaction_id
                ]
            );
        }
    }
}
