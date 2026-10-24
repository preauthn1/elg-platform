<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Log;

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
        $message = '['.$exception->getCode().'] "'.$exception->getMessage().'"';
        if (isset($stacks[0]['line'])) {
            $message .= ' on line '. $stacks[0]['line'];
        }
        if (isset($stacks[0]['file'])) {
            $message .= ' of file '. $stacks[0]['file'];
        }
        Log::error($message, $stacks);
        //parent::report($exception);
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
