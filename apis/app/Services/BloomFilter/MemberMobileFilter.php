<?php

declare(strict_types=1);

namespace App\Services\BloomFilter;

use App\Models\Member;
use Illuminate\Support\Facades\Log;

class MemberMobileFilter extends BloomFilterService
{
    public string $bucket = 'duplicate_mobile';

    /**
     * @param  array  $mobiles  驗證的 mobile
     * @param  array  $memberIds  mobile 所屬的會員id
     */
    public function getDuplicateData(array $mobiles, array $memberIds = []): array
    {
        $existData = [];
        $notExistData = [];
        // 會員可能沒綁手機，null 無從比對重複（雜湊會 TypeError），直接略過
        foreach (array_filter($mobiles, fn ($value) => $value !== null) as $value) {
            if ($this->exists($this->bucket, $value)) {
                $existData[] = $value;
            } else {
                $notExistData[] = $value;
            }
        }

        Log::info('Redis member mobile existData', ['existData' => $existData]);
        Log::info('Redis member mobile notExistData', ['notExistData' => $notExistData]);

        if ($notExistData) {
            $duplicateData = Member::whereIn('mobile', $notExistData)
                ->when($memberIds, function ($query) use ($memberIds) {
                    return $query->whereNotIn('id', $memberIds);
                })
                ->distinct()
                ->pluck('mobile')
                ->toArray();

            Log::info('DB member mobile duplicateData', ['duplicateData' => $duplicateData]);

            foreach ($duplicateData as $value) {
                $this->add($this->bucket, $value);

                $existData[] = $value;
            }
        }

        Log::info('return member mobile existData', ['existData' => $existData]);

        return $existData;
    }
}
