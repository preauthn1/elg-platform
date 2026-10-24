<?php

namespace App\Http\Requests;

use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\LanguageTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlatformBroadcastRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sec_key' => 'required|string',

            'bot_id' => 'required|string',
            'bot_code' => 'required|string',

            'chat_ids' => 'required|array',
            'chat_ids.*' => 'string',

            'type' => [
                'required',
                'string',
                Rule::enum(ChatMessagesTypeEnum::class),
            ],

            'contents' => 'required|array',
            'contents.*.content' => [
                'string',
                Rule::requiredIf(fn () => $this->input('type') === ChatMessagesTypeEnum::TEXT->value),
            ],
            'contents.*.media_url' => [
                'string',
                Rule::requiredIf(fn () => $this->input('type') !== ChatMessagesTypeEnum::TEXT->value),
            ],
            'contents.*.lang' => [
                'string',
                Rule::enum(LanguageTypeEnum::class),
            ],
        ];
    }

    public function messages()
    {
        return [
            'contents.*.content.required_if' => 'Required if type is text',
            'contents.*.media_url.required_if' => 'Required if type is not text',
        ];
    }
}
