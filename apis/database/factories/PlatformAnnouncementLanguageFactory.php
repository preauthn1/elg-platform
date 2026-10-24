<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformAnnouncementLanguageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform_announcement_id' => 1,
            'language_id' => 2,
            'title' => '標題 tw',
            'content' => '內容 tw',
            'platform_announcement_image_id' => 1,
        ];
    }
}
