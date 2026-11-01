<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\GeoInflowAction;
use App\Services\GeoFlow\GeoInflowOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * GEOInflow 闭环批量评估定时命令。
 *
 * 扫描所有已发布文章，调用 GeoInflowOrchestrator 评估，
 * 对需要优化的文章自动创建 pending 状态的 GeoInflowAction 记录。
 */
final class GeoInflowBatchEvaluateCommand extends Command
{
    protected $signature = 'geoflow:geo-inflow-batch-evaluate
        {--dry-run : 仅评估不写入动作记录}
        {--limit=0 : 最多评估这么多篇文章（0 = 不限）}
        {--after-id=0 : 从该文章 ID 之后开始（用于分批）}';

    protected $description = '批量评估已发布文章的 GEOInflow 闭环状态，自动生成待处理优化动作';

    public function __construct(
        private readonly GeoInflowOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $afterId = max(0, (int) $this->option('after-id'));

        $this->info($dryRun ? '[DRY RUN] 仅评估，不写入动作记录' : '开始批量评估已发布文章…');

        $query = Article::query()
            ->where('status', 'published')
            ->where('id', '>', $afterId)
            ->orderBy('id');

        $totalArticles = (clone $query)->count();
        $evaluateCount = $limit > 0 ? min($limit, $totalArticles) : $totalArticles;

        $this->info("共 {$totalArticles} 篇已发布文章，本次评估 {$evaluateCount} 篇");

        if ($evaluateCount === 0) {
            $this->info('无需评估，退出。');
            return self::SUCCESS;
        }

        $evaluated = 0;
        $needsAction = 0;
        $actionsCreated = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($evaluateCount);
        $bar->start();

        foreach ($query->lazyById(50) as $article) {
            if ($limit > 0 && $evaluated >= $limit) {
                break;
            }

            try {
                $evaluation = $this->orchestrator->evaluateArticle($article);
                $evaluated++;

                if ($evaluation['needs_action']) {
                    $needsAction++;

                    if (! $dryRun) {
                        $created = $this->createPendingAction($article, $evaluation);
                        if ($created) {
                            $actionsCreated++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('GEOInflow batch: article evaluation failed', [
                    'article_id' => (int) $article->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('评估完成：');
        $this->line("  已评估：{$evaluated} 篇");
        $this->line("  需优化：{$needsAction} 篇");
        if (! $dryRun) {
            $this->line("  新建动作：{$actionsCreated} 条");
        }
        if ($errors > 0) {
            $this->warn("  失败：{$errors} 篇（已记录日志）");
        }

        Log::info('GEOInflow batch evaluation completed', [
            'dry_run' => $dryRun,
            'evaluated' => $evaluated,
            'needs_action' => $needsAction,
            'actions_created' => $actionsCreated,
            'errors' => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 为文章创建 pending 状态的闭环动作记录。
     * 避免重复创建：同一文章同一推荐动作在 pending 状态下不再新建。
     */
    private function createPendingAction(Article $article, array $evaluation): bool
    {
        $actionType = (string) ($evaluation['recommended_action'] ?? 'optimize_metadata');

        $exists = GeoInflowAction::query()
            ->where('article_id', (int) $article->id)
            ->where('action_type', $actionType)
            ->where('status', GeoInflowAction::STATUS_PENDING)
            ->exists();

        if ($exists) {
            return false;
        }

        GeoInflowAction::create([
            'article_id' => (int) $article->id,
            'action_type' => $actionType,
            'trigger_source' => $this->resolveTriggerSource($evaluation['reasons'] ?? []),
            'severity_score' => (int) ($evaluation['severity_score'] ?? 0),
            'reasons' => $evaluation['reasons'] ?? [],
            'context' => [
                'source' => 'batch_evaluate',
                'evaluated_at' => now()->toIso8601String(),
            ],
            'status' => GeoInflowAction::STATUS_PENDING,
        ]);

        return true;
    }

    /**
     * 从评估原因列表推断主要触发来源。
     */
    private function resolveTriggerSource(array $reasons): string
    {
        $reasonText = implode(' ', $reasons);

        return match (true) {
            str_contains($reasonText, '可见度') => GeoInflowAction::TRIGGER_VISIBILITY,
            str_contains($reasonText, '分发失败') => GeoInflowAction::TRIGGER_DISTRIBUTION,
            str_contains($reasonText, 'PV') => GeoInflowAction::TRIGGER_TRAFFIC,
            str_contains($reasonText, '质量分') => GeoInflowAction::TRIGGER_QUALITY_TREND,
            default => GeoInflowAction::TRIGGER_MANUAL,
        };
    }
}
