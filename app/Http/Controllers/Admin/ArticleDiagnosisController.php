<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\GeoDiagnosisRun;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 文章诊断控制器
 *
 * 处理文章级 GEO 诊断的运行、查询和历史记录。
 */
class ArticleDiagnosisController extends Controller
{
    public function __construct(
        private readonly GeoDiagnosisOrchestrator $orchestrator,
    ) {}

    /**
     * 运行文章诊断
     */
    public function run(Request $request, int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        // 检查是否有正在运行的诊断
        $runningDiagnosis = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
            ->exists();

        if ($runningDiagnosis) {
            return response()->json([
                'error' => [
                    'code' => 'diagnosis_running',
                    'message' => '该文章已有诊断任务在运行中，请等待完成',
                ],
            ], 409);
        }

        $adminId = $request->user()?->id;
        $context = [
            'site_id' => $request->input('site_id'),
        ];

        $run = $this->orchestrator->runDiagnosis($article, $adminId ? (int) $adminId : null, $context);

        return response()->json([
            'data' => [
                'run_id' => $run->id,
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
            ],
        ]);
    }

    /**
     * 获取文章最新诊断结果
     */
    public function latest(int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();
        $run = $this->orchestrator->getLatestDiagnosis($article);

        if ($run === null) {
            return response()->json([
                'data' => null,
                'meta' => ['message' => '该文章尚未运行过 GEO 诊断'],
            ]);
        }

        return response()->json([
            'data' => [
                'run_id' => $run->id,
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
            ],
        ]);
    }

    /**
     * 获取文章诊断历史
     */
    public function history(Request $request, int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $perPage = (int) $request->input('per_page', 10);
        $perPage = min(max($perPage, 1), 50);

        $runs = $article->geoDiagnosisRuns()
            ->completed()
            ->latestFirst()
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'items' => $runs->map(fn(GeoDiagnosisRun $run) => [
                    'run_id' => $run->id,
                    'total_score' => $run->total_score,
                    'content_structure_score' => $run->content_structure_score,
                    'knowledge_alignment_score' => $run->knowledge_alignment_score,
                    'technical_geo_score' => $run->technical_geo_score,
                    'ai_visibility_score' => $run->ai_visibility_score,
                    'grade' => $run->grade,
                    'grade_label' => $run->grade_label,
                    'completed_at' => $run->completed_at?->toIso8601String(),
                ]),
                'pagination' => [
                    'page' => $runs->currentPage(),
                    'last_page' => $runs->lastPage(),
                    'total' => $runs->total(),
                ],
            ],
        ]);
    }
}
