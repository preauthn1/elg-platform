<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\MemberTeam;

use App\Http\Requests\AbstractRequest;

class AchievementRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'nullable|integer',
        ];
    }
}
