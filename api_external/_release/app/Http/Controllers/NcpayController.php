<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Common;

class NcpayController extends Controller
{

    public function callback(Request $request)
    {
        Common::log($request->headers, 'ncpay_header');
        Common::log($request->all(), 'ncpay_body');
    }
}