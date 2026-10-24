<?php

namespace App\DTOs;

use Illuminate\Http\Request;

class TgWebhookDataDTO
{
    /**
     * 對應 TgBotCallbackActionEnum
     */
    public readonly string $callbackAction;

    /**
     * 解析 callback_query 的 data 字段
     */
    public readonly array $callbackActionExtraDatas;

    /**
     * 資料表 brands 的 bot_id
     */
    public readonly string $botId;

    /**
     * Telegram chat ID
     */
    public readonly string $chatId;

    /**
     * Telegram message ID
     */
    public readonly string $messageId;

    /**
     * message 或 callback_query 的 message
     */
    public readonly array $message;

    /**
     * Username of the sender (if available)
     */
    public readonly string $username;

    /**
     * Raw webhook JSON payload
     */
    public readonly string $rawJson;

    public function __construct(
        string $botId,
        string $chatId,
        string $messageId,
        array $message = [],
        string $username = '',
        string $rawJson = '',
        string $callbackAction = '',
        array $callbackActionExtraDatas = [],
    ) {
        $this->botId = $botId;
        $this->chatId = $chatId;
        $this->messageId = $messageId;
        $this->message = $message;
        $this->username = $username;
        $this->rawJson = $rawJson;
        $this->callbackAction = $callbackAction;
        $this->callbackActionExtraDatas = $callbackActionExtraDatas;
    }

    public static function fromRequest(string $botId, Request $request): ?self
    {
        $callbackAction = '';
        $callbackActionExtraDatas = [];
        if (!empty($request->input('callback_query'))) {
            $message = $request->input('callback_query')['message'];
            $callbackAction = $request->input('callback_query')['data'];
            $parsedActionArray = self::parseCallbackAction($callbackAction);
            $callbackAction = $parsedActionArray['action'];
            $callbackActionExtraDatas = $parsedActionArray['extraDatas'];
        } else {
            $message = $request->input('message');
        }

        if (empty($message)) {
            return null;
        }

        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $firstName = $message['chat']['first_name'] ?? '';
        $lastName = $message['chat']['last_name'] ?? '';
        $username = $message['chat']['username'] ?? '';
        if (!empty($username)) {
            $username = $username;
        }if (!empty($firstName) && !empty($lastName)) {
            $username = $firstName . ' ' . $lastName;
        } elseif (!empty($firstName)) {
            $username = $firstName;
        } elseif (!empty($lastName)) {
            $username = $lastName;
        }
        $rawJson = $request->getContent();

        return new self(
            botId: $botId,
            chatId: $chatId,
            messageId: $messageId,
            message: $message,
            username: $username,
            rawJson: $rawJson,
            callbackAction: $callbackAction,
            callbackActionExtraDatas: $callbackActionExtraDatas,
        );
    }

    /**
     * 解析 callback_query 的 data 字段
     *
     * 格式1：action_name
     * 格式2：action=action_name&param1=value1&param2=value2
     */
    private static function parseCallbackAction(string $callbackAction = ''): array
    {
        $return = [
            'action' => '',
            'extraDatas' => [],
        ];

        if (empty($callbackAction)) {
            return $return;
        }

        if (!str_contains($callbackAction, '&')) {
            $return['action'] = $callbackAction;

            return $return;
        }

        $queryString = [];
        parse_str($callbackAction, $queryString);
        $action = $queryString['action'];
        unset($queryString['action']);
        $return['action'] = $action;
        $return['extraDatas'] = $queryString;

        return $return;
    }
}
