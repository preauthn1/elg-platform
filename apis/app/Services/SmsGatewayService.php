<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SmsGatewayInfoData;
use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Models\SmsSendRecord;
use Exception;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * 流程
 * 1. 建立 SmsSendRecord
 * 2. 封裝 SmsSendData 調用 $smsGateway 發送簡訊
 * 3. 更新 SmsSendRecord 狀態
 *
 * ⚠️ **`SmsGateways\Suppliers\*Gateway` 的「零引用」不等於死代碼——禁止靠靜態掃描刪除。**
 *
 * 這些 class 全部由 {@see self::sendSms()} 以 `sms_gateways.code`（經 `sms_gateway_infos.sms_gateway_id`
 * 關聯取得——**`sms_gateway_infos` 本身沒有 `code` 欄**）動態組出完整 class name 後 `new`，
 * 因此**全 repo 不會有任何一處 `use`／`new` 直接引用它們**，IDE 與 grep 一律回報零引用。
 *
 * 判某支 Gateway 死活必須**三道閘全過**，缺一不可（2026-07-28 實測：21 支全數卡關，一支都不能刪）：
 *
 * 1. **白標聯集**：跨全部白標連線盤該 code 在 `sms_gateway_infos` 有無配置列；
 * 2. **繼承鏈**：本支是否為其他 Gateway 的父類——現有三組
 *    `CxGateway extends ChuanxGateway`、`N88Gateway extends LaafficGateway`、
 *    `TopyingGateway extends TrfGateway`。父類即使自身零配置也不可刪
 *    （例：`chuanx` 生產六站零配置，但子類 `cx` 在 Alfawin77／Yes2Win 每天在發）；
 * 3. **Dev 環境**：Dev 的 `sms_gateway_infos` 是否仍有啟用配置
 *    （例：`local` 6 筆／5 啟用、`wa` 1 筆啟用——生產全零，砍了卻會廢掉開發測試通道）。
 *
 * 誤刪的後果是該站發簡訊直接拋 `InvalidArgumentException`。
 */
class SmsGatewayService
{
    /**
     * @throws Exception
     */
    public function sendSms(SmsGatewayInfoData $smsGatewayInfoData, $member, $mobile, $content, $type): SmsResultData
    {
        // lad('$smsGatewayInfoData', $smsGatewayInfoData);

        // CHUANX
        // WhatsApp

        $memberId = $member->id;

        // class 必須使用 code 命名且符合 PSR-4 命名規範
        // ⚠️ 此處是 Suppliers\*Gateway 的唯一引用點（動態組名）——刪 Gateway 前必讀 class docblock
        $gatewayCode = $smsGatewayInfoData->smsGatewayInfo->code;
        $gatewayClass = 'App\Services\SmsGateways\Suppliers\\' . ucfirst(strtolower($gatewayCode)) . 'Gateway';
        Log::debug('gatewayClass: ' . $gatewayClass);

        if (!class_exists($gatewayClass)) {
            throw new InvalidArgumentException("Unsupported SMS Gateway: {$gatewayCode}");
        }

        // 寫入簡訊紀錄
        // sms_gateway_info_id 與 cost 是「這一則實際套用了哪一組費率、當下多少錢」的唯一紀錄（target/tickets#4424）：
        // 同一家簡訊商在同一個通道組可以有多國費率，事後只靠 sms_gateway_id 反推不出唯一那一列，
        // 彙總就會把所有國家的單價一起算進去。成本在此凍結後，彙總直接加總 cost，不再反推。
        $smsSendRecord = SmsSendRecord::create([
            'sms_gateway_id' => $smsGatewayInfoData->smsGatewayInfo->sms_gateway_id,
            'sms_gateway_info_id' => $smsGatewayInfoData->smsGatewayInfo->id,
            'cost' => $smsGatewayInfoData->smsGatewayInfo->cost,
            'mobile' => $mobile,
            'message_content' => $content,
            'agent_id' => ($type === 1) ? $memberId : 0,
            'member_id' => ($type === 2) ? $memberId : 0,
        ]);

        $smsGateway = new $gatewayClass;

        $sendData = SmsSendData::from([
            'member' => $member,
            'smsGatewayInfo' => $smsGatewayInfoData->smsGatewayInfo,
            'mobile' => $mobile,
            'content' => $content,
        ]);

        try {
            /** @var SmsResultData $result */
            $result = $smsGateway->send($sendData);
        } catch (Exception $e) {
            // 例外時補更新紀錄狀態，避免 smsSendRecord 停留在未發送狀態
            $smsSendRecord->update([
                'status' => 2,
                'response_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (!$result instanceof SmsResultData) {
            $smsSendRecord->update(['status' => 2]);

            return SmsResultData::from(['status' => 2, 'response' => 'Invalid result', 'transactionId' => '']);
        }

        /**
         * 更新簡訊發送記錄
         */
        $smsSendRecord->update([
            'status' => $result->status, // 發送狀態; 0:未發送, 1:發送成功, 2:發送失敗
            'response_message' => $result->response,
            'transaction_id' => $result->transactionId,
        ]);

        return $result;
    }
}
