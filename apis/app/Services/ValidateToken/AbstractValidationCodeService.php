<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\CacheKeys\CacheKeys;
use App\Data\SmsGatewayInfoData;
use App\Enums\EmailTextTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Enums\SmsGatewayEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Exceptions\EmailTemplateNotFoundException;
use App\Exceptions\SmsGatewayException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\SmsGatewayInfo;
use App\Models\SmsText;
use App\Services\AgentService;
use App\Services\EmailGatewayService;
use App\Services\EmailTextService;
use App\Services\MemberAccessTokenService;
use App\Services\MemberFailCountService;
use App\Services\MemberService;
use App\Services\SmsGatewayService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Throwable;

abstract class AbstractValidationCodeService
{
    public function __construct(
        protected AgentService $agentService,
        protected MemberService $memberService,
        protected MemberAccessTokenService $memberAccessTokenService,
        protected EmailGatewayService $emailGatewayService,
        protected EmailTextService $emailTextService,
        protected MemberFailCountService $memberFailCountService,
    ) {}

    /**
     * 驗證碼相關錯誤達上限：鎖定帳號（已鎖定就不重寫狀態紀錄）、作廢 token，回應通知前端登出。
     *
     * 作廢 token 失敗時帳號仍維持鎖定，只記 log 並告警（見 MemberAccessTokenService::revokeAfterLock()），
     * 因此不可在外層交易內呼叫。呼叫點有兩種：
     * - 訪客發送驗證碼（找回密碼時手機或信箱比對錯到上限）不用先登入就能打，跟登入密碼同樣的理由。
     * - 會員發送驗證碼（找回支付密碼時手機或信箱比對錯到上限）要先登入，呼叫端持有 token。
     *   仍用同一種做法：作廢失敗時若鎖定一起回滾，就變成沒鎖也沒作廢；維持鎖定的話，
     *   下一個請求就會被 AuthMember 依會員狀態擋下。支付密碼錯誤那條另外沿用 lockMemberAndRevoke()，
     *   是等保證會重試作廢的機制做好再一起改，不是因為回滾比較安全。
     *
     * @throws ApiException
     */
    protected function throwMemberLockedException(Member $member): never
    {
        $this->memberAccessTokenService->lockMember($member, MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT);
        $this->memberAccessTokenService->revokeAfterLock($member, MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT);

        throw $this->memberLockedException();
    }

    /**
     * 會員原本就已鎖定：只作廢 token，回應通知前端登出，不做鎖帳。
     *
     * 給「讀到的會員已經是鎖定」的呼叫端用。這類判斷讀的是從庫，客服剛解鎖、從庫還沒同步時會誤判為鎖定；
     * 這時若照 throwMemberLockedException() 鎖帳，會把剛解鎖的帳號重新鎖上。
     * 作廢 token 失敗只記 log 並告警，照常回應鎖帳，因此不可在外層交易內呼叫。
     *
     * @throws ApiException
     */
    protected function throwMemberAlreadyLockedException(Member $member): never
    {
        $this->memberAccessTokenService->revokeAfterLock($member, MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT);

        throw $this->memberLockedException();
    }

    /**
     * 帳號已鎖定的回應：通知前端登出
     */
    protected function memberLockedException(): ApiException
    {
        return new ApiException(
            __('error_message.90062'),
            HttpCode::HTTP_REDIRECT_LOGOUT->value,
        );
    }

    /**
     * 查詢手機號可綁定次數限制
     * 上限值讀 type=6「註冊/綁定限制」(phone)。
     * 未勾選(不限制)回 PHP_INT_MAX(呼叫端僅用於「==1 才回單一帳號」的選單判斷)。
     */
    protected function getMobileBindLimit(Agent $agent): int
    {
        $limit = $this->memberService->bindingLimitFor(
            $agent,
            MemberRegisterLimitFieldEnum::PHONE,
        );

        if ($limit === null) {
            return PHP_INT_MAX; // 不限制
        }

        if ($limit <= 0) {
            $limit = 1; // 預設值
        }

        return $limit;
    }

