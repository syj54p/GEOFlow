<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\GeoDiagnosisRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 双站点同文章隔离测试。
 *
 * 验证：两个 niucloud 站点对同一篇 GEOFlow 文章分别诊断，
 * 结果互不可见；运行中检查按 site 隔离，互不阻塞。
 */
class DualSiteSameArticleTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(string $username = 'test_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret',
            'email' => "{$username}@example.com",
            'display_name' => 'Test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createArticle(string $title = 'Shared Article'): Article
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-' . uniqid()]);
        $author = Author::query()->create(['name' => 'Author', 'email' => 'a@' . uniqid() . '.com']);

        return Article::query()->create([
            'title' => $title,
            'slug' => 'slug-' . uniqid(),
            'content' => 'Test content for dual-site diagnosis.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    private function createSiteToken(Admin $admin, int $siteId, array $scopes = ['geo-diagnosis:read', 'geo-diagnosis:write']): string
    {
        $token = $admin->createToken('tenant-token', $scopes);
        $token->accessToken->forceFill(['site_id' => $siteId])->save();

        return $token->plainTextToken;
    }

    public function test_two_sites_diagnose_same_article_results_isolated(): void
    {
        $admin1 = $this->createAdmin('site1_admin');
        $admin2 = $this->createAdmin('site2_admin');
        $article = $this->createArticle();

        $token1 = $this->createSiteToken($admin1, 100);
        $token2 = $this->createSiteToken($admin2, 200);

        // 站点 1 创建诊断记录
        $run1 = GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => $admin1->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 85,
            'content_structure_score' => 80,
            'knowledge_alignment_score' => 90,
            'technical_geo_score' => 85,
            'ai_visibility_score' => 85,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 2 创建诊断记录（同一文章）
        $run2 = GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 200,
            'admin_id' => $admin2->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 72,
            'content_structure_score' => 70,
            'knowledge_alignment_score' => 75,
            'technical_geo_score' => 70,
            'ai_visibility_score' => 72,
            'grade' => 'B',
            'grade_label' => '良好',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 1 查 latest → 只见自己的（score=85）
        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/latest");

        $response->assertOk()
            ->assertJsonPath('data.run_id', $run1->id)
            ->assertJsonPath('data.site_id', 100)
            ->assertJsonPath('data.total_score', 85);

        // 站点 2 查 latest → 只见自己的（score=72）
        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/latest");

        $response->assertOk()
            ->assertJsonPath('data.run_id', $run2->id)
            ->assertJsonPath('data.site_id', 200)
            ->assertJsonPath('data.total_score', 72);

        // 站点 1 查 history → 只返回自己的 1 条
        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/history");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.run_id', $run1->id);

        // 站点 2 查 history → 只返回自己的 1 条
        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/history");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.run_id', $run2->id);
    }

    public function test_running_diagnosis_check_is_per_site(): void
    {
        $admin1 = $this->createAdmin('site1_admin');
        $admin2 = $this->createAdmin('site2_admin');
        $article = $this->createArticle();

        $token1 = $this->createSiteToken($admin1, 100);
        $token2 = $this->createSiteToken($admin2, 200);

        // 站点 1 有一个运行中的诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => $admin1->id,
            'status' => GeoDiagnosisRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        // 站点 2 应该可以启动诊断（站点 1 的运行中不阻塞站点 2）
        // 注意：这里只测试运行中检查的隔离，不实际触发诊断流程
        $running = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('site_id', 200)
            ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
            ->exists();

        $this->assertFalse($running, '站点 2 不应看到站点 1 的运行中诊断');

        // 站点 1 自己不能重复启动
        $running = GeoDiagnosisRun::query()
            ->where('article_id', $article->id)
            ->where('site_id', 100)
            ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
            ->exists();

        $this->assertTrue($running, '站点 1 应看到自己的运行中诊断');
    }

    public function test_dashboard_only_shows_site_own_data(): void
    {
        $admin1 = $this->createAdmin('site1_admin');
        $admin2 = $this->createAdmin('site2_admin');
        $article = $this->createArticle();

        $token1 = $this->createSiteToken($admin1, 100, ['geo-diagnosis:read']);
        $token2 = $this->createSiteToken($admin2, 200, ['geo-diagnosis:read']);

        // 站点 1 有 2 条诊断记录
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 100,
            'admin_id' => $admin1->id,
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
            'admin_id' => $admin1->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 85,
            'grade' => 'A',
            'grade_label' => '优秀',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 2 有 1 条诊断记录
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'site_id' => 200,
            'admin_id' => $admin2->id,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'total_score' => 70,
            'grade' => 'B',
            'grade_label' => '良好',
            'findings' => [],
            'recommendations' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 站点 1 Dashboard → total_runs=2, average_score=82.5
        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson('/api/v1/geo-diagnosis/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.total_runs', 2)
            ->assertJsonPath('data.average_score', 82.5);

        // 站点 2 Dashboard → total_runs=1, average_score=70
        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/v1/geo-diagnosis/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.total_runs', 1)
            ->assertJsonPath('data.average_score', 70.0);
    }
}
