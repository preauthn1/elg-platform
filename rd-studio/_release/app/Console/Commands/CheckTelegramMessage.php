<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TelegramMessage;
use App\Helpers\Common;
use App\Services\TelegramService;
use DB;

class CheckTelegramMessage extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_telegram_message'; //php artisan check_telegram_message，每分鐘檢查一次

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢查Telegram Message';

    protected $tgService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tgService = new TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $messages = TelegramMessage::where('status', 0)->get();
            if (count($messages) > 0) {
                foreach($messages as $message) {
                    //發送telegram通知
                    $this->tgService->sendMessageByChatId($message->message, $message->chat_id);  //Markdown OR HTML

                    TelegramMessage::find($message->id)
                        ->update([
                            'status' => 1
                        ]);
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

}