    /**
     * 發送驗證碼簡訊
     *
     * 發送失敗一律拋 {@see SmsGatewayException} 中斷（玩家端由 bootstrap render() 統一回 error_message.2003），
     * 告警與 log 交由框架 report 管線 → AlertGate 降噪，避免同一失敗原因洗版。
     *
     * @throws Exception
     * @throws SmsGatewayException
     */
    protected function sendValidateSms(Member $member, array $input, string $token, int $times): void
    {
        $smsGatewayGroupId = $member->agent->sms_gateway_group_id;

        // 挑選簡訊商
        $smsGatewayInfoData = $this->getSmsGatewayInfo(
            member: $member,
            mobile: $input['mobile'],
            times: $times,
            forcedGatewayInfoId: $input['sms_gateway_info_id'] ?? null,
            sendByWhatsApp: (bool) ($input['send_by_whatsapp'] ?? false),
        );

        if (!$smsGatewayInfoData) {
            throw new SmsGatewayException(
                message: '查無符合國際電話冠碼',
                memberId: $member->id,
                mobile: $input['mobile'],
                smsGatewayGroupId: $smsGatewayGroupId,
            );
        }

        // 取得簡訊模板
        $smsText = $this->getSmsText($smsGatewayInfoData->countryId, $input['type'], $smsGatewayGroupId);

        if (!$smsText) {
            throw new SmsGatewayException(
                message: '查無簡訊樣板',
                memberId: $member->id,
                mobile: $input['mobile'],
                smsGatewayGroupId: $smsGatewayGroupId,
            );
        }

        // 依據簡訊商規範格式化驗證碼
        $formattedToken = [REDACTED], $smsGatewayInfoData->smsGatewayInfo->info);

        // 替換簡訊模板中的認證碼
        // 部分簡訊商只接受驗證碼, 不接受簡訊模板
        $smsGatewayCode = $smsGatewayInfoData->smsGatewayInfo->smsGateway->code;
        if ($smsGatewayCode === SmsGatewayEnum::CODE_GROWIX->value
           || $smsGatewayCode === SmsGatewayEnum::CODE_GROWIX_V2->value
           || $smsGatewayCode === SmsGatewayEnum::CODE_GROWIX_V2_WA->value) {
            $smsText = $formattedToken;
        } else {
            $smsText = str_replace('{code}', $formattedToken, $smsText->text);
        }

