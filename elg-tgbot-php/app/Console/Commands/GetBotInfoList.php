<?php

namespace App\Console\Commands;

use App\DTOs\BotInfoDTO;
use App\Enums\PlatformApiCodeEnum;
use App\Helpers\AESHelper;
use App\Helpers\CacheKeysHelper;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GetBotInfoList extends Command
{
    /**
     * php artisan app:get-bot-info-list
     *
     * @var string
     */
    protected $signature = 'app:get-bot-info-list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get bot info list from Api Frontend, includes bot_id bot_code and tg api bot token';

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
        Log::info('Bot info list:');

        if (env('APP_ENV') === 'local') {
            $datas = [];
            $datas[] = [
                'name' => env('LOCAL_TGBOT_ID'),
                'code' => env('LOCAL_TGBOT_CODE'),
                'bot_id' => env('LOCAL_TGBOT_ID'),
                'bot_token' => env('LOCAL_TGBOT_TOKEN'),
                'invite_code' => env('LOCAL_TGBOT_INVITE_CODE'),
            ];
        } else {
            $datas = $this->getBotInfos();
        }

        foreach ($datas as $index => $data) {
            Log::info('Bot name ' . $data['name']);
            Log::info('Bot code ' . $data['code']);
            Log::info('Bot bot_id ' . $data['bot_id']);

            try {
                $cacheKey = CacheKeysHelper::getBotInfoKey($data['bot_id']);

                Log::info("Updating cache key: $cacheKey");

                $newData = new BotInfoDTO(
                    $data['bot_id'],
                    $data['code'],
                    $data['bot_token'],
                    $data['invite_code']
                );

                Cache::forever($cacheKey, $newData);

                Log::info("Update cache key: $cacheKey, done.");

                Log::info("Updating tg api webhook endpoint for bot_id: {$newData->id}");

                if (env('APP_ENV') != 'local') {
                    $this->updateTgApiWebhookEndpoint($newData->id, $newData->token);
                }

                Log::info('Update done.');
            } catch (\Exception $e) {
                Log::error('Failed to update bot info, msg: ' . $e->getMessage());
            }
        }

        $this->info('Update bot info list done.');
    }

    private function getBotInfos(): array
    {
        $url = config('platform_api.url') . '/tgBot/brandList';
        $API_AES_KEY = config('platform_api.aes_key');
        $API_IV = config('platform_api.iv');
        $API_MD5_KEY = config('platform_api.md5_key');

        $params = [
            'key' => config('platform_api.secret_key'),
        ];

        $json_content = empty($params) ? '' : json_encode($params);
        $aes = new AESHelper($API_AES_KEY, $API_IV);
        $encryptParams = $aes->encrypt($json_content);
        $key = md5($encryptParams . $API_MD5_KEY);

        Log::info('Get bot info list', [
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

        if ($response['code'] !== PlatformApiCodeEnum::SUCCESS->value) {
            Log::error('Failed to get bot info list', $response);
            $this->fail('Failed to get bot info list');

            return [];
        }

        if (empty($response['data'])) {
            Log::error('No bot info found');
            $this->fail('No bot info found');

            return [];
        }

        return $response['data'];
    }

    private function updateTgApiWebhookEndpoint(string $botId, string $botToken): void
    {
        // https://example.invalid/bot<token>/setWebhook?url=<api>
        $endpoint = config('app.url') . "/api/TGBot/{$botId}";
        $url = config('tgbot.url', 'https://example.invalid/bot') . $botToken . "/setWebhook?url={$endpoint}";

        $response = $this->client->get($url);

        $response = json_decode($response->getBody(), true);

        if ($response['ok'] !== true) {
            throw new \Exception('Failed to update tg api webhook endpoint, msg: ' . ($response['description'] ?? 'no error msg'));
        }
    }
}
