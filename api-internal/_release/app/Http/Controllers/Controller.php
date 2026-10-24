<?php

namespace App\Http\Controllers;


use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function validate(Request $request, array $rules,
                             array $messages = [], array $customAttributes = []) {
        $validator = $this->getValidationFactory()->make(
            $request->all(), $rules, $messages, $customAttributes
        );
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json($validator->errors(), 422));
        }

    }
}
