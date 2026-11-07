<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Models\Admin;
use App\Models\BrandKeyword;
use App\Models\BrandMonitorResult;
use App\Models\BrandPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 品牌监测 API 多租户隔离集成测试。
 *
 * 覆盖：
 *   - 关键词 CRUD 按 site_id 隔离
 *   - 平台列表按 site_id 过滤（NULL=全局 + 同站点私有）
 *   - 删除关键词时跨站点不可见
 *   - scope 鉴权（brand-monitor:read / write）
 */
class BrandMonitorApiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(string $username = 'brand_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret',
            'email' => "{$username}@example.com",
            'display_name' => 'Brand Test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createSiteToken(Admin $admin, int $siteId, array $scopes = ['brand-monitor:read', 'brand-monitor:write']): string
    {
        $token = $admin->createToken('brand-token', $scopes);
        $token->accessToken->forceFill(['site_id' => $siteId])->save();

        return $token->plainTextToken;
    }

    // ── 关键词隔离 ──

    public function test_keywords_list_filtered_by_site_id(): void
    {
        $admin = $this->createAdmin('kw_list');

        BrandKeyword::query()->create(['site_id' => 100, 'keyword' => 'site100-brand', 'is_active' => true]);
        BrandKeyword::query()->create(['site_id' => 200, 'keyword' => 'site200-brand', 'is_active' => true]);
        BrandKeyword::query()->create(['site_id' => 100, 'keyword' => 'site100-another', 'is_active' => true]);

        $token = $this->createSiteToken($admin, 100);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/brand-monitor/keywords')
            ->assertOk();

        $keywords = $response->json('data');
        $this->assertCount(2, $keywords);
        foreach ($keywords as $kw) {
            $this->assertSame(100, $kw['site_id']);
        }
    }

    public function test_add_keyword_creates_with_site_id(): void
    {
        $admin = $this->createAdmin('kw_add');
        $token = $this->createSiteToken($admin, 100);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/brand-monitor/keywords', ['keyword' => 'new-brand'])
            ->assertCreated()
            ->assertJsonPath('data.keyword', 'new-brand')
            ->assertJsonPath('data.site_id', 100);

        $this->assertDatabaseHas('brand_keywords', [
            'keyword' => 'new-brand',
            'site_id' => 100,
            'is_active' => true,
        ]);
    }

    public function test_remove_keyword_rejects_cross_site(): void
    {
        $admin = $this->createAdmin('kw_remove');

        // 关键词属于 site_id=200
        $keyword = BrandKeyword::query()->create([
            'site_id' => 200,
            'keyword' => 'other-site-kw',
            'is_active' => true,
        ]);

        // 用 site_id=100 的 Token 尝试删除 → 404
        $token = $this->createSiteToken($admin, 100);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/brand-monitor/keywords/{$keyword->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        // 关键词仍然存在
        $this->assertDatabaseHas('brand_keywords', ['id' => $keyword->id]);
    }

    public function test_remove_keyword_allows_same_site(): void
    {
        $admin = $this->createAdmin('kw_remove_ok');

        $keyword = BrandKeyword::query()->create([
            'site_id' => 100,
            'keyword' => 'my-site-kw',
            'is_active' => true,
        ]);

        $token = $this->createSiteToken($admin, 100);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/brand-monitor/keywords/{$keyword->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('brand_keywords', ['id' => $keyword->id]);
    }

    // ── 平台隔离 ──

    public function test_platforms_list_includes_global_and_site_specific(): void
    {
        $admin = $this->createAdmin('plat_list');

        // 全局平台（site_id=NULL）
        BrandPlatform::query()->create([
            'site_id' => null,
            'name' => 'Global Platform',
            'code' => 'global',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        // site_id=100 私有平台
        BrandPlatform::query()->create([
            'site_id' => 100,
            'name' => 'Site 100 Platform',
            'code' => 'site100',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        // site_id=200 私有平台（不应出现在 100 的视图中）
        BrandPlatform::query()->create([
            'site_id' => 200,
            'name' => 'Site 200 Platform',
            'code' => 'site200',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        $token = $this->createSiteToken($admin, 100);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/brand-monitor/platforms')
            ->assertOk();

        $platforms = $response->json('data');
        $this->assertCount(2, $platforms);

        $codes = array_column($platforms, 'code');
        $this->assertContains('global', $codes);
        $this->assertContains('site100', $codes);
        $this->assertNotContains('site200', $codes);
    }

    // ── Scope 鉴权 ──

    public function test_visibility_requires_brand_monitor_read_scope(): void
    {
        $admin = $this->createAdmin('scope_read');
        $token = $admin->createToken('wrong-scope', ['catalog:read'])->plainTextToken;
        $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        $tokenModel->forceFill(['site_id' => 100])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/brand-monitor/visibility')
            ->assertForbidden();
    }

    public function test_add_keyword_requires_brand_monitor_write_scope(): void
    {
        $admin = $this->createAdmin('scope_write');
        $token = $admin->createToken('read-only', ['brand-monitor:read'])->plainTextToken;
        $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        $tokenModel->forceFill(['site_id' => 100])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/brand-monitor/keywords', ['keyword' => 'test'])
            ->assertForbidden();
    }

    // ── Fail-closed：无 Header + 未绑定 Token → 422 ──

    public function test_unbound_token_without_header_returns_422(): void
    {
        $admin = $this->createAdmin('unbound_422');
        $token = $admin->createToken('unbound', ['brand-monitor:read'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/brand-monitor/keywords')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'tenant_required');
    }

    // ── Fail-closed：Token 绑定与 Header 不一致 → 403 ──

    public function test_bound_token_with_mismatched_header_returns_403(): void
    {
        $admin = $this->createAdmin('mismatch_403');
        $token = $this->createSiteToken($admin, 100);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Site-Id', '999')
            ->getJson('/api/v1/brand-monitor/keywords')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_mismatch');
    }
}
