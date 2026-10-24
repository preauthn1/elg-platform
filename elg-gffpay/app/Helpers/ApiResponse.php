<?php

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

    public static function successWithPagination(array|object $data, OnlyPaginateResource $paginator, string $message = ''): JsonResponse
    {
        if (empty($message)) {
            $message = __('error_message.1000');
        }

        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => [
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
}
