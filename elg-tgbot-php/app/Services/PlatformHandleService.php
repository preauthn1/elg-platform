<?php

namespace App\Services;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\LanguageTypeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Http\Requests\PlatformBroadcastRequest;
use App\Models\ChatLanguageSetting;
use App\Singletons\TgBotApiCallService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class PlatformHandleService
{
    public function handleBroadcast(PlatformBroadcastRequest $request): array
    {
        $type = ChatMessagesTypeEnum::tryFrom($request->type) ?? ChatMessagesTypeEnum::TEXT;

        $langToMessageDTOs = $this->getLanguageAndMessageDTOMap($type, $request->contents);
        $defaultMessageDTO = $langToMessageDTOs[LanguageTypeEnum::EN_US->value] ?? null;

        // TODO: Verify bot code.

        $chatLangs = ChatLanguageSetting::where('bot_id', $request->bot_id)
            ->whereIn('chat_id', $request->chat_ids)
            ->get();

        $failedChatIds = [];
        $successChatIds = [];
        foreach ($request->chat_ids as $chatId) {
            $chatLang = $chatLangs->firstWhere('chat_id', $chatId);

            if (is_null($chatLang)) {
                $messageDTO = $defaultMessageDTO;
            } else {
                $lang = $chatLang->language ?? null;
                $messageDTO = $langToMessageDTOs[$lang] ?? $defaultMessageDTO;
            }
            // $request->button as [] or ['text' => 'text', 'url' => 'url']
            // 如果 $request->button 不是空array 的話，則會新增按鈕
            if (!empty($request->button)) {
                $buttonRows = new TgBotButtonRowBuilder;
                $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
                $buttonRows->addAppButton($request->button['text'], $request->button['url']);
                $buttonRows->setAttr('resize_keyboard', true);
                $buttonRows->setAttr('persistent', true);
                $messageDTO->buttonRows = $buttonRows->build();
            }

            // TODO: 未來要增加可以新增按鈕
            // add replybutton
            // "reply_markup": {
            //     "keyboard": [
            //     [{
            //         "text": "Open App"
            //     }]
            //     ],
            //     "resize_keyboard": true,
            //     "persistent": true
            // }
            // $buttonRows = new TgBotButtonRowBuilder();
            // $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
            // $buttonRows->addButton('Open App', '', 'https://example.invalid/login');
            // $buttonRows->setAttr('resize_keyboard', true);
            // $buttonRows->setAttr('persistent', true);
            // $messageDTO->buttonRows = $buttonRows->build();

            // TODO: 未來要增加可以新增按鈕
            // add replybutton
            // "reply_markup": {
            //     "keyboard": [
            //     [{
            //         "text": "Open App"
            //     }]
            //     ],
            //     "resize_keyboard": true,
            //     "persistent": true
            // }
            // $buttonRows = new TgBotButtonRowBuilder();
            // $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
            // $buttonRows->addButton('Open App', '', 'https://example.invalid/login');
            // $buttonRows->setAttr('resize_keyboard', true);
            // $buttonRows->setAttr('persistent', true);
            // $messageDTO->buttonRows = $buttonRows->build();

            try {
                $messageId = (new TgBotApiCallService($request->bot_id, $chatId))->send($messageDTO);
                $successChatIds[] = $chatId;

            } catch (ClientException $e) {
                $failedChatIds[] = [
                    'chat_id' => $chatId,
                    'message' => $e->getMessage(),
                ];

                Log::error('Platform controller error handler, ClientException', [
                    'bot_id' => $request->bot_id,
                    'chat_id' => $chatId,
                    'message' => $e->getMessage(),
                    'response' => $e->getResponse()->getBody()->getContents(),
                    'code' => $e->getCode(),
                ]);
            } catch (\Exception $e) {
                // ? need retry ?
                $failedChatIds[] = [
                    'chat_id' => $chatId,
                    'message' => $e->getMessage(),
                ];

                Log::error('Platform controller error handler', [
                    'bot_id' => $request->bot_id,
                    'chat_id' => $chatId,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        return [
            'success_chat_ids' => $successChatIds,
            'failed_chat_ids' => $failedChatIds,
        ];
    }

    private function getLanguageAndMessageDTOMap(ChatMessagesTypeEnum $type, array $contents): array
    {
        $ret = [];

        foreach ($contents ?? [] as $content) {
            $lang = $content['lang'];
            if (empty($content['content']) && empty($content['media_url'])) {
                throw new HttpResponseException(
                    response()->json(
                        data: ['error' => "One of text, media_url is required, lang: {$lang}"],
                        status: 400
                    )
                );
            }

            $tgMessageDTO = new TgBotApiMessageParameterDTO(
                type: $type,
                content: $content['content'] ?? '',
                mediaUrl: $content['media_url'] ?? ''
            );

            $ret[$lang] = $tgMessageDTO;
        }

        if (empty($ret)) {
            throw new HttpResponseException(
                response()->json(
                    data: ['error' => 'Message empty'],
                    status: 400
                )
            );
        }

        return $ret;
    }
}
