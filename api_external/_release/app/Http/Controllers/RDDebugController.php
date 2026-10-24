<?php

namespace App\Http\Controllers;

use App\Helpers\Common;
use App\PgBetRecord;
use Illuminate\Http\Request;
use App\PgRoundBetRecord;
use DB;
use App\Helpers\TelegramHelper;

class RDDebugController extends Controller
{
    /**
     * RDDebug/TG/Message
     */
    public function testTelegramMessage(Request $request)
    {
        try {           
            $msg = "測試TG訊息";
            TelegramHelper::sendMessage($msg , '');

            return response()->json([
                'result' => 'success',
            ]);          

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}