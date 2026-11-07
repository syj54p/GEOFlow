<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\BrandKeyword;
use App\Models\Category;
use App\Models\GeoDiagnosisRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin Web 全局视图回归测试。
 *
 * 验证：超管 session 访问诊断/品牌监测页时，全局聚合行为不变，
 * 包括遗留 NULL site_id 数据也可见。
 */
class GlobalViewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'super_admin',
            'password' => 'secret',
            'email' => 'super@example.com',
            'display_name' => 'Super Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createArticle(string $title = 'Test Article'): Article
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-' . uniqid()]);
        $author = Author::query()->create(['name' => 'Author', 'email' => 'a@' . uniqid() . '.com']);

        return Article::query()->create([
            'title' => $title,
            'slug' => 'slug-' . uniqid(),
            'content' => 'Test content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    public function test_admin_diagnosis_dashboard_sees_all_sites_aggregated(): void
    {
        $admin = $this->createSuperAdmin();

        // 创建多个站点的诊断记录
        $article1 = $this->createArticle('Article 1');
        $article2 = $this->createArticle('Article 2');
        $article3 = $this->createArticle('Article 3');

        GeoDiagnosisRun::query()->create([
            'article_id' => $article1->id,
            'site_id' => 100,
            'admin_id' => $admin->id,
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
            'article_id' => $article2->id,
            'site_id' => 200,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 70,
            'grade' => 'B',
            'grade_label' => '良好',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 遗留全局数据（site_id=NULL）
        GeoDiagnosisRun::query()->create([
            'article_id' => $article3->id,
            'site_id' => null,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 60,
            'grade' => 'C',
            'grade_label' => '中等',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 超管访问诊断 Dashboard API → 看到全部 3 条
        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.geo-diagnosis.dashboard'));

        $response->assertOk()
            ->assertJsonPath('data.total_runs', 3);
    }

    public function test_admin_article_latest_sees_all_runs(): void
    {
        $admin = $this->createSuperAdmin();
        $article = $this->createArticle();

        // 站点 100 的诊断
        $run1 = GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 80,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 200 的诊断（更新）
        $run2 = GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 200,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 85,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now()->addMinute(), // 更新
        ]);

        // 超管访问 latest → 不传 site_id，返回最新一条（run2）
        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.article-diagnosis.latest', ['articleId' => $article->id]));

        $response->assertOk()
            ->assertJsonPath('data.run_id', $run2->id);
    }

    public function test_admin_article_history_sees_all_sites(): void
    {
        $admin = $this->createSuperAdmin();
        $article = $this->createArticle();

        // 站点 100 的诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 80,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 200 的诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 200,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 70,
            'grade' => 'B',
            'grade_label' => '良好',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now()->addMinute(),
        ]);

        // 遗留全局数据
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => null,
            'admin_id' => $admin->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 60,
            'grade' => 'C',
            'grade_label' => '中等',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now()->addMinutes(2),
        ]);

        // 超管访问 history → 不传 site_id，返回全部 3 条
        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.article-diagnosis.history', ['articleId' => $article->id]));

        $response->assertOk()
            ->assertJsonCount(3, 'data.items');
    }

    public function test_admin_brand_monitor_keywords_sees_all(): void
    {
        $admin = $this->createSuperAdmin();

        // 站点 100 的关键词
        BrandKeyword::query()->create([
            'keyword' => '萌友宠物',
            'site_id' => 100,
            'is_active' => true,
        ]);

        // 站点 200 的关键词
        BrandKeyword::query()->create([
            'keyword' => '御湖物业',
            'site_id' => 200,
            'is_active' => true,
        ]);

        // 全局关键词（site_id=NULL）
        BrandKeyword::query()->create([
            'keyword' => 'GEOFlow',
            'site_id' => null,
            'is_active' => true,
        ]);

        // 超管访问关键词列表 → 看到全部 3 个
        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.brand-monitor.keywords.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_delete_any_keyword(): void
    {
        $admin = $this->createSuperAdmin();

        $keyword = BrandKeyword::query()->create([
            'keyword' => '测试关键词',
            'site_id' => 100,
            'is_active' => true,
        ]);

        // 超管删除任意关键词（不传 site_id）→ 成功
        $response = $this->actingAs($admin, 'admin')
            ->deleteJson(route('admin.brand-monitor.keywords.destroy', ['id' => $keyword->id]));

        $response->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('brand_keywords', ['id' => $keyword->id]);
    }
}
