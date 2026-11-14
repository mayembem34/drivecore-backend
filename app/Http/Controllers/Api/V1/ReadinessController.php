<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $ready = Artisan::call('drivecore:check') === 0;

        return response()->json(['data' => ['status' => $ready ? 'ready' : 'unavailable']], $ready ? 200 : 503);
    }
}
