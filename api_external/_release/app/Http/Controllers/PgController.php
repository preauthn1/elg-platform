<?php

namespace App\Http\Controllers;

use App\Helpers\Common;
use App\PgBetRecord;
use Illuminate\Http\Request;
use App\PgRoundBetRecord;
use DB;

class PgController extends Controller
{

    /**
     * insertbetrecord
     *
     *
     * @bodyParam message
     * @response {
     *  "result": "成功回應:success",
     * }
     */
    public function insertbetrecord(Request $request)
    {
        try {
            Common::log($request->all(), 'pg_request');
            $result = $request->Messages;
            if (is_array($result)) {
                foreach ($result as $row) {
                    /*if (is_array($row) && $row['MessageType'] == '5') {
                        $data = array();
                        $data['TransactionId'] = $row['TransactionId'];
                        $data['ProductGroup'] = $row['ProductGroup'];
                        $data['ExternalUserId'] = $row['ExternalUserId'];
                        $data['GamesessionId'] = $row['GamesessionId'];
                        $data['GameId'] = $row['GameId'];
                        $data['Currency'] = $row['Currency'];
                        $data['ExternalTransactionId'] = $row['ExternalTransactionId'];
                        $data['Balance'] = $row['Balance'];
                        $data['NumRounds'] = $row['NumRounds'];
                        $data['TotalLoss'] = $row['TotalLoss'];
                        $data['TotalGain'] = $row['TotalGain'];
                        $data['GamesessionStarted'] = str_replace('T', ' ', $row['GamesessionStarted']);
                        $data['GamesessionFinished'] = str_replace('T', ' ', $row['GamesessionFinished']);
                        $data['ExchangeRate'] = $row['ExchangeRate'];
                        $data['ContextId'] = $row['ContextId'];
                        $data['ClientIP'] = $row['ClientIP'];
                        $data['ExternalFreegameId'] = $row['ExternalFreegameId'];
                        $data['JackpotLoss'] = $row['JackpotLoss'];
                        $data['JackpotGain'] = $row['JackpotGain'];
                        $data['FreegameRounds'] = $row['FreegameRounds'];
                        $data['FreegameBet'] = $row['FreegameBet'];
                        $data['FreegameWin'] = $row['FreegameWin'];
                        $data['Channel'] = $row['Channel'];
                        $data['MessageId'] = $row['MessageId'];
                        $data['MessageTimestamp'] = str_replace('T', ' ', $row['MessageTimestamp']);
                        $record = PgBetRecord::whereRaw("TransactionId = '" . $row['TransactionId'] . "'")
                            ->first();
                        if (!is_object($record)) {
                            PgBetRecord::create($data);
                        }
                    } else */if(is_array($row) && $row['MessageType'] == '4'){
                        $data = array();
                        $data['TransactionId'] = $row['TransactionId'];
                        $data['Status'] = $row['Status'];
                        $data['Amount'] = $row['Amount'];
                        $data['Time'] = str_replace('T', ' ', $row['Time']);
                        $data['ProductGroup'] = $row['ProductGroup'];
                        $data['ExternalUserId'] = $row['ExternalUserId'];
                        $data['GamesessionId'] = $row['GamesessionId'];
                        $data['GamesessionState'] = $row['GamesessionState'];
                        $data['GameId'] = $row['GameId'];
                        $data['RoundId'] = $row['RoundId'];
                        $data['RoundData'] = $row['RoundData'];
                        $data['RoundLoss'] = $row['RoundLoss'];
                        $data['JackpotLoss'] = $row['JackpotLoss'];
                        $data['JackpotGain'] = $row['JackpotGain'];
                        $data['Currency'] = $row['Currency'];
                        $data['ExternalTransactionId'] = $row['ExternalTransactionId'];
                        $data['Balance'] = $row['Balance'];
                        $data['NumRounds'] = $row['NumRounds'];
                        $data['TotalLoss'] = $row['TotalLoss'];
                        $data['TotalGain'] = $row['TotalGain'];
                        $data['ExternalFreegameId'] = $row['ExternalFreegameId'];
                        $data['Channel'] = $row['Channel'];
                        $data['MessageId'] = $row['MessageId'];
                        $data['MessageTimestamp'] = str_replace('T', ' ', $row['MessageTimestamp']);
                        $record = PgRoundBetRecord::whereRaw("TransactionId = '" . $row['TransactionId'] . "'")
                            ->first();
                        if (!is_object($record)) {
                            PgRoundBetRecord::create($data);
                        }
                    }
                }
                return response()->json([
                    'result' => 'success',
                ]);
            } else {
                return response()->json([
                    'result' => 'error',
                ]);
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_pg(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;

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
