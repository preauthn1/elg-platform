<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\CacheKeys\CacheKeys;
use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\Agent\TwoFactorResultCodeEnum;
use App\Enums\General\CacheTtl;
use App\Exceptions\Agent\TwoFactorResultException;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentAuthentication;
use App\Models\AgentPermissionGroup;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * 代理 Google 2FA：取 QRCode／驗證碼／本人開啟／關閉／重置他人（target/tickets#4518 自 API_Agent Google2FAController 搬入）。
 *
 * 規則與 API_Agent 同一套（舊後台過渡期仍打 API_Agent，兩邊必須一致）：
 * - QRCode 與金鑰只給本人、且只在尚未開啟時給（#4503）；已開啟要換裝置請上層重置。
 * - 關閉／重置一律驗「操作者本人」當下的驗證碼；操作他人時操作者本人必須已開啟 2FA，對象不能套用 admin 角色（#4422 定義）。
 * - 開啟、關閉、重置各寫一筆代理操作紀錄；完成後登出被變更帳號的所有登入（#4518，含操作者目前這一個）。
 *
 * 與 API_Agent 共用兩把 Redis key（同一台 redis、同一個 db）：
 * - 金鑰暫存 CacheKeys::legacyAgentAuthGoogle()：兩邊都讀寫，同一個人在新舊後台看到同一組 QRCode。
 * - token 快取 CacheKeys::apiAgentAccessToken()：API_Agent Authenticate middleware 的 5 分鐘快取，作廢 token 時一併刪（見 AgentAccessToken:[REDACTED]
 *
 * 業務失敗拋 TwoFactorResultException（HTTP 200＋API_Agent 相容碼，前端彈窗就地顯示）；權限／查無帳號拋 ApiException。
 */
class AgentTwoFactorService
{
    /** agent_authentications.type：Google 2FA */
    private const int AUTH_TYPE_GOOGLE = 1;

    /** QRCode 畫布邊長；產出後把固定寬高換成 100%，讓前端自適應 */
    private const int QR_SIZE = 400;

    /**
     * 與 API_Agent 共用的金鑰暫存要走的 Redis 連線：config/database.php 的 json（SERIALIZER_JSON，同 db 0）。
     * 預設連線是 SERIALIZER_PHP，存出去的值 API_Agent 解不出來（它用 predis 直接 json_decode 原始值）。
     */
    private const string LEGACY_REDIS_CONNECTION = 'json';

    public function __construct(
        private readonly AgentPermissionScopeService $scope,
        private readonly AgentOperateLogService $operateLog,
    ) {}

    /**
     * 本人的 QRCode 與金鑰。已開啟 2FA 回兩個空字串；尚未開啟時沒有金鑰就產生一組保存。
     *
     * @return array{image: string, secret: [REDACTED]
     */
    public function image(Agent $self): array
    {
        if ((int) $self->has_2fa === 1) {
            return ['image' => '', 'secret' => ''];
        }

        // 與 API_Agent 共用這把 key，必須走 json 連線（SERIALIZER_JSON）：預設連線會先 PHP 序列化再存，
        // API_Agent（predis、json_decode 原始值）讀出來會是 null，舊後台的 QRCode 就空白；
        // json 連線寫陣列＝存純 JSON，讀 API_Agent 寫的純 JSON 也會自動解成陣列。
        $key = CacheKeys::legacyAgentAuthGoogle($self->id);
        $cached = Redis::connection(self::LEGACY_REDIS_CONNECTION)->get($key);
        if (is_array($cached) && isset($cached['image'], $cached['secret'])) {
            return ['image' => (string) $cached['image'], 'secret' => (string) $cached['secret']];
        }

        $auth = AgentAuthentication::query()
            ->where('agent_id', $self->id)
            ->where('status', 1)
            ->first();

        if ($auth !== null && (string) $auth->secret !== '') {
            $data = ['image' => (string) $auth->image, 'secret' => (string) $auth->secret];
        } else {
            $data = $this->issueSecret($self);
        }

        Redis::connection(self::LEGACY_REDIS_CONNECTION)->setex($key, CacheTtl::ONE_HOUR->value, $data);

        return $data;
    }