        $this->sendSmsViaGateway($smsGatewayInfoData, $member, $input['mobile'], $smsText);
    }

    /**
     * 取得簡訊商資訊
     *
     * 篩選與挑選簡訊商的邏輯說明：
     * 1. 先依以下條件從資料庫撈出可用的簡訊商設定（`sms_gateway_infos`）：
     *    - sms_gateway_infos.status = 1（啟用中的簡訊商設定）
     *    - sms_gateway_infos.sms_gateway_group_id = 會員所屬代理的簡訊群組
     *    - 關聯的 sms_gateways.status = 1（啟用中的簡訊商），且 code 屬於 `$sendByWhatsApp` 指定的管道
     *      （WhatsApp 或一般簡訊，見 `SmsGatewayEnum::isWhatsAppChannel()`）
     *    - 關聯的 countries.status = 1，且傳入的手機號碼 `$mobile`
     *      需以該國家的 country_code 開頭（透過 `? like concat(countries.country_code, '%')` 比對）
     * 2. 依 `sms_gateway_infos.sort` 進行排序，取得候選清單 `$smsGatewayInfos`。
     * 3. 挑選具體要使用的簡訊商：
     *    - 若 `$times` 為 0 或未傳入，表示第一次發送，從清單中隨機選一個（`random()`），以分散單一供應商壓力。
     *    - 若 `$times` > 0，則以（$times - 1）對供應商數量取餘數，當作索引取得對應的簡訊商，達到輪詢(round-robin)效果。
     * 4. 取得該簡訊商綁定的第一個國家( `$smsGatewayInfo->countries->first()` )，
     *    回傳其 country_id 與 country_code，包裝成 `SmsGatewayInfoData` 給呼叫端使用。
     *
     * @param  int  $times  簡訊發送次數, 影響挑選簡訊商順位
     * @param  bool  $sendByWhatsApp  是否只挑選 WhatsApp 管道的簡訊商（@see SmsGatewayEnum::isWhatsAppChannel()）
     *
     * @see sendValidateSms
     */
    private function getSmsGatewayInfo(Member $member, string $mobile, int $times = 1, ?int $forcedGatewayInfoId = null, bool $sendByWhatsApp = false): ?SmsGatewayInfoData
    {
        // 強制指定簡訊商時，跳過自動挑選邏輯（含 WhatsApp 篩選）
        if ($forcedGatewayInfoId !== null) {
            $forcedGatewayInfo = SmsGatewayInfo::with(['smsGateway', 'countries'])->find($forcedGatewayInfoId);

            if (!$forcedGatewayInfo) {
                return null;
            }

            $country = $forcedGatewayInfo->countries->first();

            if (!$country) {
                return null;
            }

            return SmsGatewayInfoData::from([
                'smsGatewayInfo' => $forcedGatewayInfo,
                'countryId' => $country->id,
                'countryCode' => $country->country_code,
            ]);
        }

        $whatsAppCodes = array_values(array_map(
            fn (SmsGatewayEnum $code) => $code->value,
            array_filter(SmsGatewayEnum::cases(), fn (SmsGatewayEnum $code) => $code->isWhatsAppChannel() === $sendByWhatsApp),
        ));

        // todo 緩存
        $smsGatewayInfos = SmsGatewayInfo::with(['smsGateway', 'countries'])
            ->where('sms_gateway_infos.status', 1)
            ->where('sms_gateway_infos.sms_gateway_group_id', $member->agent->sms_gateway_group_id)
            ->whereHas('smsGateway', fn ($query) => $query->where('sms_gateways.status', 1)
                ->whereIn('sms_gateways.code', $whatsAppCodes))
            ->whereHas('countries', fn ($query) => $query->where('countries.status', 1)
                ->whereRaw('? like concat(countries.country_code, ?)', [$mobile, '%']))
            ->orderByDesc('sms_gateway_infos.sort')
            ->get();

        if ($smsGatewayInfos->isEmpty()) {
            return null;
        }

        // 依發送次數進行輪詢，避免長期固定打同一個供應商
        $index = ($times - 1) % $smsGatewayInfos->count();
        $smsGatewayInfo = $smsGatewayInfos->get($index);

        return SmsGatewayInfoData::from([
            'smsGatewayInfo' => $smsGatewayInfo,
            'countryId' => $smsGatewayInfo->countries->first()->id,
            'countryCode' => $smsGatewayInfo->countries->first()->country_code,
        ]);
    }

    /**
     * 取得簡訊內容模板
     *
     * @see sendValidateSms
     */
    private function getSmsText(int $countryId, int $type, int $smsGatewayGroupId): ?SmsText
    {
        return SmsText::where('sms_gateway_group_id', $smsGatewayGroupId)
            ->where('type_id', $type)
            ->where('country_id', $countryId)
            ->first();
    }

    /**
     * 修改簡訊內容
     *
     * 根據簡訊商的設定，對驗證碼進行格式化處理。
     * 例如將 123456 轉換為 1-2-3-4-5-6
     *
     * @return string 格式化後的驗證碼
     *
     * @see sendValidateSms
     */
    private function formatSmsContent(string $verificationCode, object $info): string
    {
        // $info = $smsGatewayInfo['info'];

        $insertChars = (string) ($info['insert_characters'] ?? '');

        if (Common::isNotEmptyString($insertChars)) {
            $charactersArray = str_split($verificationCode);
            foreach ($charactersArray as $index => &$character) {
                $character .= ($index !== array_key_last($charactersArray)) ? $insertChars : '';
            }
            $verificationCode = implode($charactersArray);
        }

        return $verificationCode;
    }

    /**
     * 發送簡訊通知
     *
     * 通知型簡訊：挑不到簡訊商與三方回傳失敗都只 report 不中斷；僅查無樣板維持拋出中斷（沿用既有行為）。
     *
     * @throws SmsGatewayException 查無簡訊樣板
     */
    protected function sendSms(Member $member, string $mobile, string $text = ''): void
    {
        if (!$text) {
            return;
        }

        // 挑選簡訊商
        $smsGatewayInfoData = $this->getSmsGatewayInfo($member, $mobile);

        if (!$smsGatewayInfoData) {
            // 通知型簡訊，挑不到簡訊商不中斷主流程，僅 report 進告警管線
            report(new SmsGatewayException(
                message: '查無符合國際電話冠碼',
                memberId: $member->id,
                mobile: $mobile,
                smsGatewayGroupId: $member->agent->sms_gateway_group_id,
            ));

            return;
        }

        // 使用資料驗證的內容樣板 type=1
        $smsText = $this->getSmsText($smsGatewayInfoData->countryId, 1, $member->agent->sms_gateway_group_id);

        if (!$smsText) {
            throw new SmsGatewayException(
                message: '查無簡訊樣板',
                memberId: $member->id,
                mobile: $mobile,
                smsGatewayGroupId: $member->agent->sms_gateway_group_id,
            );
        }

        $text = str_replace('{code}', $text, $smsText->text);

        try {
            $this->sendSmsViaGateway($smsGatewayInfoData, $member, $mobile, $text);
        } catch (SmsGatewayException $e) {
            // 通知型簡訊，發送失敗不中斷主流程；告警與 log 交由 report 管線處理
            report($e);
        }
    }

    /**
     * 發送簡訊
     *
     * @throws Exception
     * @throws SmsGatewayException 三方回傳失敗
     *
     * @see sendValidateSms
     */
    private function sendSmsViaGateway(SmsGatewayInfoData $smsGatewayInfoData, Member $member, string $mobile, string $smsText): void
    {
        $smsApiService = new SmsGatewayService;

        $result = $smsApiService->sendSms(
            smsGatewayInfoData: $smsGatewayInfoData,
            member: $member,
            mobile: $mobile,
            content: $smsText,
            type: 2, // 1:agent_id, 2:member_id
        );

        if ($result->status !== 1) {
            throw new SmsGatewayException(
                message: 'code:' . $smsGatewayInfoData->smsGatewayInfo->code . ' API 回傳失敗',
                memberId: $member->id,
                mobile: $mobile,
                smsGatewayGroupId: $member->agent->sms_gateway_group_id,
                gatewayInfoId: $smsGatewayInfoData->smsGatewayInfo->id,
                gatewayCode: $smsGatewayInfoData->smsGatewayInfo->code,
                response: $result->response,
            );
        }
    }

    /**
     * 發送驗證碼信（工單4286：主旨與內文改讀電子郵件設置組別的樣板）
     *
     * 語系取前台 DetectLanguage middleware 放進 Context 的 LANGUAGE_ID（訪客註冊階段也取得到）。
     * 取樣板順序：該語系 → 全語系 → 查無拋 {@see EmailTemplateNotFoundException} 中斷（比照簡訊查無樣板）。
     * 代理沒綁電子郵件設置組別時不查表、用內建預設文字，與改動前行為一致。
     *
     * @param  int  $type  驗證類型 ValidationCodeTypeEnum，由 EmailTextTypeEnum::fromValidationType() 對到樣板類型
     *
     * @throws EmailTemplateNotFoundException
     * @throws Throwable
     */
    protected function sendValidateMail(string $email, string $token, ?Member $member = null, int $type = ValidationCodeTypeEnum::BINDING_DATA->value): void
    {
        $agent = $member?->agent;
        $groupId = (int) ($agent?->email_gateway_group_id ?? 0);
        $languageId = (int) (Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value) ?? 1);
        $textType = EmailTextTypeEnum::fromValidationType($type);

        $template = $this->emailTextService->resolve($groupId, $textType, $languageId);

        if ($template === null) {
            // 必須就地 new：AlertGate 以建構位置 file:line 降噪
            throw new EmailTemplateNotFoundException(
                message: '查無郵件樣板',
                memberId: (int) ($member?->id ?? 0),
                email: $email,
                emailGatewayGroupId: $groupId,
                typeId: $textType->value,
                languageId: $languageId,
            );
        }

        $this->sendMail(
            $email,
            $this->emailTextService->render($template['content'], $token, $email),
            agent: $agent,
            member: $member,
            subject: $this->emailTextService->render($template['subject'], $token, $email),
        );
    }

    /**
     * 批量發送簡訊通知
     */
    protected function notifyMemberBySms(Member $member, array $mobiles, string $text): void
    {
        foreach ($mobiles as $mobile) {
            $this->sendSms($member, $mobile, $text);
        }
    }

    /**
     * 批量發送郵件通知
     *
     * @throws Throwable
     */
    protected function notifyMemberByEmail(array $emails, string $text, ?Member $member = null): void
    {
        foreach ($emails as $email) {
            $this->sendMail($email, $text, agent: $member?->agent, member: $member);
        }
    }

    /**
     * 發送郵件通知，$agent 決定用哪一組 SMTP
     *
     * @param  string|null  $subject  未帶時用預設主旨（通知型郵件沒有樣板類型，維持既有主旨）
     *
     * @throws Throwable
     */
    protected function sendMail(string $email, string $text = '', ?Agent $agent = null, ?Member $member = null, ?string $subject = null): bool
    {
        $this->emailGatewayService->send(
            agentGroupId: (int) ($agent?->email_gateway_group_id ?? 0),
            receiver: $email,
            subject: $subject ?? EmailTextService::DEFAULT_SUBJECT,
            content: $text,
            agentId: (int) ($agent?->id ?? 0),
            memberId: (int) ($member?->id ?? 0),
        );

        return true;
    }

    /**
     * 根據遊客驗證碼獲取會員
     *
     * @throws ApiException
     */
    protected function getMemberByGuestValidateToken(string $guestValidateToken): Member
    {
        $key = CacheKeys::guestValidateToken($guestValidateToken);
        $memberId = Cache::get($key);
        if (!$memberId) {
            throw new ApiException('guest validate token is invalid');
        }
        $member = Member::find($memberId);
        if (!$member) {
            throw new ApiException('guest validate token is invalid');
        }

        return $member;
    }
}
