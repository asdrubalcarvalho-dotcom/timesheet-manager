<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AiMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMetricsController extends Controller
{
    public function __construct(private readonly AiMetricsService $metricsService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $daysParam = $request->query('days');
        $days = is_numeric($daysParam) ? (int) $daysParam : null;

        return response()->json(
            $this->metricsService->getMetrics($days)
        );
    }
}
