<?php

namespace App\Repositories;

use App\Models\Vendor;
use DB;

class VendorRepository extends Repository
{
    
    public function __construct()
    {
        $this->model = new Vendor();
    }

    public function getVendorById($id)
    {
        return $this->model
            ->where('id', $id)
            ->first();
    }

    public function getThirdPartyCodeByVendorId($id)
    {
        $vendor = Vendor::find($id);
        return $vendor->third_party_code;
    }

    public function getVendorIdsByThirdPartyCode($code)
    {
        return Vendor::where('third_party_code', $code)->pluck('id')->toArray();
    }

    public function getVendorsByThirdPartyCode($code)
    {
        return Vendor::where('third_party_code', $code)->get();
    }

    public function getHourlyVendors($vendor_id = 0)
    {
        return $vendor = Vendor::where('summary_daily', 1)
            ->where(function ($sub) use ($vendor_id) {
                $sub->where('id' , $vendor_id)
                    ->orWhereRaw($vendor_id . ' = 0');
            })
            ->groupBy('third_party_code')
            ->orderBy('id')
            ->pluck('id')
            ->toArray();
    }

    public function getDailyVendors($vendor_id = 0)
    {
        return $vendor = Vendor::where('summary_daily', 2)
            ->where(function ($sub) use ($vendor_id) {
                $sub->where('id' , $vendor_id)
                    ->orWhereRaw($vendor_id . ' = 0');
            })
            ->groupBy('third_party_code')
            ->orderBy('id')
            ->pluck('id')
            ->toArray();
    }
}