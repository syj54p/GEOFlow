<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Article;
use App\Models\GeoDiagnosisRun;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 运行文章 GEO 诊断任务。
 *
 * 异步执行单篇文章的四维诊断检查，生成评分和改进建议。
 */
final class RunGeoDiagnosisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $articleId,
        public readonly ?int $adminId = null,
        public readonly ?int $siteId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'geo_diagnosis_' . $this->articleId;
    }

    public function handle(GeoDiagnosisOrchestrator $orchestrator): void
    {
        $article = Article::query()->find($this->articleId);
        if ($article === null) {
            Log::warning('RunGeoDiagnosisJob: article not found', ['article_id' => $this->articleId]);
            return;
        }

        // 检查是否已有运行中的诊断
        $running = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
            ->exists();

        if ($running) {
            Log::info('RunGeoDiagnosisJob: skipping, already running', ['article_id' => $this->articleId]);
            return;
        }

        $context = ['site_id' => $this->siteId];
        $orchestrator->runDiagnosis($article, $this->adminId, $context);

        Log::info('RunGeoDiagnosisJob: completed', ['article_id' => $this->articleId]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunGeoDiagnosisJob: failed', [
            'article_id' => $this->articleId,
            'error' => $exception?->getMessage(),
        ]);

        // 将运行中的诊断标记为失败
        GeoDiagnosisRun::query()
            ->where('article_id', $this->articleId)
            ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
            ->update([
                'status' => GeoDiagnosisRun::STATUS_FAILED,
                'error_message' => $exception?->getMessage() ?? 'Unknown error',
                'completed_at' => now(),
            ]);
    }
}
