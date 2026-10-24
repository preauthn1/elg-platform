<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\General\ContextKeyEnum;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

class ApiException extends Exception
{
    public function toArray(): array
    {
        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        return [
            'code' => $this->getCode() ?: 400,
            'message' => $this->getMessage() ?: __('error_message.unknown exception'),
            'file' => $this->getRelativeFilePath() . ':' . $this->getLine(),
            'tid' => $requestId,
        ];
    }

    /**
     * 取得相對於專案根目錄的文件路徑
     */
    private function getRelativeFilePath(): string
    {
        $file = $this->getFile();
        $basePath = base_path();

        if (str_starts_with($file, $basePath)) {
            return substr($file, strlen($basePath) + 1);
        }

        return $file;
    }

    /**
     * 覆寫 render 方法
     */
    public function render(): JsonResponse
    {
        return response()->json($this->toArray(), $this->resolveHttpStatus());
    }

    /**
     * 將 exception code 收斂到合法的 HTTP status code 範圍（100-599），
     * 否則 Symfony Response 會拋 InvalidArgumentException。
     */
    private function resolveHttpStatus(): int
    {
        $code = $this->getCode();

        return ($code >= 100 && $code < 600) ? $code : 400;
    }
}
