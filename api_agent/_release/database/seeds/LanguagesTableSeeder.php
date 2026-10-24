<?php

use Illuminate\Database\Seeder;
use App\Language;

class LanguagesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $language = array(
            'en' => 'English',
            'zh-TW' => '中文-台灣',
            'zh-CN' => '中文-中国',
        );
        foreach ($language as $key => $value) {
            Language::create([
                'name' => $value,
                'code' => $key,
                'status' => 1,
                'admin' => 1,
            ]);

        }
    }
}
