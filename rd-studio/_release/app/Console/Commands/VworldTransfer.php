<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use Illuminate\Support\Facades\Storage;
use DB;
use Hash;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgAccount;
use App\Models\JokerAccount;
use App\Models\ScrAccount;
use App\Models\HaolongAccount;
use App\Models\GreatWallAccount;
use App\Models\Ace333Account;
use App\Models\AgentVendor;
use App\Models\VplayAccount;
use App\Models\Member;
use App\Models\Setting;
use App\Models\TransactionLog;
use App\Models\User;

class VworldTransfer extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vworld_transfer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'vworld轉移';

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
        //查agent資料
        $agents = DB::connection('game_agent')
            ->table('A_Agent as a')
            ->leftJoin('A_Agent_Data as d', 'a.AgentID', 'd.AgentID')
            ->select(
                'a.AgentID',
                'a.AgentAccount',
                'a.AgentNickName',
                'a.AgentPassword',
                'd.InviteCode',
                'd.AssignAgentAccount'
            )
            ->where('a.DeleteFlag', 0)
            ->get();
        $assign = array();
        $assign_cs = array();
        foreach ($agents as $row) {
            if ($row->InviteCode != '') {
                echo "********* $row->AgentAccount ******";
                $cs = $this->getApiInfo();
                //寫入遊戲平台表
                $data = [
                    'username' => $row->AgentAccount,
                    'password' => $row->AgentPassword,
                    'nickname' => $row->AgentNickName,
                    'code' => $row->InviteCode,
                    'parent' =>  isset($assign[$row->AssignAgentAccount]) ? $assign[$row->AssignAgentAccount] : 0,
                    'frontend_register' => 1,
                    'mobile_validate' => 1,
                    'status' => 1,
                    'CS_API_USER' => $cs['user'],
                    'CS_API_KEY' => $cs['key'],
                ];
                $result = Agent::create($data);
                //保存新的AGENT ID
                $assign[$row->AgentAccount] = $result->id; //代理用
                $assign[$row->AgentID] = $result->id; //會員用

                // 給予所有選單的權限
                $data = array();
                $data['agent_id'] = $result->id;
                $menus = AgentMenu::get();
                foreach ($menus as $menu) {
                    $data['agent_menu_id'] = $menu['id'];
                    AgentPermission::create($data);
                }

                //協助開啟vendor
                $vendors = [1, 2, 3, 4, 5, 6, 8, 9];
                $data = ['agent_id' => $result->id, 'status' => 1];
                foreach ($vendors as $v) {
                    $data['vendor_id'] = $v;
                    AgentVendor::create($data);
                }

                //寫入客服表
                $data = [
                    'role_id' => 2,
                    'parent_id' =>  isset($assign_cs[$row->AssignAgentAccount]) ? $assign_cs[$row->AssignAgentAccount] : 0,
                    'username' => $row->AgentAccount,
                    'password' => $row->AgentPassword,
                    'API_USER' => $cs['user'],
                    'API_KEY' => $cs['key'],
                    'nickname' => $row->AgentNickName,
                    'status' => 1,
                ];
                $result = User::on('cs')->create($data);

                //建立客服會員
                // $m = DB::connection('cs')->insertGetId("INSERT INTO `members` SET user_id='$result->id', third_party_id='0',username='agent_$result->id',nickname='Agent Service',status=1");
                $m = DB::connection('cs')
                    ->table('members')
                    >insertGetId(
                        [
                            'user_id' => $result->id,
                            'third_party_id' => '0',
                            'username' => $row->AgentAccount,
                            'nickname' => 'Agent Service',
                            'status' => 1
                        ]
                    );
                //加入最上層的客服當好友
                DB::connection('cs')->insert("INSERT INTO `friends` SET self_id='$m', other_id='1',status='1'");
                DB::connection('cs')->insert("INSERT INTO `friends` SET other_id='$m', self_id='1',status='1'");

                //保存新的CS AGENT ID
                $assign_cs[$row->AgentAccount] = $result->id;

                //寫入客服setting
                $result = Setting::on('cs')
                    ->create(['agent_id' => $result->id, 'api_url' => env('FRONTEND_API_URL')]);
            } else {
                echo "---------- $row->AgentAccount-------------";
            }
        }

        //查詢會員資料
        $members = DB::connection('game_branch')
            ->table('A_Member as m')
            ->leftJoin('A_Agent_Member as a', 'm.MemberID', 'a.MemberID')
            ->leftJoin('A_Agent_Member as shw', function ($join) {
                $join->on('a.AgentID', '=', 'shw.AgentID')
                    ->where('shw.IsShadow', 1);
            })
            ->leftJoin('A_Member as shwm', 'shw.MemberID', 'shwm.MemberID')
            ->leftJoin('A_Member_Data as d', 'm.MemberID', 'd.MemberID')
            ->leftJoin('A_MemberFinance as f', 'm.MemberID', 'f.MemberID')
            ->select(
                'm.MemberID',
                'm.MemberAccount',
                'm.NickName',
                'm.CountryCode',
                'm.Mobile',
                'm.Memberpassword',
                'f.Point',
                'shwm.IMCertify',
                'a.AgentID',
                'a.AGMemberAccount',
                'd.IDKiss918',
                'd.IDAce333'
            )
            ->where('m.DeleteState', 0)
            ->get();

        foreach ($members as $row) {
            echo "********* $row->MemberID ---- $row->MemberAccount ******";
            //寫入會員表
            $data = [
                'parent' => isset($assign[$row->AgentID]) ? $assign[$row->AgentID] : 0,
                'username' => $row->MemberAccount,
                'password' => $row->Memberpassword,
                'nickname' => $row->NickName,
                'balance' => $row->Point,
                'mobile' => $row->CountryCode . $row->Mobile,
                'mobile_validate' => 1,
                'status' => 1
            ];
            $result = Member::create($data);
            //新增初始餘額
            $log = [
                'transaction_no' => '0',
                'member_id' => $result->id,
                'type' => 0,
                'rate' => 1,
                'point' => $row->Point,
                'balance' => $row->Point,
                'status' => 1000,
                'remark' => '轉移初始餘額',
                'operator' => 'system',
            ];
            TransactionLog::create($log);
            if ($row->AGMemberAccount != '') {
                //建立AG帳號
                $ag = [
                    'username' => $row->AGMemberAccount,
                    'password' => $row->IMCertify,
                    'member_id' => $result->id,
                ];
                AgAccount::create($ag);
            }

            //建立Joker帳號
            $joker = [
                'username' => $row->MemberAccount,
                'member_id' => $result->id,
            ];
            JokerAccount::create($joker);

            //建立918帳號, 密碼不用理，進入遊戲會改
            if ($row->IDKiss918 != 'Kiss918') {
                $src = [
                    'username' => $row->MemberAccount,
                    'player_id' => $row->IDKiss918,
                    'member_id' => $result->id,
                ];
                ScrAccount::create($src);
            }

            //建立豪龍帳號
            $src = [
                'username' => $row->MemberAccount,
                'member_id' => $result->id,
                'is_vworld' => 1,
            ];
            HaolongAccount::create($src);

            //建立GW99帳號，, 密碼不用理，進入遊戲會改
            //會員帳號=代理商帳號(jkl_0701001)+轉換後的MemberID(轉換後的MemberID方式，Member由左至右，+1~+N，取個位數。EX：12345 => 24680)
            $member_array = str_split($row->MemberID);
            $new_member_id = '';
            for ($i = 1; $i <= count($member_array); $i++) {
                $new_member_id .= ($member_array[$i - 1] + $i) % 10;
            }
            $greate_wall = [
                'username' =>  $new_member_id,
                'member_id' => $result->id,
            ];
            GreatWallAccount::create($greate_wall);

            //建立ace333帳號
            if ($row->IDAce333 != 'Ace333') {
                $ace333 = [
                    'username' => $row->MemberAccount,
                    'password' => Hash::make('Aa' . $row->MemberID),
                    'player_id' => $row->IDAce333,
                    'member_id' => $result->id,
                ];
                Ace333Account::create($ace333);
            }

            //建立vplay帳號
            $vplay = [
                'username' => $row->MemberAccount,
                'member_id' => $result->id,
            ];
            VplayAccount::create($vplay);
        }
    }

    private function getApiInfo()
    {
        $user = str_random(10);
        $key = str_random(24);
        $is_exist = Agent::where('CS_API_USER', $user)->where('CS_API_USER', $key)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getApiInfo();
        } else {
            return [
                'user' => $user,
                'key' => $key
            ];
        }
    }
}