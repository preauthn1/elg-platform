<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Member;

use App\Http\Requests\AbstractRequest;
use App\Rules\Base64Image;
use App\Rules\NotAllowedNicknames;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends AbstractRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $member = Auth::user();

        return [
            'email' => [
                'nullable',
                'email',
                (is_object($member) ? Rule::unique('members', 'email')->ignore($member->id) : ''),
            ],
            'nickname' => [
                'nullable',
                'between:0,30',
                'alpha_dash:ascii',
                (is_object($member) ? new NotAllowedNicknames($member->agent) : ''),
                (is_object($member) ? Rule::unique('members', 'nickname')->ignore($member->id) : ''),
            ],
            /**
             * 用戶頭像 base64 或 URL 編碼
             */
            'image' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if (filter_var($value, FILTER_VALIDATE_URL)) {
                        return true;
                    }
                    (new Base64Image)->validate($attribute, $value, $fail);
                },
            ],
            'timezone_id' => 'nullable|digits_between:0,10',
            'country_id' => 'nullable|digits_between:0,10',
            'zip_code' => 'nullable|digits_between:0,10',
            'district' => 'nullable|between:0,30',
            'city' => 'nullable|between:0,30',
            'address' => 'nullable|between:0,100',
            'has_2fa' => 'nullable|boolean',
            'ip_change_mobile_verify' => 'nullable|boolean',
            'ip_change_email_verify' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('error_message.1088'),
            'nickname.unique' => __('error_message.1093', ['value' => $this->input('nickname')]),
        ];
    }
}
