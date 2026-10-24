<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Game;

use App\Http\Requests\AbstractRequest;

class SetFavouriteGameRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'game_id' => 'required|digits_between:0,10',
            'status' => 'required|boolean',
        ];
    }
}
