<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\Base64Image;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Base64Image 規則測試
 *
 * php artisan test tests/Unit/Rules/Base64ImageTest.php
 */
class Base64ImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::setLocale('en');
    }

    public function test_invalid_format_message_lists_accepted_formats(): void
    {
        $validator = Validator::make(
            ['image' => 'data:image/png;base64,' . base64_encode('plain text, not an image')],
            ['image' => [new Base64Image]]
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('Only jpg/png/gif/webp/heic/heif images are supported', $validator->errors()->first('image'));
    }

    public function test_too_large_message_shows_size_limit(): void
    {
        $binary = "\x89PNG\r\n\x1A\n" . str_repeat("\0", 600 * 1024);

        $validator = Validator::make(
            ['image' => 'data:image/png;base64,' . base64_encode($binary)],
            ['image' => [new Base64Image]]
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('Image size cannot exceed 500KB', $validator->errors()->first('image'));
    }
}
