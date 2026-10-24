<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\SmsGatewayEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Resources\Frontend\CountryListResource;
use App\Models\Agent;
use App\Models\Country;
use App\Models\SmsGatewayInfoCountry;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Redis;

#[Group('Country (國家)')]
class CountryController extends Controller
{
    /**
     * 國家列表
     *
     * OLD: /api/country/list
     */
    public function list(DomainRequest $request)
    {
        $userData = $this->getUserContext();

        $languageId = $userData->languageId;
        $domain = $request->domain;
        // v2：新增 has_whatsapp/has_sms，改變了快取內容 shape，版本號避免吐出部署前缺欄位的舊快取
        $key = implode(':', ['country', 'list', 'v2', 'domain', $domain, $languageId]);

        if (Redis::exists($key)) {
            $data = Redis::get($key);

            return ApiResponse::success(CountryListResource::collection($data));
        }

        $smsGatewayGroupIds = Agent::query()
            ->whereHas('agentDomains', function ($query) use ($domain) {
                $query->where('status', 1)
                    ->where('domain', $domain);
            })
            ->pluck('sms_gateway_group_id')
            ->toArray();

        $smsGatewayInfoCountries = SmsGatewayInfoCountry::query()
            ->where('status', 1)
            ->whereHas('smsGatewayInfo', function ($query) use ($smsGatewayGroupIds) {
                $query->where('status', 1)
                    ->whereIn('sms_gateway_group_id', $smsGatewayGroupIds);
            })
            ->with('smsGatewayInfo.smsGateway')
            ->get();

        // 依國家彙整該國有沒有一般簡訊／WhatsApp 管道可用（判準見 SmsGatewayEnum::isWhatsAppChannel()）
        $channelsByCountryId = [];
        foreach ($smsGatewayInfoCountries as $smsGatewayInfoCountry) {
            $code = $smsGatewayInfoCountry->smsGatewayInfo?->smsGateway?->code;
            $isWhatsApp = ($code !== null ? SmsGatewayEnum::tryFrom($code) : null)?->isWhatsAppChannel() ?? false;

            $countryId = $smsGatewayInfoCountry->country_id;
            $channelsByCountryId[$countryId]['has_whatsapp'] = ($channelsByCountryId[$countryId]['has_whatsapp'] ?? false) || $isWhatsApp;
            $channelsByCountryId[$countryId]['has_sms'] = ($channelsByCountryId[$countryId]['has_sms'] ?? false) || !$isWhatsApp;
        }

        $data = Country::with([
            'countryNames' => function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
            }])
            ->where('status', 1)
            ->whereIn('id', array_keys($channelsByCountryId))
            ->select('id', 'code', 'name', 'country_code', 'phone_rule')
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($data as $country) {
            $country->has_whatsapp = $channelsByCountryId[$country->id]['has_whatsapp'] ?? false;
            $country->has_sms = $channelsByCountryId[$country->id]['has_sms'] ?? false;
        }

        Redis::set($key, $data);

        return ApiResponse::success(CountryListResource::collection($data));
    }
}
