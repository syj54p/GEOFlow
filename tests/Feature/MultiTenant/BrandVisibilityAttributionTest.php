<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Models\BrandKeyword;
use App\Models\BrandMonitorResult;
use App\Models\BrandPlatform;
use App\Services\GeoFlow\BrandMonitor\BrandVisibilityService;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\PlatformAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BrandVisibilityService 多租户数据归属单元测试。
 *
 * 覆盖：
 *   - refreshVisibility 结果 site_id 取关键词自身归属
 *   - 平台配对规则：NULL=全局 + 同 site_id=私有
 *   - getVisibilityData 按 site_id 过滤关键词
 */
class BrandVisibilityAttributionTest extends TestCase
{
    use RefreshDatabase;

    private BrandVisibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BrandVisibilityService();
    }

    public function test_refresh_visibility_attributes_result_to_keyword_site_id(): void
    {
        // site_id=100 的关键词
        $keyword = BrandKeyword::query()->create([
            'site_id' => 100,
            'keyword' => 'brand-alpha',
            'is_active' => true,
        ]);

        // 全局平台（site_id=NULL）
        $platform = BrandPlatform::query()->create([
            'site_id' => null,
            'name' => 'Doubao',
            'code' => 'doubao',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        // Mock 适配器
        $adapter = $this->createMock(PlatformAdapterInterface::class);
        $adapter->method('checkKeywordRank')
            ->willReturn([
                'rank' => 5,
                'visibility_score' => 85.5,
                'in_top10' => true,
            ]);

        $count = $this->service->refreshVisibility(
            ['doubao' => $adapter],
            100,  // 传入 siteId=100
        );

        $this->assertSame(1, $count);

        // 结果 site_id 应取关键词自身的 site_id=100
        $this->assertDatabaseHas('brand_monitor_results', [
            'keyword_id' => $keyword->id,
            'platform_id' => $platform->id,
            'site_id' => 100,
            'search_rank' => 5,
        ]);
    }

    public function test_platform_pairing_global_serves_all_keywords(): void
    {
        // 两个不同站点的关键词
        BrandKeyword::query()->create(['site_id' => 100, 'keyword' => 'brand-100', 'is_active' => true]);
        BrandKeyword::query()->create(['site_id' => 200, 'keyword' => 'brand-200', 'is_active' => true]);

        // 全局平台
        BrandPlatform::query()->create([
            'site_id' => null,
            'name' => 'Doubao',
            'code' => 'doubao',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        $adapter = $this->createMock(PlatformAdapterInterface::class);
        $adapter->method('checkKeywordRank')
            ->willReturn(['rank' => 1, 'visibility_score' => 100, 'in_top10' => true]);

        // 不传 siteId → 取所有 active 关键词
        $count = $this->service->refreshVisibility(['doubao' => $adapter]);

        $this->assertSame(2, $count);

        // 各自归属自己的 site_id
        $this->assertDatabaseHas('brand_monitor_results', ['site_id' => 100, 'keyword_id' => 1]);
        $this->assertDatabaseHas('brand_monitor_results', ['site_id' => 200, 'keyword_id' => 2]);
    }

    public function test_platform_pairing_site_specific_only_serves_same_site(): void
    {
        BrandKeyword::query()->create(['site_id' => 100, 'keyword' => 'brand-100', 'is_active' => true]);
        BrandKeyword::query()->create(['site_id' => 200, 'keyword' => 'brand-200', 'is_active' => true]);

        // site_id=100 私有平台
        BrandPlatform::query()->create([
            'site_id' => 100,
            'name' => 'Private Platform',
            'code' => 'private100',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        $adapter = $this->createMock(PlatformAdapterInterface::class);
        $adapter->method('checkKeywordRank')
            ->willReturn(['rank' => 3, 'visibility_score' => 70, 'in_top10' => true]);

        $count = $this->service->refreshVisibility(['private100' => $adapter]);

        // 只有 site_id=100 的关键词匹配到平台
        $this->assertSame(1, $count);
        $this->assertDatabaseHas('brand_monitor_results', ['site_id' => 100]);
        $this->assertDatabaseMissing('brand_monitor_results', ['site_id' => 200]);
    }

    public function test_visibility_data_filters_by_site_id(): void
    {
        BrandKeyword::query()->create(['site_id' => 100, 'keyword' => 'visible-100', 'is_active' => true]);
        BrandKeyword::query()->create(['site_id' => 200, 'keyword' => 'visible-200', 'is_active' => true]);
        BrandKeyword::query()->create(['site_id' => 100, 'keyword' => 'another-100', 'is_active' => true]);

        BrandPlatform::query()->create([
            'site_id' => null,
            'name' => 'Doubao',
            'code' => 'doubao',
            'is_enabled' => true,
            'api_config' => [],
        ]);

        $data = $this->service->getVisibilityData(null, null, 100);

        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertContains($item['keyword'], ['visible-100', 'another-100']);
        }
    }
}
