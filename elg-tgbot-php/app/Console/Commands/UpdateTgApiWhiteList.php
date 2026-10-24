<?php

namespace App\Console\Commands;

use App\Helpers\CacheKeysHelper;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UpdateTgApiWhiteList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-tg-api-white-list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Telegram API white list, from https://example.invalid/resources/cidr.txt';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            Log::info('UpdateTgApiWhiteList start');
            $client = new Client;
            $response = $client->get(config('tgbot.tg_whitelist_url'));
            $content = $response->getBody()->getContents();

            // update cache
            $cacheKey = CacheKeysHelper::getTgApiWhiteListKey();
            Cache::forever($cacheKey, $content);

            // update file
            Storage::disk('local')->put(config('tgbot.tg_whitelist_file'), $content);

        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $responseBodyAsString = $response->getBody()->getContents();

            Log::error('UpdateTgApiWhiteList failed, TG API response error', [
                'status' => $status,
                'response' => $responseBodyAsString,
            ]);

            // Load from file
            $content = Storage::disk('local')->get(config('tgbot.tg_whitelist_file'));
            $cacheKey = CacheKeysHelper::getTgApiWhiteListKey();
            Cache::forever($cacheKey, $content);

        } catch (\Exception $e) {
            Log::error('UpdateTgApiWhiteList failed', [
                'message' => $e->getMessage(),
            ]);

        }
    }
}
