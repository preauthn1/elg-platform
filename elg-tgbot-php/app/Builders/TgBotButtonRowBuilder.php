<?php

namespace App\Builders;

use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;

class TgBotButtonRowBuilder
{
    private array $replyMarkup = [];

    private array $buttonRow = [];

    private int $rowCounter = 0;

    private TgBotButtonRowTypeEnum $type = TgBotButtonRowTypeEnum::INLINE_KEYBOARD;

    public function addAppButton(string $text, string $url): void
    {
        $buttonData = [
            'text' => $text,
            'web_app' => ['url' => $url],
        ];

        $this->addButtonByArray($buttonData);
    }

    public function addAppButtonByActionEnum(TgBotCallbackActionEnum $action, string $url): void
    {
        $buttonData = [
            'text' => __('tgbot.buttons.' . $action->value),
            'web_app' => ['url' => $url],
        ];

        $this->addButtonByArray($buttonData);
    }

    public function addButtonByActionEnum(TgBotCallbackActionEnum $action, string $url = ''): void
    {
        $callbackData = $action->value;
        $buttonContent = __("tgbot.buttons.{$callbackData}");

        $this->addButton($buttonContent, $callbackData, $url);
    }

    public function addButtonByArray(array $buttonData): void
    {
        $this->buttonRow[$this->rowCounter][] = $buttonData;
    }

    public function addButton(string $text, string $callbackData = '', string $url = ''): void
    {
        if (empty($callbackData) && empty($url)) {
            throw new \Exception('Both callbackData and url are empty');
        }

        $newButton = [
            'text' => $text,
            'callback_data' => $callbackData,
        ];
        if (!empty($url)) {
            $newButton['url'] = $url;
        }

        $this->buttonRow[$this->rowCounter][] = $newButton;

    }

    public function setType(TgBotButtonRowTypeEnum $type)
    {
        $this->type = $type;
    }

    public function setAttr(string $fieldName, mixed $value = true): void
    {
        $this->replyMarkup[$fieldName] = $value;
    }

    public function build(): array
    {
        $this->replyMarkup[$this->type->value] = $this->buttonRow;

        return $this->replyMarkup;
    }

    public function nextRow(): void
    {
        if (empty($this->buttonRow[$this->rowCounter])) {
            return;
        }

        $this->rowCounter++;
    }
}
