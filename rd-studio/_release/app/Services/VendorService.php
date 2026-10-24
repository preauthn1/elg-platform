<?php

namespace App\Services;

use App\Repositories\VendorRepository;

class VendorService
{
    protected $repository;
	
	public function __construct()
    {
        $this->repository = new VendorRepository();
    }

    public function getThirdPartyCodeByVendorId($vendor_id)
    {
        return $this->repository->getThirdPartyCodeByVendorId($vendor_id);
    }

    public function getVendorIdsByThirdPartyCode($third_party_code)
    {
        return $this->repository->getVendorIdsByThirdPartyCode($third_party_code);
    }

    public function getVendorsByThirdPartyCode($third_party_code)
    {
        return $this->repository->getVendorsByThirdPartyCode($third_party_code);
    }

    public function getHourlyVendors($vendor_id)
    {
        return $this->repository->getHourlyVendors($vendor_id);
    }

    public function getDailyVendors($vendor_id)
    {
        return $this->repository->getDailyVendors($vendor_id);
    }
}