    /**
     * 為代理產生一組新的金鑰與 QRCode，存成啟用中（status=1）。
     *
     * @return array{image: string, secret: [REDACTED]
     */
    private function issueSecret(Agent $agent): array
    {
        $google2fa = new Google2FA;
        $secret = [REDACTED];
        $qrUrl = $google2fa->getQRCodeUrl(
            (string) ($agent->organization?->name ?? ''),
            $agent->username,
            $secret,
        );
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE), new SvgImageBackEnd));
        // 只改 <svg> 外框讓圖隨容器縮放；背景 <rect> 帶同一串寬高，一併取代會失去高度變透明，深色主題下黑碼疊黑底掃不出來
        $image = str_replace(
            sprintf('version="1.1" width="%d" height="%d"', self::QR_SIZE, self::QR_SIZE),
            'version="1.1" width="100%"',
            $writer->writeString($qrUrl),
        );

        AgentAuthentication::query()->create([
            'agent_id' => $agent->id,
            'type' => self::AUTH_TYPE_GOOGLE,
            'secret' => $secret,
            'image' => $image,
            'status' => 1,
            'admin' => 0,
        ]);

        return ['image' => $image, 'secret' => $secret];
    }

    /**
     * 驗證某代理的驗證碼：只能驗自己或自己範疇內的代理（#4503，避免拿來試他線代理的驗證碼）。
     */
    public function auth(Agent $operator, int $agentId, string $otp): void
    {
        if ($agentId !== (int) $operator->id) {
            $this->assertInScope($operator, $agentId);
        }
        if (!$this->verifyOtp($agentId, $otp)) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::OTP_INVALID);
        }
    }

    /**
     * 本人開啟（#4521）：驗證碼對得上才開；成功後登出自己所有登入（#4518）。
     */
    public function enable(Agent $self, string $otp): void
    {
        DB::transaction(function () use ($self, $otp): void {
            $agent = Agent::query()->whereKey($self->id)->lockForUpdate()->first();
            if ($agent === null) {
                throw new ApiException(__('error_message.1005'));
            }
            if ((int) $agent->has_2fa === 1) {
                throw new TwoFactorResultException(TwoFactorResultCodeEnum::ALREADY_ENABLED);
            }
            if (!$this->verifyOtp((int) $agent->id, $otp)) {
                throw new TwoFactorResultException(TwoFactorResultCodeEnum::OTP_INVALID);
            }

            $agent->update(['has_2fa' => 1]);
            $this->writeLog((int) $self->id, (int) $agent->id, AgentOperateTypeEnum::ENABLE_AGENT_2FA);
        });

        AgentAccessToken:[REDACTED] $self->id);
        // 金鑰暫存只給設置畫面用，開啟後不再需要；交易提交後才刪，刪早了同時有人查 image 會把舊值寫回
        $this->forgetLegacySecretCache((int) $self->id);
    }

    /**
     * 關閉：agent_id 不帶＝關閉自己；關閉別人要在範疇內、操作者已開啟 2FA、對象未套 admin 角色。
     * 把 has_2fa 改 0，同時作廢舊金鑰、發新金鑰並清暫存（#4541），重新開啟時是新的 QRCode。成功後登出對象所有登入（#4518）。
     *
     * @return bool 是否關閉的是自己（前端據此跳「設定已更新，請重新登入」）
     */
    public function disable(Agent $operator, ?int $agentId, string $otp): bool
    {
        $targetId = $agentId ?: (int) $operator->id;
        $isSelf = $targetId === (int) $operator->id;

        if (!$isSelf) {
            $this->assertInScope($operator, $targetId);
        }
        $target = $this->findTarget($targetId);
        if ((int) $target->has_2fa !== 1) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::TARGET_NOT_ENABLED);
        }
        if (!$isSelf) {
            $this->assertCanManageOthers($operator, $target);
        }
        if (!$this->verifyOtp((int) $operator->id, $otp)) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::OTP_INVALID);
        }

        DB::transaction(function () use ($operator, $target, $targetId): void {
            Agent::query()->whereKey($targetId)->update(['has_2fa' => 0]);
            // target/tickets#4541：關閉即作廢舊金鑰並發一組新的；之後「設置2FA」看到的是新 QRCode，
            // 綁定前反覆開啟都是同一組（image() 沿用 status=1 這筆）
            AgentAuthentication::query()
                ->where('agent_id', $targetId)
                ->where('status', 1)
                ->update(['status' => 0]);
            $this->issueSecret($target);
            $this->writeLog((int) $operator->id, $targetId, AgentOperateTypeEnum::DISABLE_AGENT_2FA);
        });

        AgentAccessToken:[REDACTED];
        // 暫存的是舊金鑰，交易提交後才刪（理由同 enable）
        $this->forgetLegacySecretCache($targetId);

        return $isSelf;
    }

    /**
     * 重置他人（#4503）：關閉對象的 2FA 並作廢舊金鑰，對象下次開啟會拿到新金鑰。
     * 不能重置自己；權限碼（agent_reset_2fa／cj7_user_list_reset_2fa）由路由的 CheckAgentPermission 把關。
     * 成功後登出對象所有登入（#4518）。
     */
    public function reset(Agent $operator, int $agentId, string $otp): void
    {
        if ($agentId === (int) $operator->id) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::CANNOT_RESET_SELF);
        }
        $this->assertInScope($operator, $agentId);
        $target = $this->findTarget($agentId);
        if ((int) $target->has_2fa !== 1) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::TARGET_NOT_ENABLED);
        }
        $this->assertCanManageOthers($operator, $target);
        if (!$this->verifyOtp((int) $operator->id, $otp)) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::OTP_INVALID);
        }

        DB::transaction(function () use ($operator, $agentId): void {
            Agent::query()->whereKey($agentId)->update(['has_2fa' => 0]);
            AgentAuthentication::query()
                ->where('agent_id', $agentId)
                ->where('status', 1)
                ->update(['status' => 0]);
            $this->writeLog((int) $operator->id, $agentId, AgentOperateTypeEnum::RESET_AGENT_2FA);
        });

        AgentAccessToken:[REDACTED];
        // 暫存的是舊金鑰，交易提交後才刪（理由同 enable）
        $this->forgetLegacySecretCache($agentId);
    }

    /**
     * 刪新舊後台共用的金鑰暫存。必須排在 expireAll 之後：redis 異常時記 log、不中斷，
     * 不能讓它擋掉踢人或把已提交的變更回成錯誤（同 MemberAccessTokenService::forgetTokenCache）。
     */
    private function forgetLegacySecretCache(int $agentId): void
    {
        try {
            Redis::connection(self::LEGACY_REDIS_CONNECTION)->del(CacheKeys::legacyAgentAuthGoogle($agentId));
        } catch (Throwable $e) {
            Log::error('AgentTwoFactor legacy secret cache forget failed after commit', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** 以該代理啟用中的金鑰驗 6 位數驗證碼；沒有金鑰或金鑰格式壞掉一律當錯誤 */
    public function verifyOtp(int $agentId, string $otp): bool
    {
        $auth = AgentAuthentication::query()
            ->where('agent_id', $agentId)
            ->where('status', 1)
            ->first();
        if ($auth === null || (string) $auth->secret === '') {
            return false;
        }

        try {
            return (bool) (new Google2FA)->verifyKey((string) $auth->secret, $otp);
        } catch (Throwable) {
            return false;
        }
    }

    private function findTarget(int $agentId): Agent
    {
        $target = Agent::query()->find($agentId);
        if ($target === null) {
            throw new ApiException(__('error_message.1005'));
        }

        return $target;
    }

    private function assertInScope(Agent $operator, int $agentId): void
    {
        if (!$this->scope->isInScope($this->scope->hostId($operator), $agentId)) {
            throw new ApiException(__('error_message.9003'));
        }
    }

    /**
     * 操作他人 2FA（關閉、重置）的共同條件：操作者本人已開啟 2FA（要驗他的驗證碼），對象不能套用 admin 角色。
     */
    private function assertCanManageOthers(Agent $operator, Agent $target): void
    {
        if ((int) $operator->has_2fa !== 1) {
            throw new TwoFactorResultException(TwoFactorResultCodeEnum::OPERATOR_NOT_ENABLED);
        }
        if (!empty($target->agent_permission_group_id)) {
            $ownerAgentId = AgentPermissionGroup::query()->whereKey($target->agent_permission_group_id)->value('owner_agent_id');
            if (AgentPermissionGroup::isAdminOwner($ownerAgentId === null ? null : (int) $ownerAgentId)) {
                throw new TwoFactorResultException(TwoFactorResultCodeEnum::TARGET_ADMIN_ROLE);
            }
        }
    }

    private function writeLog(int $operatorId, int $targetId, AgentOperateTypeEnum $type): void
    {
        $this->operateLog->log(
            agentId: $operatorId,
            type: $type,
            debugInfo: new PlainDebugInfo(''),
            ip: Common::getAgentClientIp(),
            referenceId: (string) $targetId,
            referenceType: AgentOperateReferenceTypeEnum::AGENT,
        );
    }
}
