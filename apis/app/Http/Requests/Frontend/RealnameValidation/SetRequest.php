<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\RealnameValidation;

use App\Enums\MemberFieldKeyEnum;
use App\Helpers\Traits\GetContextTrait;
use App\Rules\Base64Image;
use App\Services\MemberFieldSettingService;
use Illuminate\Foundation\Http\FormRequest;

class SetRequest extends FormRequest
{
    use GetContextTrait;

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
     * 沒有實名驗證欄位設定時，用預設規則：真實姓名與銀行卡皆必填。
     * 有設定時，銀行卡與證件欄位依設定決定是否必填；未啟用的欄位仍收下不擋，
     * 避免尚未更新的前台送出既有欄位就被擋。
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'realname' => 'required|string|max:100',
            'bank_id' => 'required|integer',
            'bank_name' => 'required|string|max:100',
            'bank_account' => 'required|string|max:100',
        ];

        $config = $this->verificationConfig();

        if ($config === []) {
            // 沒有欄位設定時證件照非必填，但有送就一定要是合法圖片，不能原樣寫進資料庫
            $rules['id_front_image'] = ['nullable', new Base64Image];
            $rules['id_back_image'] = ['nullable', new Base64Image];

            return $rules;
        }

        $bank = $this->fieldRulePrefix($config, MemberFieldKeyEnum::BANK->value);
        $rules['bank_id'] = $bank . 'integer';
        $rules['bank_name'] = $bank . 'string|max:100';
        $rules['bank_account'] = $bank . 'string|max:100';

        $rules['birthday'] = $this->fieldRulePrefix($config, MemberFieldKeyEnum::BIRTHDAY->value) . 'date_format:Y-m-d';
        $rules['id_number'] = $this->fieldRulePrefix($config, MemberFieldKeyEnum::ID_NUMBER->value) . 'string|max:100';

        // 證件照片收 base64，比照會員頭像由 model 存檔後改存網址
        $photo = $this->fieldRulePrefix($config, MemberFieldKeyEnum::ID_PHOTO->value);
        $rules['id_front_image'] = [trim($photo, '|'), new Base64Image];
        $rules['id_back_image'] = [trim($photo, '|'), new Base64Image];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'realname.required' => __('param.realname.required'),
            'realname.max' => __('param.realname.max', ['max' => 100]),
            'bank_id.required' => __('param.bank_id.required'),
            'bank_id.integer' => __('param.bank_id.integer'),
            'bank_name.required' => __('param.bank_name.required'),
            'bank_name.max' => __('param.bank_name.max', ['max' => 100]),
            'bank_account.required' => __('param.bank_account.required'),
            'bank_account.max' => __('param.bank_account.error'),
            'birthday.required' => __('param.birthday.required'),
            'birthday.date_format' => __('param.birthday.date_format'),
            'id_number.required' => __('param.id_number.required'),
            'id_number.max' => __('param.id_number.max', ['max' => 100]),
            'id_front_image.required' => __('param.id_front_image.required'),
            'id_back_image.required' => __('param.id_back_image.required'),
        ];
    }

    /**
     * @return array<string, array{show:bool, required:bool}>
     */
    private function verificationConfig(): array
    {
        $member = $this->getUserContext()?->member;

        if ($member === null || $member->agent === null) {
            return [];
        }

        return app(MemberFieldSettingService::class)->verificationRules($member->agent);
    }

    /**
     * @param  array<string, array{show:bool, required:bool}>  $config
     */
    private function fieldRulePrefix(array $config, string $fieldKey): string
    {
        return ($config[$fieldKey]['required'] ?? false) === true ? 'required|' : 'nullable|';
    }
}
