<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis;

use App\Models\Article;
use App\Models\GeoDiagnosisRun;
use App\Models\GeoDiagnosisSetting;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\CanonicalLinkChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\CompetitorQuoteChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\FaqAndDefinitionChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\H1TitleChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\HeadingHierarchyChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\KnowledgeContradictionChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\KnowledgeCoverageChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\KeywordFrequencyChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\KeywordRankChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\LlmsTxtChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\ParagraphLengthChecker;
use App\Services\GeoFlow\GeoDiagnosis\Checkers\SchemaOrgChecker;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;
use Illuminate\Support\Facades\Log;

/**
 * GEO 诊断编排器。
 *
 * 协调所有检查器执行诊断，计算评分，生成建议，并持久化结果。
 */
final class GeoDiagnosisOrchestrator
{
    /** @var list<DiagnosisCheckerInterface> */
    private array $checkers;

    public function __construct(
        private readonly GeoDiagnosisScorer $scorer,
        private readonly GeoDiagnosisRecommendationEngine $recommendationEngine,
    ) {
        $this->checkers = $this->buildCheckers();
    }

    /**
     * 对一篇文章运行完整 GEO 诊断。
     *
     * @param  Article  $article   待诊断文章
     * @param  int|null $adminId   操作管理员 ID
     * @param  array    $context   额外上下文数据（知识库、品牌监测等）
     * @return GeoDiagnosisRun
     */
    public function runDiagnosis(Article $article, ?int $adminId = null, array $context = []): GeoDiagnosisRun
    {
        // 创建诊断运行记录
        $run = GeoDiagnosisRun::create([
            'article_id' => $article->id,
            'admin_id' => $adminId,
            'site_id' => $context['site_id'] ?? null,
            'status' => GeoDiagnosisRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            // 获取诊断设置
            $settings = GeoDiagnosisSetting::getForSite($context['site_id'] ?? null);
            $enabledChecks = $settings->enabled_checks ?? GeoDiagnosisSetting::ALL_CHECKS;

            // 执行各检查器
            $checkResults = [];
            $checkOutputs = [];

            foreach ($this->checkers as $checker) {
                // 跳过被禁用的检查项
                if (!in_array($checker->checkId(), $enabledChecks, true)) {
                    continue;
                }

                try {
                    $result = $checker->check($article, $context);
                    $dimension = $checker->dimension();

                    $checkResults[$dimension][] = [
                        'passed' => $result['passed'],
                        'score' => $result['score'],
                        'dimension' => $dimension,
                    ];
                    $checkOutputs[] = [
                        'finding' => $result['finding'],
                        'recommendation' => $result['recommendation'],
                    ];
                } catch (\Throwable $e) {
                    Log::warning('GeoDiagnosis checker failed', [
                        'checker' => $checker->checkId(),
                        'article_id' => $article->id,
                        'error' => $e->getMessage(),
                    ]);

                    // 失败的检查项给 0 分
                    $checkResults[$checker->dimension()][] = [
                        'passed' => false,
                        'score' => 0,
                        'dimension' => $checker->dimension(),
                    ];
                }
            }

            // 计算评分
            $scores = $this->scorer->score($checkResults, $settings);

            // 汇总建议
            $compiled = $this->recommendationEngine->compile($checkOutputs);

            // 更新诊断运行记录
            $run->update([
                'status' => GeoDiagnosisRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'total_score' => $scores['total_score'],
                'content_structure_score' => $scores['content_structure_score'],
                'knowledge_alignment_score' => $scores['knowledge_alignment_score'],
                'technical_geo_score' => $scores['technical_geo_score'],
                'ai_visibility_score' => $scores['ai_visibility_score'],
                'findings' => $compiled['findings'],
                'recommendations' => $compiled['recommendations'],
            ]);

            return $run->refresh();

        } catch (\Throwable $e) {
            $run->update([
                'status' => GeoDiagnosisRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('GeoDiagnosis run failed', [
                'run_id' => $run->id,
                'article_id' => $article->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $run->refresh();
        }
    }

    /**
     * 获取文章的最新诊断结果。
     */
    public function getLatestDiagnosis(Article $article): ?GeoDiagnosisRun
    {
        return $article->latestGeoDiagnosisRun;
    }

    /**
     * 获取文章的诊断历史。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, GeoDiagnosisRun>
     */
    public function getDiagnosisHistory(Article $article, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $article->geoDiagnosisRuns()
            ->completed()
            ->latestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * 获取诊断概览数据。
     */
    public function getDashboardData(?int $siteId = null): array
    {
        // 文章平均 GEO 分
        $avgScore = (int) round(
            GeoDiagnosisRun::query()
                ->completed()
                ->selectRaw('AVG(total_score) as avg_score')
                ->value('avg_score') ?? 0
        );

        // 与上次对比的增量
        $previousAvg = (int) round(
            GeoDiagnosisRun::query()
                ->completed()
                ->where('created_at', '<', now()->subDays(7))
                ->selectRaw('AVG(total_score) as avg_score')
                ->value('avg_score') ?? 0
        );
        $avgScoreDelta = $avgScore - $previousAvg;

        // 待优化文章数（分数 < 60）
        $articlesNeedingOptimization = Article::query()
            ->whereHas('latestGeoDiagnosisRun', function ($q) {
                $q->where('total_score', '<', 60);
            })
            ->count();

        // 低分文章数（分数 < 40）
        $lowScoreArticles = Article::query()
            ->whereHas('latestGeoDiagnosisRun', function ($q) {
                $q->where('total_score', '<', 40);
            })
            ->count();

        // 品牌监测相关数据
        $activeKeywords = \App\Models\BrandKeyword::query()->active()->count();
        $activePlatforms = \App\Models\BrandPlatform::query()->enabled()->count();

        // 品牌可见度百分比
        $brandVisibilityPct = 0;
        if ($activePlatforms > 0) {
            $brandVisibilityPct = (int) round(
                \App\Models\BrandMonitorResult::query()
                    ->inPeriod('7d')
                    ->selectRaw('AVG(visibility_score) as avg_vis')
                    ->value('avg_vis') ?? 0
            );
        }

        // 最近活动
        $recentActivities = \App\Models\BrandActivityLog::query()
            ->forSite($siteId)
            ->latestFirst()
            ->limit(10)
            ->get()
            ->map(fn($log) => [
                'type' => $log->activity_type,
                'title' => $log->title,
                'description' => $log->description,
                'severity' => $log->severity,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->toArray();

        // 各平台可见度快照
        $platformVisibility = \App\Models\BrandPlatform::query()
            ->enabled()
            ->get()
            ->map(function ($platform) {
                $avgVis = $platform->monitorResults()
                    ->inPeriod('7d')
                    ->selectRaw('AVG(visibility_score) as avg_vis')
                    ->value('avg_vis');
                return [
                    'platform' => $platform->code,
                    'name' => $platform->name,
                    'visibility_pct' => (int) round($avgVis ?? 0),
                ];
            })
            ->toArray();

        return [
            'avg_geo_score' => $avgScore,
            'avg_geo_score_delta' => $avgScoreDelta,
            'brand_visibility_pct' => $brandVisibilityPct,
            'articles_needing_optimization' => $articlesNeedingOptimization,
            'low_score_articles' => $lowScoreArticles,
            'active_keywords' => $activeKeywords,
            'active_platforms' => $activePlatforms,
            'recent_activities' => $recentActivities,
            'platform_visibility' => $platformVisibility,
        ];
    }

    /**
     * @return list<DiagnosisCheckerInterface>
     */
    private function buildCheckers(): array
    {
        return [
            // 内容结构 (CS)
            new H1TitleChecker(),
            new HeadingHierarchyChecker(),
            new ParagraphLengthChecker(),
            new FaqAndDefinitionChecker(),

            // 知识对齐 (KA)
            new KnowledgeCoverageChecker(),
            new KeywordFrequencyChecker(),
            new KnowledgeContradictionChecker(),

            // 技术 GEO (TG)
            new LlmsTxtChecker(),
            new SchemaOrgChecker(),
            new CanonicalLinkChecker(),

            // AI 可见度 (AV)
            new keywordRankChecker(),
            new CompetitorQuoteChecker(),
        ];
    }
}
