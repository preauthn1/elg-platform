<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\AES;
use App\FirebaseCloudMessage;
use App\Member;

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
        Common::cron_log(0, 'start-' . date('Y-m-d H:i:s'), 'firebase_send_message');
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
        Common::cron_log(0, 'total row-' . count($messages), 'firebase_send_message');
        Common::cron_log(0, 'end-' . date('Y-m-d H:i:s'), 'firebase_send_message');
    }

    private function getToken($memberId)
    {
        $member = Member::find($memberId);
        if (is_object($member) && $member->device_id != '') {
            $strEncode = $member->device_id;
            $class = new AES();
            $token = [REDACTED];
            return $token;
        } else {
            return false;
        }
    }

    private function post($data)
    {
        Common::cron_log(0, $data, 'firebase_send_message');
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
        Common::cron_log(0, $result, 'firebase_send_message');
        return json_decode($result, true);
    }
}