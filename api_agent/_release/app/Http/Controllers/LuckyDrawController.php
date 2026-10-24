<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use Hash;
use Validator;
use App\Services\LuckyDrawService;

class LuckyDrawController extends Controller
{
    /**
     * ReportController constructor.
     */
    public function __construct()
    {
        $this->service = new LuckyDrawService();
    }

    public function dropdownList(Request $request)
    {
        try {
            $data = $this->service->dropdownList();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}