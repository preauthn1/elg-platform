<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Log;
use App\Services\TelegramService;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    protected $tgService;
    public function __construct()
    {
        $this->tgService = new TelegramService();
    }

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception $exception
     * @return void
     */
    public function report(Exception $exception)
    {
        $stacks = collect($exception->getTrace())->map(function ($value, $key) {
            unset($value['args']);
            unset($value['type']);
            return $value;
        })->all();
        $message = "[" . $exception->getCode() . "] \"".$exception->getMessage() . "\"" . PHP_EOL;
        if ($exception->getLine()) {
            $message .= "on line ". $exception->getLine() . PHP_EOL;
        }
        if ($exception->getFile()) {
            $message .= "of file ". $exception->getFile() . PHP_EOL;
        }
        Log::error($message, $stacks);

        $this->tgService->sendMessage($message);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Exception $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $exception)
    {
        return parent::render($request, $exception);
    }
}
