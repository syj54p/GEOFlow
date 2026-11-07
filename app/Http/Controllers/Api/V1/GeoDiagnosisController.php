<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Models\Article;
use App\Models\GeoDiagnosisRun;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 GEO 诊断端点（租户隔离）。
 *
 * 必须经过 api.tenant 中间件，tenantId() 恒非 null。
 * scope: geo-diagnosis:read / geo-diagnosis:write。
 */
class GeoDiagnosisController extends BaseApiController
{
    use ResolvesTenant;

    public function __construct(
        private readonly GeoDiagnosisOrchestrator $orchestrator,
    ) {}

    /**
     * 运行文章诊断（POST /api/v1/articles/{articleId}/geo-diagnosis/run）
     */
    public function run(Request $request, int $articleId): JsonResponse
    {
        $siteId = $this->tenantId($request);
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $running = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
            ->exists();

        if ($running) {
            return response()->json([
                'error' => [
                    'code' => 'diagnosis_running',
                    'message' => '该文章已有诊断任务在运行中，请等待完成',
                ],
            ], 409);
        }

        $adminId = $this->auth($request)->auditAdminId;
        $context = ['site_id' => $siteId];

        $run = $this->orchestrator->runDiagnosis($article, $adminId, $context);

        return $this->success($request, $this->formatRun($run));
    }

    /**
     * 获取文章最新诊断结果（GET /api/v1/articles/{articleId}/geo-diagnosis/latest）
     */
    public function latest(Request $request, int $articleId): JsonResponse
    {
        $siteId = $this->tenantId($request);
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $run = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('site_id', $siteId)
            ->where('status', GeoDiagnosisRun::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->first();

        if ($run === null) {
            return $this->success($request, ['run' => null, 'message' => '该文章尚未运行过 GEO 诊断']);
        }

        return $this->success($request, $this->formatRun($run));
    }

    /**
     * 获取文章诊断历史（GET /api/v1/articles/{articleId}/geo-diagnosis/history）
     */
    public function history(Request $request, int $articleId): JsonResponse
    {
        $siteId = $this->tenantId($request);
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);

        $runs = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('site_id', $siteId)
            ->completed()
            ->latestFirst()
            ->paginate($perPage);

        return $this->success($request, [
            'items' => $runs->map(fn (GeoDiagnosisRun $r) => $this->formatRunSummary($r)),
            'pagination' => [
                'page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    /**
     * 获取租户诊断仪表盘（GET /api/v1/geo-diagnosis/dashboard）
     */
    public function dashboard(Request $request): JsonResponse
    {
        $siteId = $this->tenantId($request);

        $totalRuns = GeoDiagnosisRun::query()
            ->where('site_id', $siteId)
            ->where('status', GeoDiagnosisRun::STATUS_COMPLETED)
            ->count();

        $avgScore = GeoDiagnosisRun::query()
            ->where('site_id', $siteId)
            ->where('status', GeoDiagnosisRun::STATUS_COMPLETED)
            ->avg('total_score');

        $recentRuns = GeoDiagnosisRun::query()
            ->where('site_id', $siteId)
            ->completed()
            ->latestFirst()
            ->limit(10)
            ->with('article:id,title')
            ->get();

        return $this->success($request, [
            'total_runs' => $totalRuns,
            'average_score' => $avgScore !== null ? round((float) $avgScore, 1) : null,
            'recent_runs' => $recentRuns->map(fn (GeoDiagnosisRun $r) => [
                'run_id' => $r->id,
                'article_id' => $r->article_id,
                'article_title' => $r->article?->title ?? '',
                'total_score' => $r->total_score,
                'grade' => $r->grade,
                'grade_label' => $r->grade_label,
                'completed_at' => $r->completed_at?->toIso8601String(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatRun(GeoDiagnosisRun $run): array
    {
        return [
            'run_id' => $run->id,
            'article_id' => $run->article_id,
            'site_id' => $run->site_id,
            'status' => $run->status,
            'total_score' => $run->total_score,
            'content_structure_score' => $run->content_structure_score,
            'knowledge_alignment_score' => $run->knowledge_alignment_score,
            'technical_geo_score' => $run->technical_geo_score,
            'ai_visibility_score' => $run->ai_visibility_score,
            'grade' => $run->grade,
            'grade_label' => $run->grade_label,
            'findings' => $run->findings,
            'recommendations' => $run->recommendations,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatRunSummary(GeoDiagnosisRun $run): array
    {
        return [
            'run_id' => $run->id,
            'total_score' => $run->total_score,
            'content_structure_score' => $run->content_structure_score,
            'knowledge_alignment_score' => $run->knowledge_alignment_score,
            'technical_geo_score' => $run->technical_geo_score,
            'ai_visibility_score' => $run->ai_visibility_score,
            'grade' => $run->grade,
            'grade_label' => $run->grade_label,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }
}
