<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Enums\General\ContextKeyEnum;
use App\Http\Resources\OnlyPaginateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Context;

class ApiResponse
{
    public static function success(array|object|null $data = [], string $message = ''): JsonResponse
    {
        if (empty($message)) {
            $message = __('error_message.1000');
        }

        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        // 這裡先寫死200, 別用HttpCode::HTTP_OK->value, dedoc/scramble 會讀不到 response 格式
        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => $data,
            'tid' => $requestId,
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $extra  items／pagination 之外要一起回在 data 底下的鍵（例如 summary 統計卡），
     *                                       不可覆蓋 items／pagination
     */
    public static function successWithPagination(array|object $data, OnlyPaginateResource $paginator, string $message = '', array $extra = []): JsonResponse
    {
        if (empty($message)) {
            $message = __('error_message.1000');
        }

        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => $extra + [
                'items' => $data,
                'pagination' => $paginator,
            ],
            'tid' => $requestId,
        ], 200);
    }

    public static function error(string $message = '', ?int $code = null, array|JsonResource $data = []): JsonResponse
    {
        if (!$code) {
            $code = 400;
        }

        if (empty($message)) {
            $message = __('error_message.unknown exception');
        }

        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'tid' => $requestId,
        ], $code);
    }

    public static function customCode($code, string $message = '', array|object|null $data = null): JsonResponse
    {
        if (empty($message)) {
            $message = __('error_message.1000');
        }

        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        $body = [
            'code' => $code,
            'message' => $message,
            'tid' => $requestId,
        ];

        if ($data !== null) {
            $body['data'] = $data;
        }

        // 這裡先寫死200, 別用HttpCode::HTTP_OK->value, dedoc/scramble 會讀不到 response 格式
        return response()->json($body, 200);
    }

    /**
     * Livechat Bot 專用回應格式：{status, code, msg, output, error}
     *
     * @param  array<array-key, mixed>  $output  可為關聯陣列（單筆物件）或數值索引陣列（清單）
     * @param  array<array-key, mixed>  $error
     */
    public static function livechatBotOutput(array $output, string $msg = 'Success', array $error = []): JsonResponse
    {
        return response()->json([
            'status' => true,
            'code' => 200,
            'msg' => $msg,
            'output' => $output,
            'error' => $error,
        ]);
    }
}
