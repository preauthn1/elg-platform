<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Member;
use App\Models\TiktokAccount;
use App\Models\TiktokLog;
use App\Services\ThirdParty\VideoTiktok;
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoTiktokService
{
    public const VIDEO_URLS = [
        'vmovie' => 'https://example.invalid/session/',
        'vnews' => 'https://example.invalid/session/',
        'dikdok' => 'https://example.invalid/session/',
    ];

    private TiktokAccount $account;

    public function __construct(
        protected VideoTiktok $videoTt
    ) {}

    /**
     * Get video session ID for a member
     */
    public function getVideoSessionId(Member $member): string
    {
        try {
            $account = $this->getAccount($member);
            $result = $this->videoTt->login($account->username, $account->password);

            if ($result['sessionID'] !== '') {
                $videoSessionId = $result['sessionID'];
                $clientID = $result['clientID'];
                $this->createLog($member, $videoSessionId, $clientID);

                return $videoSessionId;
            }

        } catch (Throwable $e) {
        }

        return '';
    }

    /**
     * Get video URL for a given video code and session ID
     */
    public function getVideoUrl(string $videoCode, string $sessionId): ?string
    {
        if (!$this->isValidVideoCode($videoCode)) {
            return null;
        }

        return $sessionId ? self::VIDEO_URLS[$videoCode] . $sessionId : null;
    }

    /**
     * Check if a video code is valid
     */
    public function isValidVideoCode(string $videoCode): bool
    {
        return isset(self::VIDEO_URLS[$videoCode]);
    }

    /**
     * Get TikTok account for member
     *
     * @throws ApiException
     */
    public function getAccount(Member $member): TiktokAccount
    {
        if (isset($this->account) && $this->account->member_id === $member->id) {
            return $this->account;
        }

        $account = TiktokAccount::where('member_id', $member->id)->first();

        if (!is_object($account)) {
            $account = TiktokAccount::where('member_id', 0)->first();
            if (is_object($account)) {
                $account->update(['member_id' => $member->id]);
            } else {
                throw new ApiException(__('error_message.9993'));
            }
        }

        $this->account = $account;

        return $this->account;
    }

    /**
     * Create login log
     */
    protected function createLog(Member $member, string $sessionID, string $clientID): void
    {
        TiktokLog::create([
            'member_id' => $member->id,
            'session_id' => $sessionID,
            'clientID' => $clientID,
            'admin' => 0,
        ]);
    }
}
