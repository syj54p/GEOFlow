<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * GEO 诊断概览仪表盘控制器
 */
class GeoDiagnosisDashboardController extends Controller
{
    public function __construct(
        private readonly GeoDiagnosisOrchestrator $orchestrator,
    ) {}

    /**
     * 诊断概览页面
     */
    public function index(): View
    {
        $dashboard = $this->orchestrator->getDashboardData();

        return view('admin.geo-diagnosis.dashboard', [
            'pageTitle' => '诊断概览',
            'activeMenu' => 'geo-diagnosis',
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * 诊断概览 API（JSON）
     */
    public function dashboard(): JsonResponse
    {
        $data = $this->orchestrator->getDashboardData();

        return response()->json(['data' => $data]);
    }
}
