<?php

namespace App\Models;

use App\Enums\LanguageTypeEnum;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatLanguageSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'chat_id',
        'language',
    ];

    protected $casts = [
        'bot_id' => 'string',
        'chat_id' => 'string',
        'language' => LanguageTypeEnum::class,
    ];

    protected function language(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value) => strtolower($value),
            get: fn (mixed $value) => strtolower($value)
        );
    }
}
