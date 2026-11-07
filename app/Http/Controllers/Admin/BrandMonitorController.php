<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandKeyword;
use App\Models\BrandPlatform;
use App\Services\GeoFlow\BrandMonitor\BrandMonitorOrchestrator;
use App\Services\GeoFlow\BrandMonitor\BrandVisibilityService;
use App\Services\GeoFlow\BrandMonitor\KeywordManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 品牌监测控制器
 *
 * 管理 AI 可见性、信源检测、舆情评价三个子模块。
 */
class BrandMonitorController extends Controller
{
    public function __construct(
        private readonly BrandMonitorOrchestrator $orchestrator,
        private readonly KeywordManager $keywordManager,
        private readonly BrandVisibilityService $visibilityService,
    ) {}

    /**
     * 品牌监测页面（3 个标签页）
     */
    public function index(Request $request): View
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $platforms = BrandPlatform::query()
            ->when($siteId !== null, fn ($q) => $q->where(function ($q2) use ($siteId) {
                $q2->whereNull('site_id')->orWhere('site_id', $siteId);
            }))
            ->get();
        $keywords = $this->keywordManager->getKeywords($siteId);

        // 获取已知站点列表（复用诊断 orchestrator 的方法）
        $sites = app(\App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator::class)->getKnownSites();

        return view('admin.geo-diagnosis.brand-monitor', [
            'pageTitle' => '品牌监测',
            'activeMenu' => 'geo-diagnosis',
            'platforms' => $platforms,
            'keywords' => $keywords,
            'sites' => $sites,
            'selectedSiteId' => $siteId,
        ]);
    }

    // ── AI 可见性 ──

    /**
     * 获取 AI 可见性数据
     */
    public function visibility(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $platformIds = $request->input('platform_ids', []);
        $keywordIds = $request->input('keyword_ids', []);

        if (is_string($platformIds)) {
            $platformIds = array_filter(explode(',', $platformIds));
        }
        if (is_string($keywordIds)) {
            $keywordIds = array_filter(explode(',', $keywordIds));
        }

        $data = $this->orchestrator->getVisibilityData(
            !empty($platformIds) ? array_map('intval', $platformIds) : null,
            !empty($keywordIds) ? array_map('intval', $keywordIds) : null,
            $siteId,
        );

        return response()->json(['data' => $data]);
    }

    /**
     * 手动触发一次可见性检测
     */
    public function refreshVisibility(): JsonResponse
    {
        $adapters = $this->orchestrator->getAdapters();
        $result = $this->visibilityService->refreshVisibility($adapters);

        return response()->json([
            'data' => ['results_count' => $result, 'message' => '检测完成'],
        ]);
    }

    // ── 信源检测 ──

    /**
     * 获取信源分析数据
     */
    public function sources(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $period = $request->input('period', '7d');
        $data = $this->orchestrator->getSourceAnalysis($period, $siteId);

        return response()->json(['data' => $data]);
    }

    // ── 舆情评价 ──

    /**
     * 获取舆情分析数据
     */
    public function sentiment(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $period = $request->input('period', '7d');
        $data = $this->orchestrator->getSentimentAnalysis($period, $siteId);

        return response()->json(['data' => $data]);
    }

    // ── 关键词管理 ──

    /**
     * 获取关键词列表
     */
    public function keywords(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $keywords = $this->keywordManager->getKeywords($siteId);

        return response()->json(['data' => $keywords]);
    }

    /**
     * 添加关键词
     */
    public function addKeyword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
        ]);

        $keyword = $this->keywordManager->addKeyword($validated['keyword']);

        return response()->json(['data' => $keyword], 201);
    }

    /**
     * 删除关键词
     */
    public function removeKeyword(int $id): JsonResponse
    {
        $deleted = $this->keywordManager->removeKeyword($id);

        if (!$deleted) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => '关键词不存在']], 404);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── 平台管理 ──

    /**
     * 获取平台列表
     */
    public function platforms(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $platforms = BrandPlatform::query()
            ->when($siteId !== null, fn ($q) => $q->where(function ($q2) use ($siteId) {
                $q2->whereNull('site_id')->orWhere('site_id', $siteId);
            }))
            ->get();

        return response()->json(['data' => $platforms]);
    }

    /**
     * 更新平台配置（启用/禁用、检测频率）
     */
    public function updatePlatform(Request $request, int $id): JsonResponse
    {
        $platform = BrandPlatform::query()->find($id);
        if ($platform === null) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => '平台不存在']], 404);
        }

        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'check_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'api_config' => ['sometimes', 'array'],
        ]);

        $platform->update($validated);

        return response()->json(['data' => $platform->refresh()]);
    }
}
