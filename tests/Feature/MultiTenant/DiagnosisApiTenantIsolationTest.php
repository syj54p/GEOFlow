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
 * GEO 诊断 API 多租户隔离集成测试。
 *
 * 覆盖：
 *   - Token 绑定 site_id 后，API 仅返回同站点数据
 *   - 跨站点数据不可见（fail-closed）
 *   - scope 鉴权（geo-diagnosis:read / write）
 *   - X-Site-Id Header 与 Token 绑定一致性校验
 */
class DiagnosisApiTenantIsolationTest extends TestCase
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

    /**
     * 创建绑定 site_id 的 Sanctum Token。
     */
    private function createSiteToken(Admin $admin, int $siteId, array $scopes = ['geo-diagnosis:read', 'geo-diagnosis:write']): string
    {
        $token = $admin->createToken('tenant-token', $scopes);
        $token->accessToken->forceFill(['site_id' => $siteId])->save();

        return $token->plainTextToken;
    }

    /**
     * 创建未绑定 site_id 的 Token（过渡期）。
     */
    private function createUnboundToken(Admin $admin, array $scopes = ['geo-diagnosis:read']): string
    {
        return $admin->createToken('unbound-token', $scopes)->plainTextToken;
    }

    // ── Scope 鉴权 ──

    public function test_dashboard_requires_geo_diagnosis_read_scope(): void
    {
        $admin = $this->createAdmin('scope_test');
        $token = $this->createSiteToken($admin, 100, ['catalog:read']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/geo-diagnosis/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_run_requires_geo_diagnosis_write_scope(): void
    {
        $admin = $this->createAdmin('scope_write');
        $article = $this->createArticle();
        $token = $this->createSiteToken($admin, 100, ['geo-diagnosis:read']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/articles/{$article->id}/geo-diagnosis/run")
            ->assertForbidden();
    }

    // ── Token 绑定 site_id：正常路径 ──

    public function test_dashboard_returns_only_site_data(): void
    {
        $admin = $this->createAdmin('dashboard_site');
        $article = $this->createArticle();

        // 站点 100 有一条已完成诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'site_id' => 100,
            'total_score' => 75,
            'content_structure_score' => 20,
            'knowledge_alignment_score' => 20,
            'technical_geo_score' => 15,
            'ai_visibility_score' => 20,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'findings' => [],
            'recommendations' => [],
        ]);

        // 站点 200 有一条（不应出现在 100 的视图中）
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'site_id' => 200,
            'total_score' => 90,
            'content_structure_score' => 25,
            'knowledge_alignment_score' => 25,
            'technical_geo_score' => 20,
            'ai_visibility_score' => 20,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'findings' => [],
            'recommendations' => [],
        ]);

        $token = $this->createSiteToken($admin, 100);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/geo-diagnosis/dashboard')
            ->assertOk();

        // 只看到 site_id=100 的数据
        $data = $response->json('data');
        $this->assertSame(1, $data['total_runs']);
        $this->assertSame(75.0, (float) $data['average_score']);
    }

    public function test_latest_returns_null_for_other_site_run(): void
    {
        $admin = $this->createAdmin('latest_isolation');
        $article = $this->createArticle();

        // 只有 site_id=200 的诊断
        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'site_id' => 200,
            'total_score' => 90,
            'content_structure_score' => 25,
            'knowledge_alignment_score' => 25,
            'technical_geo_score' => 20,
            'ai_visibility_score' => 20,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'findings' => [],
            'recommendations' => [],
        ]);

        // 用 site_id=100 的 Token 查询 → 应返回 null
        $token = $this->createSiteToken($admin, 100);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/latest")
            ->assertOk();

        $this->assertNull($response->json('data.run'));
    }

    public function test_history_filters_by_site_id(): void
    {
        $admin = $this->createAdmin('history_filter');
        $article = $this->createArticle();

        // site_id=100 有 2 条
        for ($i = 0; $i < 2; $i++) {
            GeoDiagnosisRun::query()->create([
                'article_id' => $article->id,
                'admin_id' => $admin->id,
                'site_id' => 100,
                'total_score' => 60 + $i * 10,
                'content_structure_score' => 15,
                'knowledge_alignment_score' => 15,
                'technical_geo_score' => 15,
                'ai_visibility_score' => 15,
                'status' => GeoDiagnosisRun::STATUS_COMPLETED,
                'started_at' => now(),
                'completed_at' => now(),
                'findings' => [],
                'recommendations' => [],
            ]);
        }

        // site_id=200 有 3 条（不应出现在 100 的历史中）
        for ($i = 0; $i < 3; $i++) {
            GeoDiagnosisRun::query()->create([
                'article_id' => $article->id,
                'admin_id' => $admin->id,
                'site_id' => 200,
                'total_score' => 80,
                'content_structure_score' => 20,
                'knowledge_alignment_score' => 20,
                'technical_geo_score' => 20,
                'ai_visibility_score' => 20,
                'status' => GeoDiagnosisRun::STATUS_COMPLETED,
                'started_at' => now(),
                'completed_at' => now(),
                'findings' => [],
                'recommendations' => [],
            ]);
        }

        $token = $this->createSiteToken($admin, 100);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/history")
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame(2, $response->json('data.pagination.total'));
    }

    // ── Fail-closed：未绑定 Token + 缺少 Header → 422 ──

    public function test_unbound_token_without_header_returns_422(): void
    {
        $admin = $this->createAdmin('unbound_no_header');
        $token = $this->createUnboundToken($admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/geo-diagnosis/dashboard')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'tenant_required');
    }

    // ── Fail-closed：未绑定 Token + 有效 Header → 通过 ──

    public function test_unbound_token_with_valid_header_resolves_tenant(): void
    {
        $admin = $this->createAdmin('unbound_with_header');
        $article = $this->createArticle();

        GeoDiagnosisRun::query()->create([
            'article_id' => $article->id,
            'admin_id' => $admin->id,
            'site_id' => 42,
            'total_score' => 88,
            'content_structure_score' => 22,
            'knowledge_alignment_score' => 22,
            'technical_geo_score' => 22,
            'ai_visibility_score' => 22,
            'status' => GeoDiagnosisRun::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'findings' => [],
            'recommendations' => [],
        ]);

        $token = $this->createUnboundToken($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Site-Id', '42')
            ->getJson('/api/v1/geo-diagnosis/dashboard')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total_runs'));
    }

    // ── Fail-closed：Token 绑定 site_id=100，Header 传 200 → 403 ──

    public function test_bound_token_with_mismatched_header_returns_403(): void
    {
        $admin = $this->createAdmin('mismatch');
        $token = $this->createSiteToken($admin, 100);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Site-Id', '200')
            ->getJson('/api/v1/geo-diagnosis/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_mismatch');
    }

    // ── 无 Token → 401 ──

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/api/v1/geo-diagnosis/dashboard')
            ->assertUnauthorized();
    }
}
