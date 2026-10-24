<?php

declare(strict_types=1);

namespace App\Services\BloomFilter;

use App\Models\Member;
use Illuminate\Support\Facades\Log;

class MemberFirstNameFilter extends BloomFilterService
{
    public string $bucket = 'duplicate_first_name';

    /**
     * @param  array  $firstNames  驗證的 firstName
     * @param  array  $memberIds  firstName 所屬的會員id
     */
    public function getDuplicateData(array $firstNames, array $memberIds = []): array
    {
        $existData = [];
        $notExistData = [];
        // 會員可能沒填真實姓名，null 無從比對重複（雜湊會 TypeError），直接略過
        foreach (array_filter($firstNames, fn ($value) => $value !== null) as $value) {
            if ($this->exists($this->bucket, $value)) {
                $existData[] = $value;
            } else {
                $notExistData[] = $value;
            }
        }

        Log::info('Redis member firstName existData', ['existData' => $existData]);
        Log::info('Redis member firstName notExistData', ['notExistData' => $notExistData]);

        if ($notExistData) {
            $duplicateData = Member::whereIn('first_name', $notExistData)
                ->when($memberIds, function ($query) use ($memberIds) {
                    return $query->whereNotIn('id', $memberIds);
                })
                ->distinct()
                ->pluck('first_name')
                ->toArray();

            Log::info('DB member firstName duplicateData', ['duplicateData' => $duplicateData]);
            foreach ($duplicateData as $value) {
                $this->add($this->bucket, $value);

                $existData[] = $value;
            }
        }

        Log::info('return member firstName existData', ['existData' => $existData]);

        return $existData;
    }
}
