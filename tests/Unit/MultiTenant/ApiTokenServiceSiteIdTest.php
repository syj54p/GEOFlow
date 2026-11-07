<?php

declare(strict_types=1);

namespace Tests\Unit\MultiTenant;

use App\Models\Admin;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase            ;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ApiTokenService 多租户相关单元测试。
 *
 * 覆盖：
 *   - createToken 绑定 site_id
 *   - createToken 拒绝无效 site_id（≤0）
 *   - hydrate 输出包含 site_id
 *   - listTokens 返回 site_id 字段
 */
class ApiTokenServiceSiteIdTest extends TestCase
{
    use RefreshDatabase;

    private ApiTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ApiTokenService::class);
    }

    private function createActiveAdmin(string $username = 'token_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret',
            'email' => "{$username}@example.com",
            'display_name' => 'Token Test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_create_token_binds_site_id(): void
    {
        $admin = $this->createActiveAdmin();

        $result = $this->service->createToken(
            name: 'niucloud-bridge',
            scopes: ['geo-diagnosis:read', 'geo-diagnosis:write'],
            adminId: $admin->id,
            siteId: 100000,
        );

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('record', $result);
        $this->assertSame(100000, $result['record']['site_id']);

        // 数据库中确认
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Admin::class,
            'tokenable_id' => $admin->id,
            'site_id' => 100000,
        ]);
    }

    public function test_create_token_without_site_id_leaves_it_null(): void
    {
        $admin = $this->createActiveAdmin('no_site_admin');

        $result = $this->service->createToken(
            name: 'global-token',
            scopes: ['catalog:read'],
            adminId: $admin->id,
        );

        $this->assertNull($result['record']['site_id']);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'site_id' => null,
        ]);
    }

    public function test_create_token_rejects_zero_site_id(): void
    {
        $admin = $this->createActiveAdmin('zero_site');

        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionCode(422);

        $this->service->createToken(
            name: 'bad-token',
            scopes: ['catalog:read'],
            adminId: $admin->id,
            siteId: 0,
        );
    }

    public function test_create_token_rejects_negative_site_id(): void
    {
        $admin = $this->createActiveAdmin('neg_site');

        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionCode(422);

        $this->service->createToken(
            name: 'bad-token',
            scopes: ['catalog:read'],
            adminId: $admin->id,
            siteId: -1,
        );
    }

    public function test_hydrate_outputs_site_id(): void
    {
        $admin = $this->createActiveAdmin('hydrate_admin');
        $tokenResult = $admin->createToken('hydrate-test', ['geo-diagnosis:read']);
        $tokenResult->accessToken->forceFill(['site_id' => 42])->save();

        $plain = $tokenResult->plainTextToken;
        $record = $this->service->getActiveTokenByPlaintext($plain);

        $this->assertNotNull($record);
        $this->assertSame(42, $record['site_id']);
    }

    public function test_list_tokens_includes_site_id(): void
    {
        $admin = $this->createActiveAdmin('list_admin');

        $this->service->createToken('t1', ['catalog:read'], $admin->id, siteId: 100);
        $this->service->createToken('t2', ['catalog:read'], $admin->id, siteId: 200);
        $this->service->createToken('t3', ['catalog:read'], $admin->id);

        $tokens = $this->service->listTokens();

        $this->assertGreaterThanOrEqual(3, count($tokens));

        $siteIds = array_column($tokens, 'site_id');
        $this->assertContains(100, $siteIds);
        $this->assertContains(200, $siteIds);
        $this->assertContains(null, $siteIds);
    }

    public function test_tenant_scopes_are_registered(): void
    {
        $scopes = $this->service->getAvailableScopes();

        $this->assertContains('geo-diagnosis:read', $scopes);
        $this->assertContains('geo-diagnosis:write', $scopes);
        $this->assertContains('brand-monitor:read', $scopes);
        $this->assertContains('brand-monitor:write', $scopes);
    }
}
