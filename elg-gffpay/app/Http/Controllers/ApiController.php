<?php

namespace App\Http\Controllers;

use App\Enums\Db\ErrorCodeEnum;
use App\Enums\Db\OrderLogTypeEnum;
use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Exceptions\TonException;
use App\Http\Requests\Api\CreateDepositOrderRequest;
use App\Http\Requests\Api\CreateWithdrawOrderRequest;
use App\Http\Requests\Api\QueryDepositOrderRequest;
use App\Http\Requests\Api\QueryWithdrawOrderRequest;
use App\Http\Resources\Ton\Api\CreateDepositOrderResource;
use App\Http\Resources\Ton\Api\CreateWithdrawOrderResource;
use App\Http\Resources\Ton\Api\QueryDepositOrderResource;
use App\Http\Resources\Ton\Api\QueryWithdrawOrderResource;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\TonService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Group('TON 加密貨幣')]
class ApiController extends Controller
{
    protected string $actionPrefix = 'Ton_';

    protected ?Merchant $merchant = null;

    protected ?Currency $currency = null;

    public function __construct(
        protected TonService $tonService,
    ) {}

    /**
     * 攔截請求並統一錯誤處理
     */
    public function callAction($method, $parameters)
    {
        try {
            // 前置處理：例如紀錄 log、統一驗證、設定參數
            // 呼叫真正的 controller 方法
            $response = parent::callAction($method, $parameters);
            // 後置處理

            return $response;
        } catch (TonException $e) {
            return $this->errorResponse($e->getTonErrorCode());
        } catch (ValidationException $e) {
            return $this->errorResponse(ErrorCodeEnum::PARAMETER_FORMAT_ERROR, $e->getMessage());
        } catch (QueryException $e) {
            $action = $this->actionPrefix . 'error';
            $errorCode = $e->errorInfo[1];
            if ($errorCode === 1062) { // Duplicate entry, ignore it.
                return $this->errorResponse(ErrorCodeEnum::ORDER_NUMBER_REPEAT);
            }
            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR);
        } catch (Throwable $e) {
            $action = $this->actionPrefix . 'error';
            Log::error($action, [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->errorResponse(ErrorCodeEnum::SYSTEM_ERROR);
        }
    }

    /**
     * 在驗證參數後執行
     *
     *
     * @throws TonException
     */
    private function afterValidate(array $params): void
    {
        // 驗證商家
        $merchant_code = $params['merchant_code'];
        $currency_code = $params['currency_code'];

        // 驗證幣別, currency_code 轉換為 currency_id
        $this->currency = $this->tonService->verifyCurrency($currency_code);
        $currency_id = $this->currency->id;

        $this->merchant = Merchant::query()
            ->with(['balances' => function ($query) use ($currency_id) {
                $query->where('currency_id', $currency_id)
                    ->where('status', 1);
            }])
            ->where('code', $merchant_code)
            ->first();

        // 驗證商家是否存在
        if (!$this->merchant) {
            throw new TonException(ErrorCodeEnum::MERCHANT_NOT_FOUND);
        }

        // 驗證商戶支援的幣別
        if ($this->merchant->balances->isEmpty()) {
            throw new TonException(ErrorCodeEnum::CURRENCY_NOT_SUPPORTED);
        }

        // 驗簽
        $secretKey = $this->merchant->secret_key;
        $this->tonService->verifySign($params, $secretKey);
    }

    /**
     * 建立代收訂單
     */
    public function createDepositOrder(Request $request)
    {
        $input = $request->validate((new CreateDepositOrderRequest)->rules());
        $this->afterValidate($input);

        $merchant_order_no = $input['merchant_order_no'];
        $amount = (string) $input['amount'];
        $callback_url = $input['callback_url'];
        $return_url = $input['return_url'] ?? '';
        $currency_id = $this->currency->id;

        // 檢查訂單號
        $this->tonService->checkOrderNo($merchant_order_no, $this->merchant->id);

        $order = DB::transaction(function () use ($merchant_order_no, $amount, $callback_url, $return_url, $request, $currency_id) {
            $wallet = $this->tonService->pickTonWallet();
            $orderNo = $this->tonService->genOrderNo(OrderTypeEnum::DEPOSIT);
            $order = Order::create([
                'type' => OrderTypeEnum::DEPOSIT->value,
                'status' => OrderStatusEnum::PENDING->value,
                'wallet_id' => $wallet->id,
                'to_address' => $wallet->address,
                'order_no' => $orderNo,
                'merchant_order_no' => $merchant_order_no,
                'amount' => $amount,
                'callback_url' => $callback_url,
                'return_url' => $return_url,
                'merchant_id' => $this->merchant->id, // 使用從 callAction 獲取的 merchant
                'currency_id' => $currency_id,
            ]);

            // 在這邊建立 response 是為了 log 使用
            $resource = CreateDepositOrderResource::make($order);

            OrderLog::create([
                'order_id' => $order->id,
                'type' => OrderLogTypeEnum::CREATE->value,
                'request_url' => $request->fullUrl(),
                'request_data' => json_encode($request->all()),
                'response_data' => json_encode($resource),
                'http_code' => 200,
            ]);

            return $order;
        });

        $resource = CreateDepositOrderResource::make($order);

        return $this->response($resource);
    }

    /**
     * 建立代付訂單
     */
    public function createWithdrawOrder(Request $request)
    {
        $input = $request->validate((new CreateWithdrawOrderRequest)->rules());
        $this->afterValidate($input);

        $merchant_order_no = $input['merchant_order_no'];
        $address = $input['address'];
        $amount = (string) $input['amount'];
        $callback_url = $input['callback_url'];
        $currency_id = $this->currency->id;

        // 檢查訂單號
        $this->tonService->checkOrderNo($merchant_order_no, $this->merchant->id);

        // 檢查錢包地址
        $this->tonService->checkAddress($address);

        $order = DB::transaction(function () use ($request, $merchant_order_no, $amount, $callback_url, $address, $currency_id) {
            $wallet = $this->tonService->getMainWallet();

            // 初步檢查餘額是否足夠,  之後在排程實際呼叫 ton api 前會再檢查一次
            if (bccomp($wallet->balance, $amount, 9) < 0) {
                throw new TonException(ErrorCodeEnum::BALANCE_NOT_ENOUGH);
            }

            $orderNo = $this->tonService->genOrderNo(OrderTypeEnum::WITHDRAW);
            $order = Order::create([
                'type' => OrderTypeEnum::WITHDRAW->value,
                'status' => OrderStatusEnum::PENDING->value,
                'wallet_id' => $wallet->id,
                'from_address' => $wallet->address,
                'to_address' => $address,
                'order_no' => $orderNo,
                'merchant_order_no' => $merchant_order_no,
                'amount' => $amount,
                'callback_url' => $callback_url,
                'merchant_id' => $this->merchant->id, // 使用從 callAction 獲取的 merchant
                'currency_id' => $currency_id,
            ]);

            // 在這邊建立 response 是為了 log 使用
            $resource = CreateWithdrawOrderResource::make($order);

            OrderLog::create([
                'order_id' => $order->id,
                'type' => OrderLogTypeEnum::CREATE->value,
                'request_url' => $request->fullUrl(),
                'request_data' => json_encode($request->all()),
                'response_data' => json_encode($resource),
                'http_code' => 200,
            ]);

            return $order;
        });
        $resource = CreateWithdrawOrderResource::make($order);

        return $this->response($resource);
    }

    /**
     * 查詢代收訂單
     */
    public function queryDepositOrder(Request $request)
    {
        $input = $request->validate((new QueryDepositOrderRequest)->rules());
        $this->afterValidate($input);

        $merchant_order_no = $input['merchant_order_no'];
        $currency_id = $this->currency->id;

        $order = Order::query()
            ->where('merchant_order_no', $merchant_order_no)
            ->where('type', OrderTypeEnum::DEPOSIT->value)
            ->where('merchant_id', $this->merchant->id) // 確保只能查詢自己的訂單
            ->where('currency_id', $currency_id)
            ->first();

        if (!$order) {
            return $this->errorResponse(ErrorCodeEnum::ORDER_NOT_FOUND);
        }

        $resource = QueryDepositOrderResource::make($order);

        return $this->response($resource);
    }

    /**
     * 查詢代付訂單
     */
    public function queryWithdrawOrder(Request $request)
    {
        $input = $request->validate((new QueryWithdrawOrderRequest)->rules());
        $this->afterValidate($input);

        $merchant_order_no = $input['merchant_order_no'];
        $currency_id = $this->currency->id;

        $order = Order::query()
            ->where('merchant_order_no', $merchant_order_no)
            ->where('type', OrderTypeEnum::WITHDRAW->value)
            ->where('merchant_id', $this->merchant->id) // 確保只能查詢自己的訂單
            ->where('currency_id', $currency_id)
            ->first();

        if (!$order) {
            return $this->errorResponse(ErrorCodeEnum::ORDER_NOT_FOUND);
        }

        $resource = QueryWithdrawOrderResource::make($order);

        return $this->response($resource);
    }

    private function response(array|object|null $data)
    {
        return response()->json([
            'code' => ErrorCodeEnum::SUCCESS->value,
            'message' => ErrorCodeEnum::SUCCESS->name,
            'data' => $data,
        ]);
    }

    private function errorResponse(ErrorCodeEnum $code, ?string $message = null)
    {
        $message = $message ?: $code->name;

        return response()->json([
            'code' => $code->value,
            'message' => $message,
        ]);
    }
}
