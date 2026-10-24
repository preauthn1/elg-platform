<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Data\SmsGatewayInfoData;
use App\Exceptions\ApiException;
use App\Facades\AES;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\SmsGatewayInfo;
use App\Services\BloomFilter\MemberFirstNameFilter;
use App\Services\BloomFilter\MemberMobileFilter;
use App\Services\BloomFilter\MemberRegisterIpFilter;
use App\Services\DepositService;
use App\Services\Encryption\AesService;
use App\Services\MemberTransferService;
use App\Services\OrderService;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\PaymentService;
use App\Services\SmsGatewayService;
use App\Services\VendorService;
use App\Services\WalletService;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

#[Group('Debug (測試用、小工具)')]
class DebugController extends Controller
{
    public function __construct(
        protected VendorService $vendorService,
        protected MemberTransferService $memberTransferService,
        protected WalletService $walletService,
        protected OrderService $orderService,
        protected DepositService $depositService,
        protected PaymentService $paymentService,
        protected MemberRegisterIpFilter $memberRegisterIpFilter,
        protected MemberMobileFilter $memberMobileFilter,
        protected MemberFirstNameFilter $memberFirstNameFilter
    ) {}

    public function testQueue(Request $request)
    {
        $message = $request->input('message') ?? 'Test queue job';
        $job = new \App\Jobs\TestJob($message);
        dispatch($job);

        return ApiResponse::success([
            'message' => 'TestJob dispatched',
            'job' => $job,
        ]);
    }

    /**
     * 測試方法
     */
    public function try(Request $request)
    {
        // $now = now();
        // $memberId = $request->get('member_id') ?? 0;
        // $member = Member::find($memberId);
        // $agent = $member->agent;
        // $agentIds = $agent->hierarchy_array;
        //
        // // do something
        // $parents = [];
        // $types = OrderType::where('parent', '!=', 0)->get(['id', 'parent']);
        // $types->groupBy('parent')->map(function ($items) use (&$parents) {
        //     $parent = OrderType::find($items->first()->parent);
        //     $parents[] = $parent->id;
        //     OrderType::where('parent', $parent->id)->update(['type' => $parent->type]);
        //     // $parent->children = $item;
        //     // $parent->save();
        // });

        $list = Order::whereIn('id', [8330, 8322])->get();
        foreach ($list as $order) {
            dump($order->owner->id);
            dump($order->owner instanceof Member);
            dump($order->owner instanceof Agent);
        }

        return ApiResponse::success();
    }

    public function tryDeposit(Request $request)
    {
        $domain = $request->get('domain') ?? 'example.invalid';

        $memberId = $request->get('member_id') ?? 0;
        $member = Member::findOrFail($memberId);
        $agent = $member->agent;

        $orderTypeId = $request->get('order_type_id') ?? 9;
        $orderType = $this->depositService->getOrderType($agent, $orderTypeId);
        $agentCashFlow = $orderType->agentCashFlow;

        $amount = $request->get('amount') ?? 100;
        $amount = number_format($amount, 2, '.', '');
        $order = $this->orderService->createOrder($orderType, $agentCashFlow, $member, $amount);

        $provider = $this->paymentService->getProvider($agentCashFlow);
        $params = DepositRequestData::from([
            'member' => $member,
            'order' => $order,
            'callbackUrl' => Common::depositCallbackUrl($agentCashFlow->id),
            'returnUrl' => Common::depositReturnUrl($domain),
            'ip' => Common::getIp(),
        ]);
        $result = $provider->deposit($params);

        return ApiResponse::success($result->toArray());
    }

    /**
     * @throws Exception
     */
    public function wallets(Request $request)
    {
        $memberId = (int) ($request->get('member_id') ?? 0);
        $vendorId = (int) ($request->get('vendor_id') ?? 0);

        try {
            $member = Member::findOrFail($memberId);
        } catch (ModelNotFoundException $e) {
            throw new ApiException('Member not found', 404, $e);
        }

        $memberWallets = $this->walletService->getMemberWallets($member);

        $vendorBalance = '0.0000';
        if ($vendorId) {
            $vendorDriver = $this->vendorService->getDriver($vendorId, $member);
            $vendorBalance = $vendorDriver->getBalance();
        }

        $data = [
            'balance' => $memberWallets->wallet->balance,
            'rolling_chip' => $memberWallets->rollingChipWallet->balance ?? '0',
            'vendor_balance' => $vendorBalance,
        ];

        return ApiResponse::success($data);
    }

    public function transfer(Request $request)
    {
        $memberId = (int) ($request->get('member_id') ?? 0);
        $vendorId = (int) ($request->get('vendor_id') ?? 0);
        $amount = $request->get('amount') ?? 0;

        try {
            $member = Member::findOrFail($memberId);
        } catch (ModelNotFoundException $e) {
            throw new ApiException('Member not found', 404, $e);
        }

        $vendorDriver = $this->vendorService->getDriver($vendorId, $member);

        if ($vendorDriver->isSingleWallet()) {
            throw new ApiException('Transfer not supported for single wallet vendor', 400);
        }

        if ($amount >= 0) {
            $result = $this->memberTransferService->transferToVendor($vendorDriver, $amount);
        } else {
            $result = $this->memberTransferService->transferToLocal($vendorDriver, (string) abs($amount));
        }

        return ApiResponse::success($result);
    }

