<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\FirebaseCloudMessage;
use App\Models\Member;
use App\AES;
use DB;
use App\REDIS;

class FirebaseCloudMessaging extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firebase_send_message';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '推播';

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
        $messages = FirebaseCloudMessage::where('status', 0)->get();
        foreach ($messages as $row) {
            $token = [REDACTED];
            if ($token != '') {
                $data = [
                    'to' => $token,
                    'data' => [
                        'url' => $row->url
                    ],
                    'notification' => [
                        'body' => $row->content,
                        'title' => $row->title,
                        'click_action' => 'customer.action.fcm.MESSAGING_EVENT'
                    ]
                ];
                $result = $this->post($data);
                if ($result) {
                    // 因為一次只發一則，所以成功的數量會等於1
                    if ($result['success'] == 1) {
                        $update = ['status' => 1, 'multicast_id' => $result['multicast_id'], 'message_id' => $result['results'][0]['message_id']];
                        echo $row->id . '-success';
                    } else {
                        $update = ['status' => 2, 'multicast_id' => $result['multicast_id'], 'error' => $result['results'][0]['error']];
                        echo $row->id . '-fail';
                    }
                } else {
                    $update = ['status' => 2, 'error' => 'send error'];
                    echo $row->id . '-send error';
                }
            } else {
                $update = ['status' => 2, 'error' => 'no token'];
                echo $row->id . '-no token';
            }
            FirebaseCloudMessage::where('id', $row->id)->update($update);
        }
    }

    private function getToken($memberId)
    {
        $member = Member::find($memberId);
        try{
            if (is_object($member) && $member->device_id != '') {
                $strEncode = $member->device_id;
                $class = new AES();
                //IOS測試機
                // $strEncode = '972FE47E1882ED91EAF882EA29CF80E32D4CC3D356C7BE8511CCEBE4064A9F9C9E73749BE0EAF29133FBE51B4B299E5832F0E8A7E7CBD76B2CE3CD66A55918F3568301E18D66DFB95D1EBED3B700F6F8B3DA6A30D2E1F7932ABE8B59D07986874CC8B4CB4B0B0BFB52258962DE00635B0CD39372935576E93EEFA4E6097837FF2C0AEC0C505C26BEA63550746051B120E0B9F9AF3E2255F8DBACB9927D00A093';
                // TEST 3 ANDORID
                // $strEncode = '10BEB90498011AC217B949A126E86837989E4740A286ACBE07562D9937CD065BB5F953CA5F4A309B9CDC7E6F03C83AAFC83A824CA3DEE312CE2DA96B5D6A70FBFC54B5D1062B6998A163A47D18FE1C56E441237A36A917B239B6023B92F3991974D9D2812AF04436C1A213F15BF1A71FAD90BFA979470801A6676D00C8A2BA484C3781E1E47A3DC6EBF08C676FC555654C4B53D9FCFDDE62A9F6B9A68CC81C9B';
                //TEST 2 IOS
                //$strEncode = 'A391E0925D71247D51C32BCDCEF419B934AE41779C7B7E4ABFE01FFC51F7087EAD25117A4D87363A8124651621776C886F3E0BC8DAF305AD15A442228158570B7DE8DA4CDBA2B6858FE76BD2B14F142CE2545E1C33AE7F5DA4E8A3D1B5F9057BF0FC0FB71B64E8A8177986EF2EAE2FD49F8762E19533163F881F34C464396DB7EAD3C4C26D053CC38A1DA1D923F13364ECEA16904108065F6E14F24DDC7C476E';
                $token = [REDACTED];
                return $token;
            } else {
                return false;
            }
        } catch (\Exception $exception) {
            return false;
        }
    }

    private function post($data)
    {
        Common::log(0, $data, 'firebase_send_message');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('PUSH_FIREBASE_URL'));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json',
            'Authorization:key=' . env('PUSH_FIREBASE_SERVER_KEY')
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, 'firebase_send_message');
        return json_decode($result, true);
    }
}