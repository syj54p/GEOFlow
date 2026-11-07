<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Models\BrandKeyword;
use App\Models\BrandPlatform;
use App\Services\GeoFlow\BrandMonitor\BrandMonitorOrchestrator;
use App\Services\GeoFlow\BrandMonitor\BrandVisibilityService;
use App\Services\GeoFlow\BrandMonitor\KeywordManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 品牌监测端点（租户隔离）。
 *
 * 必须经过 api.tenant 中间件，tenantId() 恒非 null。
 * scope: brand-monitor:read / brand-monitor:write。
 */
class BrandMonitorApiController extends BaseApiController
{
    use ResolvesTenant;

    public function __construct(
        private readonly BrandMonitorOrchestrator $orchestrator,
        private readonly KeywordManager $keywordManager,
    ) {}

    // ── AI 可见性 ──

    /**
     * 获取 AI 可见性数据（GET /api/v1/brand-monitor/visibility）
     */
    public function visibility(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $platformIds = $request->input('platform_ids', []);
        $keywordIds = $request->input('keyword_ids', []);

        if (is_string($platformIds)) {
            $platformIds = array_filter(explode(',', $platformIds));
        }
        if (is_string($keywordIds)) {
            $keywordIds = array_filter(explode(',', $keywordIds));
        }

        $data = $this->orchestrator->getVisibilityData(
            ! empty($platformIds) ? array_map('intval', $platformIds) : null,
            ! empty($keywordIds) ? array_map('intval', $keywordIds) : null,
            $siteId,
        );

        return $this->success($request, $data);
    }

    /**
     * 手动触发可见性检测（POST /api/v1/brand-monitor/visibility/refresh）
     */
    public function refreshVisibility(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $adapters = $this->orchestrator->getAdapters();
        $visibilityService = new BrandVisibilityService();
        $count = $visibilityService->refreshVisibility($adapters, $siteId);

        return $this->success($request, [
            'results_count' => $count,
            'message' => '检测完成',
        ]);
    }

    // ── 信源检测 ──

    /**
     * 获取信源分析数据（GET /api/v1/brand-monitor/sources）
     */
    public function sources(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);
        $period = (string) $request->input('period', '7d');

        $data = $this->orchestrator->getSourceAnalysis($period, $siteId);

        return $this->success($request, $data);
    }

    // ── 舆情评价 ──

    /**
     * 获取舆情分析数据（GET /api/v1/brand-monitor/sentiment）
     */
    public function sentiment(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);
        $period = (string) $request->input('period', '7d');

        $data = $this->orchestrator->getSentimentAnalysis($period, $siteId);

        return $this->success($request, $data);
    }

    // ── 关键词管理 ──

    /**
     * 获取关键词列表（GET /api/v1/brand-monitor/keywords）
     */
    public function keywords(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $keywords = $this->keywordManager->getKeywords($siteId);

        return $this->success($request, $keywords);
    }

    /**
     * 添加关键词（POST /api/v1/brand-monitor/keywords）
     */
    public function addKeyword(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
        ]);

        $keyword = $this->keywordManager->addKeyword($validated['keyword'], $siteId);

        return $this->success($request, $keyword, 201);
    }

    /**
     * 删除关键词（DELETE /api/v1/brand-monitor/keywords/{id}）
     */
    public function removeKeyword(Request $request, int $id): JsonResponse
    {
        $siteId = $this->tenantId($request);

        // 确保关键词属于当前租户
        $keyword = BrandKeyword::query()
            ->whereKey($id)
            ->where('site_id', $siteId)
            ->first();

        if ($keyword === null) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => '关键词不存在或不属于当前站点'],
            ], 404);
        }

        $this->keywordManager->removeKeyword($id);

        return $this->success($request, ['deleted' => true]);
    }

    // ── 平台管理 ──

    /**
     * 获取平台列表（GET /api/v1/brand-monitor/platforms）
     *
     * 平台按 site_id 规则过滤：NULL = 全局共享，非 NULL = 仅同 site 可见。
     */
    public function platforms(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $platforms = BrandPlatform::query()
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id')
                    ->orWhere('site_id', $siteId);
            })
            ->get();

        return $this->success($request, $platforms);
    }

    /**
     * 更新平台配置（PUT /api/v1/brand-monitor/platforms/{id}）
     *
     * 仅允许更新全局平台（site_id=NULL）或本租户平台。
     */
    public function updatePlatform(Request $request, int $id): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $platform = BrandPlatform::query()
            ->whereKey($id)
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id')
                    ->orWhere('site_id', $siteId);
            })
            ->first();

        if ($platform === null) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => '平台不存在或无权修改'],
            ], 404);
        }

        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'check_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'api_config' => ['sometimes', 'array'],
        ]);

        $platform->update($validated);

        return $this->success($request, $platform->refresh());
    }
}
