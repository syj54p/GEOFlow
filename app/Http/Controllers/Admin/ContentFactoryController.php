<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Task;
use App\Services\GeoFlow\ContentFactoryOrchestrator;
use App\Services\GeoFlow\SchemaOrgJsonLdGenerator;
use App\Support\GeoFlow\ContentFactoryStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentFactoryController extends Controller
{
    public function __construct(
        private readonly ContentFactoryOrchestrator $orchestrator,
        private readonly SchemaOrgJsonLdGenerator $schemaGenerator,
    ) {}

    /**
     * 获取内容工厂 7 阶段定义。
     */
    public function stages(): JsonResponse
    {
        $stages = array_map(fn (ContentFactoryStage $stage) => [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'order' => $stage->order(),
        ], ContentFactoryStage::all());

        return response()->json(['data' => $stages]);
    }

    /**
     * 获取文章的内容工厂阶段进度报告。
     */
    public function articleProgress(int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();
        $report = $this->orchestrator->progressReport($article);
        $currentStage = $this->orchestrator->currentStage($article);

        return response()->json([
            'data' => [
                'article_id' => (int) $article->id,
                'current_stage' => [
                    'stage' => $currentStage->value,
                    'label' => $currentStage->label(),
                ],
                'progress' => $report,
            ],
        ]);
    }

    /**
     * 获取 Task 下所有文章的阶段分布统计。
     */
    public function taskDistribution(int $taskId): JsonResponse
    {
        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $distribution = $this->orchestrator->stageDistribution($task);

        $stages = ContentFactoryStage::all();
        $result = [];
        foreach ($stages as $stage) {
            $result[] = [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'count' => $distribution[$stage->value] ?? 0,
            ];
        }

        return response()->json([
            'data' => [
                'task_id' => (int) $task->id,
                'distribution' => $result,
            ],
        ]);
    }

    /**
     * 为文章生成 Schema.org JSON-LD 数据。
     */
    public function generateSchema(Request $request, int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();
        $promptId = $request->input('prompt_id') ? (int) $request->input('prompt_id') : null;

        $success = $this->orchestrator->generateSchemaOrgData($article, $promptId);

        if (! $success) {
            return response()->json([
                'error' => [
                    'code' => 'schema_generation_failed',
                    'message' => 'Schema.org JSON-LD 生成失败，请检查文章标题和 AI 模型配置。',
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'article_id' => (int) $article->id,
                'schema_org_data' => $article->schema_org_data,
                'stage' => $this->orchestrator->currentStage($article)->value,
            ],
        ]);
    }
}
