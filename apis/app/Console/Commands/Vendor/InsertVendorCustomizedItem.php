<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan tool:insert_vendor_customized_item 106 100004
 */
class InsertVendorCustomizedItem extends Command
{
    protected $signature = 'vendor:insert_vendor_customized_item {vendorId?} {agentId?} ';

    protected $description = '新增 vendor customized item';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $vendorId = (int) $this->argument('vendorId');
            if (!$vendorId) {
                $vendorId = (int) $this->ask('請輸入遊戲商ID');
            }
            $agentId = $this->argument('agentId');
            if (!$agentId) {
                $agentId = (int) $this->ask('請輸入代理商ID');
            }

            $agent = Agent::where('id', $agentId)->first();
            $vendor = Vendor::where('id', $vendorId)->first();
            if (!$agent) {
                $this->line('agent 不存在, agent_id:' . $agentId);

                return;
            }
            if (!$vendor) {
                $this->line('vendor 不存在, vendor_id:' . $vendorId);

                return;
            }

            $this->createVendorCustomizedItem($vendor, $agent);
        });
    }

    public function createVendorCustomizedItem(Vendor $vendor, Agent $agent): void
    {
        $vendorCustomizedItem = VendorCustomizedItem::query()
            ->where('vendor_id', $vendor->id)
            ->where('agent_group_id', $agent->vendor_customize_group_id)
            ->first();

        if ($vendorCustomizedItem) {
            $this->line(sprintf('vendor_id: %s, vendor_customized_item_id: %s, 已存在', $vendor->id, $vendorCustomizedItem->id));

            return;
        }

        $maxSort = VendorCustomizedItem::query()
            ->where('agent_group_id', $agent->vendor_customize_group_id)
            ->max('sorts');

        $vendorCustomizedItem = new VendorCustomizedItem;
        $vendorCustomizedItem->agent_group_id = $agent->vendor_customize_group_id;
        $vendorCustomizedItem->item_type = 1;
        $vendorCustomizedItem->vendor_id = $vendor->id;
        $vendorCustomizedItem->game_id = 0;
        $vendorCustomizedItem->type_id = 0;
        $vendorCustomizedItem->path = '';
        $vendorCustomizedItem->is_new = 0;
        $vendorCustomizedItem->is_hot = 0;
        $vendorCustomizedItem->status = 1;
        $vendorCustomizedItem->sorts = $maxSort + 1;
        $vendorCustomizedItem->admin = 0;
        $vendorCustomizedItem->save();
        $this->line(sprintf('vendor_id: %s, 新增 vendor_customized_item_id: %s', $vendor->id, $vendorCustomizedItem->id));

    }
}
