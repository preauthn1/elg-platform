<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentSettingDefine;
use App\Models\LanguageText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * php artisan db:seed --class=SyncAgentSettingDefinesSeeder
 */
class SyncAgentSettingDefinesSeeder extends Seeder
{
    private array $default = [
        'setting_id' => '',
        'parent' => '',
        'type' => '',
        'is_visible' => 1,
        'agent_edit' => 0,
        'memo' => '',
        'language_text_code' => '',
        'default_value' => '',
        'data_type' => '',
        'is_data_required' => 0,
        'enable_agents' => null,
        'sort' => 0,
        'created_by' => 0,
        'language_texts' => [],
    ];

    public function run(): int
    {
        $output = $this->command?->getOutput();

        $output?->writeln('SyncAgentSettingDefinesSeeder 開始執行...');

        $source = require database_path('data/agent_setting_defines.php');

        // 先查詢所有已存在的 vendor id
        $existingIds = AgentSettingDefine::pluck('setting_id')->toArray();

        $collect = collect($source);
        $updated = 0;
        $updatedLanguageTexts = 0;
        $collect->chunk(100)->each(function (Collection $chunk) use ($existingIds, &$updated, &$updatedLanguageTexts) {
            $batch = [];
            $languageTexts = [];
            foreach ($chunk as $id => $data) {
                if (!$data) {
                    continue;
                }

                $data = array_merge($this->default, $data);

                if (!empty($data['language_texts'])) {
                    foreach ($data['language_texts'] as $language => $text) {
                        $languageTexts[] = [
                            'language_text_code' => $data['language_text_code'],
                            'language_id' => $language,
                            'text' => $text,
                        ];
                    }
                }
                unset($data['language_texts']);

                if (!in_array($id, $existingIds)) {
                    $batch[] = array_merge($data, ['setting_id' => $id]);
                }
            }

            if (!empty($batch)) {
                AgentSettingDefine::insert($batch);
                $updated += count($batch);
            }

            if (!empty($languageTexts)) {
                $updatedLanguageTexts += LanguageText::query()->insertOrIgnore($languageTexts);
            }
        });

        $output?->writeln('SyncAgentSettingDefinesSeeder 已執行完成。');
        $output?->writeln("更新 {$updated} 個 AgentSettingDefine");
        $output?->writeln("更新 {$updatedLanguageTexts} 個 LanguageText");

        return $updated;
    }
}
