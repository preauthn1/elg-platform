<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    //

    public $rules_cs = [
        'start_time' => 'required|date_format:Y-m-d H:i:s',
        'end_time' => 'required|date_format:Y-m-d H:i:s',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];
    /**
     * @OA\Get(
     *     path="/red_envelopes",
     *     tags={"red_envelope", "cs"},
     *     summary="取紅包列表",
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="captchaId", type="string"),
     *             @OA\Property(property="img", type="string")
     *         )
     *     )
     * )
     */
    public function sr07(Request $request) {
        $this->validate($request, $this->rules_cs);
        try {
            $service = new ReportService();
            $result = $service->sr07(
                $request->HOST,
                isset($request->username)?$request->username:'',
                $request->start_time,
                $request->end_time,
                $request->page,
                $request->per_page);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }
}
