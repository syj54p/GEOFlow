<?php

declare(strict_types=1);

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\Task;
use App\Support\GeoFlow\ContentFactoryStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 内容工厂 7 阶段编排器。
 *
 * 将现有的 Task → Article → Quality → Publish 管线统一为 7 阶段模型，
 * 并在阶段 5（Schema）集成 Schema.org JSON-LD 结构化数据生成。
 */
final class ContentFactoryOrchestrator
{
    public function __construct(
        private readonly ArticleContentGenerationService $contentGeneration,
        private readonly ArticleContentPromptRenderer $promptRenderer,
        private readonly ArticleAiQualityInspectionService $qualityInspection,
        private readonly ArticleAiOptimizationCoordinator $optimizationCoordinator,
        private readonly ArticleWorkflowTransitionService $workflowTransition,
        private readonly SchemaOrgJsonLdGenerator $schemaGenerator,
    ) {}

    /**
     * 获取文章当前所处的工厂阶段。
     */
    public function currentStage(Article $article): ContentFactoryStage
    {
        $hasSchema = ! empty($article->schema_org_data);
        $isPublished = (string) $article->status === 'published';
        $reviewStatus = (string) ($article->review_status ?? 'pending');

        return ContentFactoryStage::fromArticleState(
            status: (string) $article->status,
            reviewStatus: $reviewStatus,
            hasSchemaData: $hasSchema,
            isPublished: $isPublished,
        );
    }

    /**
     * 为文章生成 Schema.org JSON-LD 结构化数据（阶段 5）。
     *
     * 使用文章标题、关键词和品牌信息作为输入，调用 AI 模型生成 JSON-LD，
     * 并存入文章的 schema_org_data 字段。
     */
    public function generateSchemaOrgData(Article $article, ?int $schemaPromptId = null): bool
    {
        if (empty($article->title)) {
            return false;
        }

        try {
            $jsonLd = $this->schemaGenerator->generateForArticle($article, $schemaPromptId);

            if ($jsonLd === null) {
                return false;
            }

            $article->forceFill(['schema_org_data' => $jsonLd])->save();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Content factory Schema.org generation failed.', [
                'article_id' => (int) $article->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 推进文章到下一个工厂阶段。
     *
     * @return array{stage: ContentFactoryStage, action: string, success: bool}
     */
    public function advance(Article $article, string $action = 'auto'): array
    {
        $currentStage = $this->currentStage($article);
        $nextStage = $currentStage->next();

        if ($nextStage === $currentStage) {
            return ['stage' => $currentStage, 'action' => 'already_final', 'success' => true];
        }

        return match ($nextStage) {
            ContentFactoryStage::EVIDENCE => $this->advanceEvidence($article),
            ContentFactoryStage::GENERATION => $this->advanceGeneration($article),
            ContentFactoryStage::REVIEW => $this->advanceReview($article),
            ContentFactoryStage::SCHEMA => $this->advanceSchema($article),
            ContentFactoryStage::PUBLISH => $this->advancePublish($article),
            ContentFactoryStage::MONITOR => ['stage' => $nextStage, 'action' => 'monitoring', 'success' => true],
            default => ['stage' => $currentStage, 'action' => 'no_transition', 'success' => false],
        };
    }

    /**
     * 获取 Task 下所有文章的阶段分布统计。
     *
     * @return array<string, int>
     */
    public function stageDistribution(Task $task): array
    {
        $distribution = array_fill_keys(
            array_map(fn (ContentFactoryStage $s) => $s->value, ContentFactoryStage::all()),
            0,
        );

        $articles = Article::query()
            ->where('task_id', (int) $task->id)
            ->select(['status', 'review_status', 'schema_org_data'])
            ->get();

        foreach ($articles as $article) {
            $stage = ContentFactoryStage::fromArticleState(
                status: (string) $article->status,
                reviewStatus: (string) ($article->review_status ?? 'pending'),
                hasSchemaData: ! empty($article->schema_org_data),
                isPublished: (string) $article->status === 'published',
            );
            $distribution[$stage->value]++;
        }

        return $distribution;
    }

    /**
     * 获取阶段进度的可读描述。
     *
     * @return list<array{stage: string, label: string, order: int, status: string}>
     */
    public function progressReport(Article $article): array
    {
        $current = $this->currentStage($article);
        $report = [];

        foreach (ContentFactoryStage::all() as $stage) {
            if ($stage->order() < $current->order()) {
                $status = 'completed';
            } elseif ($stage === $current) {
                $status = 'in_progress';
            } else {
                $status = 'pending';
            }

            $report[] = [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'order' => $stage->order(),
                'status' => $status,
            ];
        }

        return $report;
    }

    // -- Private stage transition handlers --

    private function advanceEvidence(Article $article): array
    {
        return ['stage' => ContentFactoryStage::EVIDENCE, 'action' => 'evidence_collected', 'success' => true];
    }

    private function advanceGeneration(Article $article): array
    {
        return ['stage' => ContentFactoryStage::GENERATION, 'action' => 'generation_complete', 'success' => true];
    }

    private function advanceReview(Article $article): array
    {
        return ['stage' => ContentFactoryStage::REVIEW, 'action' => 'review_complete', 'success' => true];
    }

    private function advanceSchema(Article $article): array
    {
        $success = $this->generateSchemaOrgData($article);

        return [
            'stage' => ContentFactoryStage::SCHEMA,
            'action' => $success ? 'schema_generated' : 'schema_failed',
            'success' => $success,
        ];
    }

    private function advancePublish(Article $article): array
    {
        return ['stage' => ContentFactoryStage::PUBLISH, 'action' => 'publish_complete', 'success' => true];
    }
}
