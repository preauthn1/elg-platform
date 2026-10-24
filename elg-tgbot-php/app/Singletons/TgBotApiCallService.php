<?php

namespace App\Singletons;

use App\DTOs\TgBotApiMessageParameterDTO;
use App\DTOs\TgWebhookDataDTO;
use App\Enums\ChatMessages\ChatMessagesSenderEnum;
use App\Enums\ChatMessages\ChatMessagesStatusEnum;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\LogTypeEnum;
use App\Helpers\BotHelper;
use App\Models\ChatMessage;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class TgBotApiCallService
{
    private string|float $startTime;

    private string $baseUrl;

    private Client $client;

    private array $logPostData = [];

    private readonly array $useMultipart_FormData;

    public function __construct(
        private string $botId,
        private string $chatId,
        private ?int $processingChatMessageId = null
    ) {
        $this->client = app(Client::class);
        $this->baseUrl = BotHelper::getTgApiBotUrl($this->botId);

        $this->useMultipart_FormData = [
            'sendPhoto',
            'sendAnimation',
            'sendAudio',
            'sendVoice',
            'sendVideo',
        ];
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    public function sendTextOnly(string $content): int
    {
        $dto = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: $content
        );

        return $this->send($dto);
    }

    public function send(TgBotApiMessageParameterDTO $dto): int
    {
        $this->startTime = microtime(true);

        $response = null;
        switch ($dto->type) {
            case ChatMessagesTypeEnum::TEXT:
                $response = $this->sendMessage($dto);
                break;
            case ChatMessagesTypeEnum::IMAGE:
                $response = $this->sendPhoto($dto);
                break;
            case ChatMessagesTypeEnum::ANIMATION:
                $response = $this->sendAnimation($dto);
                break;
            case ChatMessagesTypeEnum::AUDIO:
                $response = $this->sendAudio($dto);
                break;
            case ChatMessagesTypeEnum::VOICE:
                $response = $this->sendVoice($dto);
                break;
        }

        $messageId = $this->writeLogAndStoreMessageToDB($response);

        return $messageId;
    }

    public function editMessage(TgBotApiMessageParameterDTO $dto): int
    {
        $this->startTime = microtime(true);
        $messageId = app(TgWebhookDataDTO::class)->messageId;
        $url = $this->baseUrl . '/editMessageText';

        $postData = [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $dto->content,
        ];
        if (!empty($dto->buttonRows)) {
            $postData['reply_markup'] = $dto->buttonRows;
        }

        $this->logPostData = [
            'type' => ChatMessagesTypeEnum::REQUEST_EDIT->value,
            'method' => 'editMessage',
            'data' => $postData,
        ];

        $response = $this->client->post($url, [
            'json' => $postData,
        ]);

        $messageId = $this->writeLogAndStoreMessageToDB($response);

        return $messageId;
    }

    public function deleteMessage(string $messageId): int
    {
        $this->startTime = microtime(true);
        $url = $this->baseUrl . '/deleteMessage';

        $postData = [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
        ];

        $this->logPostData = [
            'type' => ChatMessagesTypeEnum::REQUEST_DELETE->value,
            'method' => 'deleteMessage',
            'data' => $postData,
        ];

        $response = $this->client->post($url, [
            'json' => $postData,
        ]);

        $messageId = $this->writeLogAndStoreMessageToDB($response);

        return $messageId;
    }

    private function sendMessage(TgBotApiMessageParameterDTO $dto): ResponseInterface
    {
        $url = $this->baseUrl . '/sendMessage';

        $postData = [
            'chat_id' => $this->chatId,
            'text' => $dto->content,
        ];
        if (!empty($dto->buttonRows)) {
            $postData['reply_markup'] = $dto->buttonRows;
        }

        $this->logPostData = [
            'url' => $url,
            'type' => ChatMessagesTypeEnum::TEXT->value,
            'method' => 'sendMessage',
            'data' => $postData,
        ];

        return $this->callApiAndRetryWhenFailed($url, $postData);
    }

    private function sendPhoto(TgBotApiMessageParameterDTO $dto): ResponseInterface
    {
        $url = $this->baseUrl . '/sendPhoto';

        $postData = [
            'chat_id' => $this->chatId,
            'photo' => $dto->mediaUrl,
            'caption' => $dto->content,
        ];
        if (!empty($dto->buttonRows)) {
            $postData['reply_markup'] = $dto->buttonRows;
        }

        $this->logPostData = [
            'url' => $url,
            'type' => ChatMessagesTypeEnum::IMAGE->value,
            'method' => 'sendPhoto',
            'data' => $postData,
        ];

        return $this->callApiAndRetryWhenFailed($url, $postData);
    }

    private function sendAnimation(TgBotApiMessageParameterDTO $dto): ResponseInterface
    {
        $url = $this->baseUrl . '/sendAnimation';

        $postData = [
            'chat_id' => $this->chatId,
            'animation' => $dto->mediaUrl,
            'caption' => $dto->content,
        ];
        if (!empty($dto->buttonRows)) {
            $postData['reply_markup'] = $dto->buttonRows;
        }

        $this->logPostData = [
            'url' => $url,
            'type' => ChatMessagesTypeEnum::ANIMATION->value,
            'method' => 'sendAnimation',
            'data' => $postData,
        ];

        return $this->callApiAndRetryWhenFailed($url, $postData);
    }

    private function sendAudio(TgBotApiMessageParameterDTO $dto): ResponseInterface
    {
        $url = $this->baseUrl . '/sendAudio';

        $postData = [
            'chat_id' => $this->chatId,
            'audio' => $dto->mediaUrl,
            'caption' => $dto->content,
        ];
        if (!empty($dto->buttonRows)) {
            $postData['reply_markup'] = $dto->buttonRows;
        }

        $this->logPostData = [
            'url' => $url,
            'type' => ChatMessagesTypeEnum::AUDIO->value,
            'method' => 'sendAudio',
            'data' => $postData,
        ];

        return $this->callApiAndRetryWhenFailed($url, $postData);
    }

    private function sendVoice(TgBotApiMessageParameterDTO $dto): ResponseInterface
    {
        $url = $this->baseUrl . '/sendVoice';

        // multipart/form-data
        $postData = [
            [
                'name' => 'chat_id',
                'contents' => $this->chatId,
            ],
            [
                'name' => 'voice',
                'contents' => fopen($dto->mediaUrl, 'r'),
                'filename' => 'voice.ogg',
                'headers' => [
                    'Content-Type' => 'audio/ogg',
                ],
            ],
            [
                'name' => 'caption',
                'contents' => $dto->content,
            ],
        ];

        $this->logPostData = [
            'url' => $url,
            'type' => ChatMessagesTypeEnum::VOICE->value,
            'method' => 'sendVoice',
            'data' => [
                'chat_id' => $this->chatId,
                'voice' => $dto->mediaUrl,
                'caption' => $dto->content,
            ],
        ];

        return $this->callApiAndRetryWhenFailed($url, $postData, true);
    }

    private function callApiAndRetryWhenFailed(
        string $url,
        array $postData,
        bool $useMultipart = false
    ): ResponseInterface {
        $options = $useMultipart ? ['multipart' => $postData] : ['json' => $postData];
        $options['exceptions'] = false;
        $options['http_errors'] = false;

        $wait = 0;
        for ($i = 0; $i < 5; $i++) {
            sleep($wait);

            $response = $this->client->post($url, $options);
            $code = $response->getStatusCode();

            if ($code === 429) { // too many requests
                $wait = (int) ($wait === 0 ? 1 : $wait * 2);

                continue;
            } elseif ($code === 200) {
                return $response;
            } else {
                return $response; // We don't care about other status code, just log it and finish.
            }
        }

        return $response;
    }

    private function writeLogAndStoreMessageToDB(ResponseInterface $response): int
    {
        $responseBody = $response->getBody();
        $responseContent = $responseBody->getContents();

        $responseData = json_decode($responseBody, true);

        $result = $responseData['result'] ?? [];
        $messageId = (is_array($result) && !empty($result)) ? (int) ($result['message_id'] ?? 0) : 0;

        $this->logTgApiCall($responseContent, microtime(true) - $this->startTime);
        $this->storeMessageToDB($messageId, $responseContent);

        $this->startTime = '';

        return $messageId;
    }

    private function logTgApiCall(string $responseContent, float $spendMicroTime)
    {
        $spendTimeMS = 1000 * $spendMicroTime;
        Log::info(
            "Telegram api call log, spend time: {$spendTimeMS}",
            [
                'url' => $this->logPostData['url'] ?? '',
                'request' => $this->logPostData,
                'response' => json_decode($responseContent),
                'extra' => [
                    'type' => LogTypeEnum::TG_API_CALL->value,
                    'method' => 'response',
                ],
            ]
        );
    }

    private function storeMessageToDB(int $messageId, string $responseContent)
    {
        $content = $this->logPostData['data']['caption'] ?? ($this->logPostData['data']['text'] ?? '');

        ChatMessage::create([
            'ref_id' => $this->processingChatMessageId,
            'bot_id' => $this->botId,
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'sender' => ChatMessagesSenderEnum::SERVER->value,
            'type' => $this->logPostData['type'] ?? '',
            'action' => $this->logPostData['method'] ?? '',
            'status' => ChatMessagesStatusEnum::DONE->value,
            'content' => $content,
            'info' => $responseContent,
            'extra_info' => json_encode($this->logPostData),
        ]);
    }
}
