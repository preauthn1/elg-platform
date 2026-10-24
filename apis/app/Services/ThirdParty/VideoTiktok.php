<?php

declare(strict_types=1);

namespace App\Services\ThirdParty;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoTiktok
{
    /**
     * Login to third party service
     */
    public function login(string $username, string $password): array
    {
        $url = config('third-party.video_tiktok.api_url') . 'auth/login';
        $data = [
            'username' => $username,
            'password' => sha1($password),
        ];
        $body = base64_encode(json_encode($data));

        try {
            $result = Http::logAction('VideoTiktok_Login')
                ->timeout(1)
                ->post($url, ['data' => $body])
                ->json();
        } catch (Throwable $e) {
            Log::error('VideoTiktok::login', [
                'username' => $username,
                'password' => $password,
                'error' => $e->getMessage(),
            ]);

            // ignore
            return [
                'sessionID' => '',
                'clientID' => '',
            ];
        }

        if (($result['status'] ?? 0) == 1) {
            return [
                'sessionID' => $result['sessionID'],
                'clientID' => $result['clientID'],
            ];
        }

        return [
            'sessionID' => '',
            'clientID' => '',
        ];
    }
}
