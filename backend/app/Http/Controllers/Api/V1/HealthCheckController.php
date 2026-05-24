<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->success([
            'service' => config('app.name'),
            'status' => 'healthy',
        ], 'API is healthy.');
    }
}
