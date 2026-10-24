<?php

namespace App\Services;

use App\Repositories\MemberWalletRepository;

class MemberWalletService extends Service
{
    protected $member_id;
    protected $agent_group_id;
    protected $wallet_type_id;
    protected $language_id;
    protected $balance;

    //public function __construct($member_id, $wallet_type_id)
    public function __construct(MemberWalletRepository $repository)
    {
        //$this->member_id = $request->ID;
        //$this->wallet_type_id = $request->WALLET_TYPE_ID;
        //$this->balance = 0;
        //$this->repository = new MemberWalletRepository($member_id);
        $this->repository = $repository;
    }

    public function init($request)
    {
        $this->member_id = $request->ID;
        $this->agent_group_id = isset($request->CASH_FLOW_GROUP) ? $request->CASH_FLOW_GROUP : 0;
        $this->wallet_type_id = isset($request->WALLET_TYPE_ID) ? $request->WALLET_TYPE_ID : 1;
        $this->language_id = isset($request->LANGUAGE) ? $request->LANGUAGE : 1;
        return $this;
    }

    public function test()
    {
        return $this->wallet_type_id;
        return $this->member_id;
    }

    public function create()
    {
        return 'service create';
        return $this->repository->create($this->wallet_type_id, $this->balance);
    }

    public function find()
    {
        return $this->repository->find(
            $this->member_id,
            $this->agent_group_id,
            $this->language_id,
            $this->wallet_type_id
        );
    }

    public function findAll()
    {
        return $this->repository->find(
            $this->member_id,
            $this->agent_group_id,
            $this->language_id,
            0
        );
    }
}
