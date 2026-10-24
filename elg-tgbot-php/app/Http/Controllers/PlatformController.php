<?php

namespace App\Http\Controllers;

use App\Helpers\AESHelper;
use App\Http\Requests\PlatformBroadcastRequest;
use App\Services\PlatformHandleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlatformController extends Controller
{
    /**
     * POST api/platform/broadcast
     *
     * @response {
     *  "success_chat_ids": [
     *      "chat_id1", "chat_id2"
     *  ],
     *  "failed_chat_ids": [
     *      { "chat_id": "chat_id3", "message": "error message" }
     *  ],
     * }
     */
    public function broadcast(Request $request)
    {
        $request->validate([
            'params' => 'required|string',
            'key' => 'required|string',
        ]);

        $params = $this->decryptParam($request->params, $request->key);

        $broadcastRequest = new PlatformBroadcastRequest($params);

        Validator::make($params, $broadcastRequest->rules())->validate();

        $result = (new PlatformHandleService)->handleBroadcast($broadcastRequest);

        return response()->json($result);
    }

    private function decryptParam(string $params, string $key): array
    {
        $params = str_replace(' ', '+', $params);
        $params = str_replace('\\', '', $params);
        if ($key != md5($params . config('platform_api.md5_key'))) {
            throw new \Exception('Invalid key');
        }

        $aes = new AESHelper(config('platform_api.aes_key'), config('platform_api.iv'));

        return json_decode($aes->decrypt($params), true);
    }
}
