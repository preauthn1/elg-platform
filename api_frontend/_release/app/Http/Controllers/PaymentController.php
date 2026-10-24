<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\PaymentNcpay;

/**
 * @group Payment
 */
class PaymentController extends Controller
{

    public function test(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->test(),
            ]);
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
    
    public function transfer(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->transfer(),
            ]);
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

    public function transferCheck(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->transferCheck(),
            ]);
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
    
    public function transferList(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->transferList(),
            ]);
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
    
    public function deposit(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->deposit(),
            ]);
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
    
    public function depositCheck(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->depositCheck(),
            ]);
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
    
    public function depositList(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->depositList(),
            ]);
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

    public function bankList(Request $request)
    {
        try {
            $payment = new PaymentNcpay();
            return response()->json([
                'code' =>  $payment->bankList(),
            ]);
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
