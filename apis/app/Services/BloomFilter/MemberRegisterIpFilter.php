<?php

declare(strict_types=1);

namespace App\Services\BloomFilter;

use App\Models\Member;
use Illuminate\Support\Facades\Log;

class MemberRegisterIpFilter extends BloomFilterService
{
    public string $bucket = 'duplicate_register_ip';

    /**
     * @param  array  $ips  驗證的ip，可丟字串或是長整數
     * @param  array  $memberIds  ip 所屬的會員id
     * @param  bool  $returnLongIp  是否回傳長整數IP
     */
    public function getDuplicateData(array $ips, array $memberIds = [], bool $returnLongIp = false): array
    {
        $existData = [];
        $notExistData = [];
        // 註冊 IP 可能為 null，無從比對重複（long2ip 會 TypeError），直接略過
        foreach (array_filter($ips, fn ($ip) => $ip !== null) as $ip) {
            $stringIp = (is_string($ip)) ? $ip : long2ip($ip);
            $longIp = (is_string($ip)) ? ip2long($ip) : $ip;
            if ($this->exists($this->bucket, $stringIp)) {
                $existData[$stringIp] = $longIp;
            } else {
                $notExistData[$stringIp] = $longIp;
            }
        }

        Log::info('Redis member IP existData', ['existData' => $existData]);
        Log::info('Redis member IP notExistData', ['notExistData' => $notExistData]);

        if ($notExistData) {
            $duplicateData = Member::whereIn('register_ip', $notExistData)
                ->when($memberIds, function ($query) use ($memberIds) {
                    return $query->whereNotIn('id', $memberIds);
                })
                ->distinct()
                ->pluck('register_ip')
                ->toArray();

            Log::info('DB member IP duplicateData', ['duplicateData' => $duplicateData]);

            foreach ($duplicateData as $ip) {
                $stringIp = long2ip($ip);
                $this->add($this->bucket, $stringIp);

                $existData[$stringIp] = $ip;
            }
        }

        $return = $returnLongIp ? $existData : array_flip($existData);
        Log::info('return member IP existData', ['existData' => $return]);

        return $return;
    }
}
