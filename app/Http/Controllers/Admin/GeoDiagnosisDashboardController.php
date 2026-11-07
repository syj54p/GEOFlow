<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): View
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $dashboard = $this->orchestrator->getDashboardData($siteId);
        $sites = $this->orchestrator->getKnownSites();

        return view('admin.geo-diagnosis.dashboard', [
            'pageTitle' => '诊断概览',
            'activeMenu' => 'geo-diagnosis',
            'dashboard' => $dashboard,
            'sites' => $sites,
            'selectedSiteId' => $siteId,
        ]);
    }

    /**
     * 诊断概览 API（JSON）
     */
    public function dashboard(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $data = $this->orchestrator->getDashboardData($siteId);

        return response()->json(['data' => $data]);
    }

    /**
     * 已知站点列表 API（JSON）
     */
    public function sites(): JsonResponse
    {
        $sites = $this->orchestrator->getKnownSites();

        return response()->json(['data' => $sites]);
    }
}
