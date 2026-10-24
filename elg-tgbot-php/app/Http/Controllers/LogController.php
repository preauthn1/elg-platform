<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;

class LogController extends Controller
{
    public function cacheNotFound(string $botId, string $chatId)
    {
        $logPath = LogHelper::cacheChannel($botId, $chatId, onlyPath: true);

        if (!file_exists($logPath)) {
            return response()->json([
                'botId' => $botId,
                'chatId' => $chatId,
                'message' => 'Log file not found.',
            ]);
        }

        if (file_exists($logPath)) {
            $log = file_get_contents($logPath);

            return response()->json($log);
        }
    }

    public function platformApi(string $botId, string $chatId)
    {
        $logPath = LogHelper::platformApiChannel($botId, $chatId, onlyPath: true);
        if (!file_exists($logPath)) {
            return response()->json([
                'botId' => $botId,
                'chatId' => $chatId,
                'message' => 'Log file not found.',
            ]);
        }

        $log = file_get_contents($logPath);
        $log = str_replace("\n", '<br>', $log);

        return response($log);
    }

    public function tgbotApi(string $botId, string $chatId)
    {
        $logPath = LogHelper::tgApiCallChannel($botId, $chatId, onlyPath: true);
        if (!file_exists($logPath)) {
            return response()->json([
                'botId' => $botId,
                'chatId' => $chatId,
                'message' => 'Log file not found.',
            ]);
        }

        $log = file_get_contents($logPath);
        $log = str_replace("\n", '<br>', $log);

        return response($log);
    }

    public function tgWebhookReq(string $botId, string $chatId)
    {
        $logPath = LogHelper::tgWebhookReqChannel($botId, $chatId, onlyPath: true);
        if (!file_exists($logPath)) {
            return response()->json([
                'botId' => $botId,
                'chatId' => $chatId,
                'message' => 'Log file not found.',
            ]);
        }

        $log = file_get_contents($logPath);
        $log = str_replace("\n", '<br>', $log);

        return response($log);
    }
}
