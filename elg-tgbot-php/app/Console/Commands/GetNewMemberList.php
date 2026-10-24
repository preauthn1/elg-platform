<?php

namespace App\Console\Commands;

use App\DTOs\TgWebhookDataDTO;
use App\Enums\PlatformApiCodeEnum;
use App\Helpers\AESHelper;
use App\Helpers\LanguageHelper;
use App\Services\ActionServices\HomepageService;
use App\Singletons\PlatformApiCallService;
use App\Singletons\TgBotApiCallService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class GetNewMemberList extends Command
{
    /**
     * php artisan app:get-new-member-list
     *
     * @var string
     */
    protected $signature = 'app:get-new-member-list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get new member list from Api Frontend';

    private Client $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $keyLatest = 'new_member_start_latest';
        $latestId = (int) Redis::get($keyLatest);

        $list = $this->getNewMemberList($latestId);
        // debug
        // $list = [
        //     [
        //         'id' => 8,
        //         'brand_id' => 14,
        //         'tg_user_id' => 7602311364,
        //         'member_id' => 49429,
        //         'tg_password' => '05a4bc59',
        //         'created_at' => '2025-08-28 05:46:15',
        //         'updated_at' => '2025-08-28 05:46:15',
        //         'brand' => [
        //             'id' => 14,
        //             'code' => 'e41ae3fd-33e9-44c5-9468-9ebd88751491',
        //             'bot_id' => 'gff_tim_dev_bot',
        //         ],
        //     ],
        // ];

        foreach ($list as $item) {
            $id = $item['id'];
            $botId = $item['brand']['bot_id'];
            $chatId = $item['tg_user_id'];

            // redis 有資料代表已發送過，避免重複發送
            $key = 'new_member_start:' . $botId . ':' . $chatId;
            if (Redis::get($key)) {
                continue;
            }

            $tgWebhookData = new TgWebhookDataDTO(
                $botId,
                $chatId,
                0,
            );

            App::scoped(TgWebhookDataDTO::class, function ($app) use ($tgWebhookData) {
                return $tgWebhookData;
            });

            App::scoped(TgBotApiCallService::class, function ($app) use ($botId, $chatId) {
                return new TgBotApiCallService($botId, $chatId);
            });

            App::scoped(PlatformApiCallService::class, function ($app) use ($chatId, $botId) {
                return new PlatformApiCallService($botId, $chatId);
            });

            LanguageHelper::setLocale();

            HomepageService::init()->showPage();

            // 使用 redis 紀錄最近發送的用戶，避免重複發送
            Redis::setex($key, 86400, 1);
            Redis::setex($keyLatest, 86400, $id);

            Log::info('tgbot_new_member_homepage', [
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'member_id' => $item['member_id'],
            ]);
        }
    }

    private function getNewMemberList(int $latestId): array
    {
        $url = config('platform_api.url') . '/tgBot/newMemberList';
        $API_AES_KEY = config('platform_api.aes_key');
        $API_IV = config('platform_api.iv');
        $API_MD5_KEY = config('platform_api.md5_key');

        $params = [
            'key' => config('platform_api.secret_key'),
            'latest_id' => $latestId,
        ];

        $json_content = empty($params) ? '' : json_encode($params);
        $aes = new AESHelper($API_AES_KEY, $API_IV);
        $encryptParams = $aes->encrypt($json_content);
        $key = md5($encryptParams . $API_MD5_KEY);

        Log::info('tgbot_getNewMemberList request', [
            'url' => $url,
            'params' => $params,
        ]);

        $response = $this->client->post($url, [
            'form_params' => [
                'key' => $key,
                'params' => $encryptParams,
            ],
        ]);

        $response = json_decode($response->getBody(), true);

        Log::info('tgbot_getNewMemberList response', [
            'url' => $url,
            'params' => $params,
            'response' => $response,
        ]);

        if (PlatformApiCodeEnum::SUCCESS->value !== $response['code']) {
            return [];
        }

        return $response['data'];
    }
}
