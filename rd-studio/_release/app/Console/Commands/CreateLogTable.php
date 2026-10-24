<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use DB;

class CreateLogTable extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_log_table';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Log表';

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
        $types = array(
            'frontend',
            'agent',
            'backend',
            'cron'
        );
        foreach ($types as $type) {
            $this->createTable($type);
        }
    }

    private function createTable($type)
    {
        DB::statement("
        CREATE TABLE IF NOT EXISTS `access_log_" . $type . "_" . date('Ym', strtotime('+1months')) . "` (
            `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號',
            `content` longtext NOT NULL COMMENT '內容',
            `user` int(11) NOT NULL DEFAULT '0' COMMENT '操作者',
            `action` varchar(50) NOT NULL COMMENT '動作',
            `ip` varchar(255) NOT NULL COMMENT 'IP',
            `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;");
    }
}