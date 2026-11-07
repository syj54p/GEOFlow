<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\GeoDiagnosisRun;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GeoDiagnosisOrchestrator Service 层单元测试。
 *
 * 覆盖：
 *   - runDiagnosis context 带 site_id → run 落库
 *   - getLatestDiagnosis 按 site 过滤，null 时全局
 *   - getDiagnosisHistory 按 site 过滤，null 时全局
 */
class GeoDiagnosisOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private GeoDiagnosisOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = app(GeoDiagnosisOrchestrator::class);
    }

    private function createArticle(string $title = 'Test Article'): Article
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-' . uniqid()]);
        $author = Author::query()->create(['name' => 'Author', 'email' => 'a@' . uniqid() . '.com']);

        return Article::query()->create([
            'title' => $title,
            'slug' => 'slug-' . uniqid(),
            'content' => 'Test content for diagnosis.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    public function test_run_diagnosis_persists_site_id_from_context(): void
    {
        $article = $this->createArticle();

        $run = $this->orchestrator->runDiagnosis($article, adminId: 1, context: [
            'site_id' => 42,
        ]);

        $this->assertDatabaseHas('geo_diagnosis_runs', [
            'id' => $run->id,
            'site_id' => 42,
            'article_id' => $article->id,
            'admin_id' => 1,
        ]);
    }

    public function test_run_diagnosis_without_site_id_persists_null(): void
    {
        $article = $this->createArticle();

        $run = $this->orchestrator->runDiagnosis($article, adminId: 1, context: []);

        $this->assertDatabaseHas('geo_diagnosis_runs', [
            'id' => $run->id,
            'site_id' => null,
        ]);
    }

    public function test_latest_diagnosis_filters_by_site(): void
    {
        $article = $this->createArticle();

        // 站点 100 的诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => 1,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 85,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 200 的诊断（更新）
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 200,
            'admin_id' => 1,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 72,
            'grade' => 'B',
            'grade_label' => '良好',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now()->addMinute(),
        ]);

        // 查询站点 100 → 返回 score=85
        $latest = $this->orchestrator->getLatestDiagnosis($article, siteId: 100);
        $this->assertNotNull($latest);
        $this->assertEquals(85, $latest->total_score);
        $this->assertEquals(100, $latest->site_id);

        // 查询站点 200 → 返回 score=72
        $latest = $this->orchestrator->getLatestDiagnosis($article, siteId: 200);
        $this->assertNotNull($latest);
        $this->assertEquals(72, $latest->total_score);
        $this->assertEquals(200, $latest->site_id);

        // 查询全局（null）→ 返回最新一条（score=72）
        $latest = $this->orchestrator->getLatestDiagnosis($article, siteId: null);
        $this->assertNotNull($latest);
        $this->assertEquals(72, $latest->total_score);
    }

    public function test_latest_diagnosis_returns_null_when_no_match(): void
    {
        $article = $this->createArticle();

        // 只有站点 100 的诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => 1,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 85,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 查询站点 200 → 返回 null
        $latest = $this->orchestrator->getLatestDiagnosis($article, siteId: 200);
        $this->assertNull($latest);
    }

    public function test_diagnosis_history_filters_by_site(): void
    {
        $article = $this->createArticle();

        // 站点 100 的 2 条诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => 1,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 80,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => 1,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 85,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now()->addMinute(),
        ]);

        // 站点 200 的 1 条诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 200,
            'admin_id' => 1,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 70,
            'grade' => 'B',
            'grade_label' => '良好',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now()->addMinutes(2),
        ]);

        // 查询站点 100 → 返回 2 条
        $history = $this->orchestrator->getDiagnosisHistory($article, limit: 10, siteId: 100);
        $this->assertCount(2, $history);
        $this->assertTrue($history->every(fn ($run) => $run->site_id === 100));

        // 查询站点 200 → 返回 1 条
        $history = $this->orchestrator->getDiagnosisHistory($article, limit: 10, siteId: 200);
        $this->assertCount(1, $history);
        $this->assertEquals(200, $history->first()->site_id);

        // 查询全局（null）→ 返回全部 3 条
        $history = $this->orchestrator->getDiagnosisHistory($article, limit: 10, siteId: null);
        $this->assertCount(3, $history);
    }

    public function test_diagnosis_history_respects_limit(): void
    {
        $article = $this->createArticle();

        // 创建 5 条诊断记录
        for ($i = 0; $i < 5; $i++) {
            GeoDiagnosisRun::query()->create([
                'article_id' => $article->id,
                'site_id' => 100,
                'admin_id' => 1,
                'status' => GeoDiagnosisRun::STATUS_COMPLETED,
                'total_score' => 70 + $i,
                'grade' => 'B',
                'grade_label' => '良好',
                'findings' => [],
                'recommendations' => [],
                'started_at' => now(),
                'completed_at' => now()->addMinutes($i),
            ]);
        }

        // limit=3 → 返回 3 条
        $history = $this->orchestrator->getDiagnosisHistory($article, limit: 3, siteId: 100);
        $this->assertCount(3, $history);

        // limit=10 → 返回全部 5 条
        $history = $this->orchestrator->getDiagnosisHistory($article, limit: 10, siteId: 100);
        $this->assertCount(5, $history);
    }
}