    /**
     * 取得 IP
     */
    public function getIP(Request $request)
    {
        return ApiResponse::success([
            'Common::getIp()' => Common::getIp(),
            '$request->ip()' => $request->ip(),
        ]);
    }

    /**
     * 解密前端請求
     *
     * 模擬中間件 DecryptRequest 將傳入的 params & key 解密
     */
    public function decryptRequest(Request $request)
    {
        $params = str_replace(' ', '+', $request->input('params'));
        $params = str_replace('\\', '', $params);

        $decryptResult = AES::decrypt($params);
        $decryptResult = json_decode($decryptResult, true);

        return response()->json($decryptResult ?? []);
    }

    /**
     * 模擬前端加密請求
     *
     * 將參數轉換為 params & key
     */
    public function encryptRequest(Request $request)
    {
        $json_content = ($request->all() == null)
            ? $request->getContent()
            : json_encode($request->all());

        $encryptParams = AES::encrypt($json_content);
        $key = md5($encryptParams . config('encrypt.aes.md5'));

        return response()->json([
            'key' => $key,
            'params' => $encryptParams,
        ]);
    }

    /**
     * 清除 Redis
     *
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    public function redisClear(Request $request)
    {
        // TODO: 應限制服務器 IP
        // 只允許 192.168 開頭的 IP 或辦公室 IP 執行
        // $ip = Common::getIp();
        // if (!Str::startsWith($ip, '192.168.') && $ip != config('custom.office.ip')) {
        //     return ApiResponse::error('Not allowed in production');
        // }

        try {
            Redis::connection('default')->flushdb();
            Redis::connection('json')->flushdb();
            Redis::connection('cache')->flushdb();

            $connection = $request->get('connection');
            if ($connection !== null) {
                Redis::connection($connection)->flushdb();
            }
        } catch (Exception $e) {
            return ApiResponse::error('Redis 清除失敗: ' . $e->getMessage());
        }

        return ApiResponse::success();
    }

    /**
     * 測試發送簡訊
     */
    public function trySendSms(Request $request)
    {
        $memberId = $request->get('member_id') ?? 0;
        $member = Member::findOrFail($memberId);
        $mobile = $request->get('mobile') ?? '0912345678';
        $content = $request->get('content') ?? '測試簡訊內容';
        $smsGatewayId = $request->get('sms_gateway_id') ?? 0;

        $smsGatewayInfo = SmsGatewayInfo::with(['smsGateway', 'countries'])
            ->where('sms_gateway_infos.status', 1)
            ->where('sms_gateway_infos.sms_gateway_group_id', $member->agent->sms_gateway_group_id)
            ->where('sms_gateway_infos.sms_gateway_id', $smsGatewayId)
            ->whereHas('smsGateway', fn ($query) => $query->where('sms_gateways.status', 1))
            ->whereHas('countries', fn ($query) => $query->where('countries.status', 1)
                ->whereRaw('? like concat(countries.country_code, ?)', [$mobile, '%']))
            // ->orderBy('sms_gateway_infos.sort')
            ->firstOrFail();

        $smsGatewayInfoData = SmsGatewayInfoData::from([
            'smsGatewayInfo' => $smsGatewayInfo,
            'countryId' => $smsGatewayInfo->countries->first()->id,
            'countryCode' => $smsGatewayInfo->countries->first()->country_code,
        ]);

        $smsApiService = new SmsGatewayService;

        $result = $smsApiService->sendSms(
            $smsGatewayInfoData,
            $member,
            $mobile,
            $content,
            2 // 1:agent_id, 2:member_id
        );

        return ApiResponse::success(['result' => $result]);
    }

    /**
     * 模擬前端加密請求
     *
     * 將參數轉換為 params & key
     */
    public function encryptCsData(Request $request)
    {
        // get post body string
        $str = $request->getContent();

        $aesService = new AesService(
            cipher: config('encrypt.third_party.cipher'),
            key: config('encrypt.third_party.key'),
            iv: config('encrypt.third_party.iv')
        );

        $str = base64_decode($str);
        $decrypt = $aesService->decrypt($str);
        $decrypt = json_decode($decrypt);

        return response()->json([
            'decrypt' => $decrypt,
        ]);
    }

    public function checkBloomFilter(Request $request)
    {
        $ip = $request->get('ip');
        $mobile = $request->get('mobile');
        $firstName = $request->get('first_name');

        $existIp = [];
        if ($ip) {
            foreach ($ip as $value) {
                $bucket = $this->memberRegisterIpFilter->bucket;
                $exist = $this->memberRegisterIpFilter->exists($bucket, $value);
                if ($exist) {
                    $existIp[] = $value;
                }
            }
        }

        $existMobile = [];
        if ($mobile) {
            foreach ($mobile as $value) {
                $bucket = $this->memberMobileFilter->bucket;
                $exist = $this->memberMobileFilter->exists($bucket, $value);
                if ($exist) {
                    $existMobile[] = $value;
                }
            }
        }

        $existFirstName = [];
        if ($firstName) {
            foreach ($firstName as $value) {
                $bucket = $this->memberFirstNameFilter->bucket;
                $exist = $this->memberFirstNameFilter->exists($bucket, $value);
                if ($exist) {
                    $existFirstName[] = $value;
                }
            }
        }

        return response()->json([
            'exist_ip' => $existIp,
            'exist_mobile' => $existMobile,
            'exist_first_name' => $existFirstName,
        ]);
    }
}
