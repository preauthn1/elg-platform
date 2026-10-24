<?php

declare(strict_types=1);

namespace App\Http\Middleware\External\SingleWallets;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Vendors\MegawinSW\Datas\MegaWinSWVendorInfoDetailData;
use App\Vendors\MegawinSW\Enums\ErrorCodeEnum;
use App\Vendors\MegawinSW\Externals\CipherService;
use Closure;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MegaWinRequestDecrypt
{
    /**
     * @var array
     */
    private $requestInfos;

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $this->requestInfos = [
            'headers' => Common::flattenHeaders($request->headers->all()),
            'params' => $request->all(),
        ];

        $mwCode = $request->header('mwCode');

        if (!$mwCode) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Missing mwCode in header');
        }

        $vendorInfo = Cache::remember(
            key: CacheKeys::externalMegawinswVendorInfo($mwCode),
            ttl: CacheTtl::ONE_HOUR->value,
            callback: fn () => VendorInfo::where('vendor_id', VendorIdEnum::MEGAWINSW->value)
                ->whereRaw("JSON_EXTRACT(info, '$.MW_CODE') = ?", [$mwCode])
                ->first()
        );
        if (!$vendorInfo) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Invalid mwCode');
        }

        app()->singleton(MegaWinSWVendorInfoDetailData::class, function () use ($vendorInfo) {
            return MegaWinSWVendorInfoDetailData::from($vendorInfo->info);
        });
        app()->singleton(CipherService::class, function () {
            return new CipherService(app(MegaWinSWVendorInfoDetailData::class));
        });

        $encryptedKey = $request->input('key') ?? '';
        $encryptedData = $request->input('data') ?? '';
        if (!$encryptedKey) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Missing key parameter');
        } elseif (!$encryptedData) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Missing data parameter');
        }

        $decryptedData = [];
        try {
            $decryptedData = app(CipherService::class)->decrypt($encryptedKey, $encryptedData);
        } catch (Exception $e) {
            return $this->errorLogAndResponse(ErrorCodeEnum::SYSTEM_BUSY, 'Decryption failed: ' . $e->getMessage());
        }

        $checkResult = $this->checkIds($decryptedData);
        if (!is_null($checkResult)) {
            return $checkResult;
        }

        $request->merge($decryptedData);

        return $next($request);
    }

    private function checkIds(array $decryptedData): ?JsonResponse
    {
        $vendorInfoDetail = app(MegaWinSWVendorInfoDetailData::class);

        if (empty($decryptedData['siteId'])) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Missing site ID');
        } elseif (empty($decryptedData['firmId'])) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Missing firm ID');
        } elseif (empty($decryptedData['merchantId'])) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Missing merchant ID');
        }

        $siteId = (string) $decryptedData['siteId'];
        $merchantId = (string) $decryptedData['merchantId'];
        $firmId = (string) $decryptedData['firmId'];
        if ($vendorInfoDetail->SITE_ID !== $siteId) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Invalid site ID');
        } elseif ($vendorInfoDetail->MERCHANT_ID !== $merchantId) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Invalid merchant ID');
        } elseif ($vendorInfoDetail->FIRM_ID !== $firmId) {
            return $this->errorLogAndResponse(ErrorCodeEnum::PARAM_ERROR, 'Invalid firm ID');
        }

        return null;
    }

    private function errorLogAndResponse(int $errorCode, string $errorMessage): JsonResponse
    {
        $responseData = [
            'ret' => $errorCode,
            'msg' => $errorMessage,
        ];

        $encryptedResponseData = app(CipherService::class)->encrypt($responseData);

        $logData = [
            'requestData' => $this->requestInfos,
            'responseData' => $responseData,
            'encryptedResponseData' => $encryptedResponseData,
        ];

        Log::error("MegaWin_SW_error_$errorCode", $logData);

        return response()->json($responseData);
    }
}
