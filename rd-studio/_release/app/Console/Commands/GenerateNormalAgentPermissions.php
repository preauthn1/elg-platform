<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Agent;
use App\Models\AgentPermission;

class GenerateNormalAgentPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate_normal_agent_permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '生成一般代理權限';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        $menu_ids = array(7,8,11,19,22,23,24,27,28,29,32,33);
        $agents = Agent::all();
        //$agents = Agent::where('id', 42771)->get();
        $agents->each(function ($item, $key) use($menu_ids) {
            foreach($menu_ids as $menu_id) {
                if (!AgentPermission::where('agent_id', $item->id)->where('agent_menu_id', $menu_id)->count()) {
                    $permission = new AgentPermission();
                    $permission->agent_id = $item->id;
                    $permission->agent_menu_id = $menu_id;
                    $permission->action = '';
                    $permission->admin = 0;
                    $permission->save();
                    echo $item->username . ' add permission '.$menu_id . PHP_EOL;
                }
            }
        });
    }
}
