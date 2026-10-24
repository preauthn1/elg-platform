<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\MemberTeam;

use App\Http\Requests\AbstractRequest;

class TeamManagementRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => 'required|between:4,30',
        ];
    }
}
