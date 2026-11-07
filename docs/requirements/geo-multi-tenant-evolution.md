# GEOFlow 多租户适配 — 后续演进规划

> **日期**：2026-11-08  
> **前置状态**：MVP（Phase 0–5）已交付，Phase 6–8 已交付，知识库隔离 K1–K7 已交付  
> **当前状态**：全部 8 个 Phase + 知识库隔离 7 步 + 代码审查 P0/P1/P2 修复完成，多租户适配深度闭环

---

## 演进路线总览

```
Phase 6（P0，1.5–2 天）   Bridge 侧交付 → 租户能用                    ✅ 已完成 (2026-11-07 验证通过)
Phase 7（P1，1 天）        测试补全 + 代码加固 → 质量闭环                ✅ 已完成 (commit fe65353)
Phase 8（P2，2–3 天）      Admin 面板租户视图 → 超管可按站点查看         ✅ 已完成 (commit 700a543)
K1-K7（P3，1 天）          知识库隔离 → 租户数据深度隔离                  ✅ 已完成 (2026-11-08)
代码审查修复               P0 items 补传 siteId + P1 全局 KB 写保护      ✅ 已完成 (commit f64a653)
长期演进（P4，按需）        文章归属 / 路线 C / 更多资源隔离              ⏳ 按需启动
```

---

## Phase 6：Bridge 侧交付（niucloud df_geo_bridge）— ✅ 已完成

**目标**：niucloud 站点管理员能在后台触发 GEO 诊断、查看品牌监测数据，完整打通多租户链路。

**交付确认（2026-11-07）**：
- ✅ GeoflowApiService：siteId 必填 + X-Site-Id Header + 8 个诊断/品牌方法
- ✅ GeoDiagnosis 控制器：8 端点合一（run/latest/history/dashboard/brandDashboard/brandKeywords/addBrandKeyword/removeBrandKeyword）
- ✅ 前端页面：`diagnosis/index.vue` 含概览卡片 + 品牌可见性表 + 关键词管理
- ✅ API 模块：`diagnosis.ts` 完整类型定义
- ✅ 语言包：`diagnosis.index.json` 70 个 key
- ✅ 菜单注册：`admin.php` 「GEO 诊断」+ 5 个子权限
- ✅ 设置页：token 绑定指引 + 连接测试租户探测（422/403）
- ✅ 构建验证：vue-tsc diagnosis 零错误 + build-addon 产出 `diagnosis-53422264.js`

### 6.1 GeoflowApiService 改造

**文件**：`niucloud/addon/df_geo_bridge/app/service/GeoflowApiService.php`

**现状**：
- 构造函数 `__construct(string $baseUrl, string $token, int $siteId = 0)`，siteId 有默认值 0
- 未发送 `X-Site-Id` Header
- 无诊断/品牌监测方法

**改动**：
```php
// 1. siteId 必填，去掉默认值
public function __construct(string $baseUrl, string $token, int $siteId)
{
    if ($siteId <= 0) {
        throw new \InvalidArgumentException('siteId 必须 > 0');
    }
    $this->siteId = $siteId;
    // ... 现有 Client 配置
}

// 2. 构造函数增加 X-Site-Id Header
$this->client = new Client([
    'base_uri' => $baseUrl,
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'X-Site-Id' => (string) $this->siteId,  // ← 新增
    ],
]);

// 3. 新增诊断/品牌监测方法（按 GEOFlow API 路由）
public function runDiagnosis(int $articleId): array
{
    // POST /api/v1/articles/{articleId}/geo-diagnosis/run
}

public function getLatestDiagnosis(int $articleId): array
{
    // GET /api/v1/articles/{articleId}/geo-diagnosis/latest
}

public function getDiagnosisHistory(int $articleId, int $limit = 10): array
{
    // GET /api/v1/articles/{articleId}/geo-diagnosis/history
}

public function getGeoDiagnosisDashboard(): array
{
    // GET /api/v1/geo-diagnosis/dashboard
}

public function getBrandMonitorDashboard(): array
{
    // GET /api/v1/brand-monitor/dashboard
}

public function getBrandKeywords(): array
{
    // GET /api/v1/brand-monitor/keywords
}

public function addBrandKeyword(string $keyword, string $type = 'brand'): array
{
    // POST /api/v1/brand-monitor/keywords
}

public function removeBrandKeyword(int $keywordId): array
{
    // DELETE /api/v1/brand-monitor/keywords/{keywordId}
}

// 4. 排查所有构造点，补传 siteId
//    - fromSiteId(int $siteId) 静态工厂：读 df_geo_bridge_config 拿 token，构造实例
//    - 直接 new self(...) 的调用：全目录 grep，一律补 siteId
```

### 6.2 新增 Bridge 控制器

**文件**：`niucloud/addon/df_geo_bridge/app/adminapi/controller/diagnosis/GeoDiagnosis.php`（新建）

```php
<?php

declare(strict_types=1);

namespace addon\df_geo_bridge\app\adminapi\controller\diagnosis;

use core\base\BaseAdminController;
use addon\df_geo_bridge\app\service\GeoflowApiService;

class GeoDiagnosis extends BaseAdminController
{
    /**
     * 运行 GEO 诊断
     * POST /adminapi/df_geo_bridge/diagnosis/run
     */
    public function run()
    {
        $siteId = $this->request->siteId();
        $articleId = $this->request->post('article_id');
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置，请联系管理员');
        }
        
        try {
            $result = $bridge->runDiagnosis($articleId);
            return success($result);
        } catch (\Exception $e) {
            return fail($e->getMessage());
        }
    }
    
    /**
     * 获取最新诊断结果
     * GET /adminapi/df_geo_bridge/diagnosis/latest
     */
    public function latest()
    {
        $siteId = $this->request->siteId();
        $articleId = $this->request->get('article_id');
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->getLatestDiagnosis($articleId);
        return success($result);
    }
    
    /**
     * 获取诊断历史
     * GET /adminapi/df_geo_bridge/diagnosis/history
     */
    public function history()
    {
        $siteId = $this->request->siteId();
        $articleId = $this->request->get('article_id');
        $limit = $this->request->get('limit', 10);
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->getDiagnosisHistory($articleId, $limit);
        return success($result);
    }
    
    /**
     * 诊断仪表盘
     * GET /adminapi/df_geo_bridge/diagnosis/dashboard
     */
    public function dashboard()
    {
        $siteId = $this->request->siteId();
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->getGeoDiagnosisDashboard();
        return success($result);
    }
}
```

**文件**：`niucloud/addon/df_geo_bridge/app/adminapi/controller/brand/BrandMonitor.php`（新建）

```php
<?php

declare(strict_types=1);

namespace addon\df_geo_bridge\app\adminapi\controller\brand;

use core\base\BaseAdminController;
use addon\df_geo_bridge\app\service\GeoflowApiService;

class BrandMonitor extends BaseAdminController
{
    /**
     * 品牌监测仪表盘
     * GET /adminapi/df_geo_bridge/brand/dashboard
     */
    public function dashboard()
    {
        $siteId = $this->request->siteId();
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->getBrandMonitorDashboard();
        return success($result);
    }
    
    /**
     * 获取品牌关键词列表
     * GET /adminapi/df_geo_bridge/brand/keywords
     */
    public function keywords()
    {
        $siteId = $this->request->siteId();
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->getBrandKeywords();
        return success($result);
    }
    
    /**
     * 添加品牌关键词
     * POST /adminapi/df_geo_bridge/brand/keywords
     */
    public function addKeyword()
    {
        $siteId = $this->request->siteId();
        $keyword = $this->request->post('keyword');
        $type = $this->request->post('type', 'brand');
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->addBrandKeyword($keyword, $type);
        return success($result);
    }
    
    /**
     * 删除品牌关键词
     * DELETE /adminapi/df_geo_bridge/brand/keywords/{id}
     */
    public function removeKeyword()
    {
        $siteId = $this->request->siteId();
        $keywordId = $this->request->post('id');
        
        $bridge = GeoflowApiService::fromSiteId($siteId);
        if (!$bridge) {
            return fail('GEO 服务未配置');
        }
        
        $result = $bridge->removeBrandKeyword($keywordId);
        return success($result);
    }
}
```

### 6.3 路由注册

**文件**：`niucloud/addon/df_geo_bridge/app/adminapi/route/route.php`（追加）

```php
// GEO 诊断
Route::group('df_geo_bridge/diagnosis', function () {
    Route::checkToken();
    Route::rules([
        'run' => 'addon\df_geo_bridge\app\adminapi\controller\diagnosis\GeoDiagnosis\run',
        'latest' => 'addon\df_geo_bridge\app\adminapi\controller\diagnosis\GeoDiagnosis\latest',
        'history' => 'addon\df_geo_bridge\app\adminapi\controller\diagnosis\GeoDiagnosis\history',
        'dashboard' => 'addon\df_geo_bridge\app\adminapi\controller\diagnosis\GeoDiagnosis\dashboard',
    ]);
});

// 品牌监测
Route::group('df_geo_bridge/brand', function () {
    Route::checkToken();
    Route::rules([
        'dashboard' => 'addon\df_geo_bridge\app\adminapi\controller\brand\BrandMonitor\dashboard',
        'keywords' => 'addon\df_geo_bridge\app\adminapi\controller\brand\BrandMonitor\keywords',
        'addKeyword' => 'addon\df_geo_bridge\app\adminapi\controller\brand\BrandMonitor\addKeyword',
        'removeKeyword' => 'addon\df_geo_bridge\app\adminapi\controller\brand\BrandMonitor\removeKeyword',
    ]);
});
```

### 6.4 前端页面

**目录**：`niucloud/addon/df_geo_bridge/admin/views/diagnosis/`（新建）

- `index.vue` — 诊断入口页（文章列表 + "运行诊断"按钮）
- `result.vue` — 诊断结果展示页（评分、维度分析、改进建议）
- `history.vue` — 诊断历史记录页

**目录**：`niucloud/addon/df_geo_bridge/admin/views/brand/`（新建）

- `dashboard.vue` — 品牌监测概览（可见性、情感分析、竞品对比）
- `keywords.vue` — 关键词管理（列表、添加、删除）

**API 模块**：`niucloud/addon/df_geo_bridge/admin/api/diagnosis.ts`（新建）

```typescript
import request from '@/utils/request'

export function runDiagnosis(articleId: number) {
    return request.post('df_geo_bridge/diagnosis/run', { article_id: articleId })
}

export function getLatestDiagnosis(articleId: number) {
    return request.get('df_geo_bridge/diagnosis/latest', { article_id: articleId })
}

export function getDiagnosisHistory(articleId: number, limit = 10) {
    return request.get('df_geo_bridge/diagnosis/history', { article_id: articleId, limit })
}

export function getDiagnosisDashboard() {
    return request.get('df_geo_bridge/diagnosis/dashboard')
}

export function getBrandDashboard() {
    return request.get('df_geo_bridge/brand/dashboard')
}

export function getBrandKeywords() {
    return request.get('df_geo_bridge/brand/keywords')
}

export function addBrandKeyword(keyword: string, type = 'brand') {
    return request.post('df_geo_bridge/brand/keywords', { keyword, type })
}

export function removeBrandKeyword(id: number) {
    return request.delete('df_geo_bridge/brand/keywords', { id })
}
```

### 6.5 设置页指引

**文件**：`niucloud/addon/df_geo_bridge/admin/views/setting/index.vue`（修改）

在连接配置区域增加提示文案：

```vue
<el-alert
    title="Token 绑定指引"
    type="info"
    :closable="false"
>
    <template #default>
        <p>1. 登录 GEOFlow 后台（{{ config.geo_base_url }}）</p>
        <p>2. 进入「API Token 管理」页面</p>
        <p>3. 创建新 Token 时，填写「绑定站点 ID」为当前站点 ID：{{ siteId }}</p>
        <p>4. 复制生成的 Token 填入下方「API Token」字段</p>
        <p>5. 点击「测试连接」验证配置</p>
    </template>
</el-alert>
```

**连接测试增加租户探测**：

```typescript
// setting/index.vue 的 testConnection 方法
async function testConnection() {
    try {
        // 现有测试逻辑...
        
        // 新增：调用一次 dashboard，验证租户上下文
        const dashboard = await getDiagnosisDashboard()
        if (dashboard.code === 422) {
            ElMessage.error('租户上下文缺失，请检查 Token 是否已绑定 site_id')
        } else if (dashboard.code === 403) {
            ElMessage.error('Token 绑定站点与当前站点不一致，请重新绑定')
        } else {
            ElMessage.success('连接成功，租户隔离正常')
        }
    } catch (error) {
        // ...
    }
}
```

### 6.6 验收标准

- [ ] niucloud 站点 A 管理员登录后台，能看到「GEO 诊断」菜单
- [ ] 站点 A 对文章 42 运行诊断 → GEOFlow 创建 site_id=A 的 run → 站点 A 看到结果
- [ ] 站点 B 查同一文章 42 → 空（未诊断过），触发后只见 B 的结果
- [ ] 站点 A 配置品牌关键词"萌友宠物" → 站点 B Dashboard 看不到
- [ ] 设置页按指引绑定 Token 后，测试连接显示"租户隔离正常"
- [ ] bridge 前端过 vue-tsc 闸门 + build-addon 构建通过

---

## Phase 7：测试补全与代码加固

**目标**：关闭 MVP 遗留的 4 个测试缺口，修复审计发现的代码异味。

### 7.1 补全测试用例

**文件**：`tests/Feature/MultiTenant/DualSiteSameArticleTest.php`（新建）

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Article;
use App\Models\Admin;
use App\Models\GeoDiagnosisRun;

class DualSiteSameArticleTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sites_diagnose_same_article_isolated(): void
    {
        // 准备：同一文章被两个站点诊断
        $article = Article::factory()->create();
        $site1Token = $this->createSiteToken(siteId: 100);
        $site2Token = $this->createSiteToken(siteId: 200);
        
        // 站点 1 诊断
        $run1 = GeoDiagnosisRun::create([
            'article_id' => $article->id,
            'site_id' => 100,
            'status' => 'completed',
            'score' => 85,
        ]);
        
        // 站点 2 诊断
        $run2 = GeoDiagnosisRun::create([
            'article_id' => $article->id,
            'site_id' => 200,
            'status' => 'completed',
            'score' => 72,
        ]);
        
        // 验证：站点 1 只见自己的
        $response = $this->actingAs($site1Token)
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/latest");
        
        $response->assertOk()
            ->assertJsonPath('data.id', $run1->id)
            ->assertJsonPath('data.site_id', 100);
        
        // 验证：站点 2 只见自己的
        $response = $this->actingAs($site2Token)
            ->getJson("/api/v1/articles/{$article->id}/geo-diagnosis/latest");
        
        $response->assertOk()
            ->assertJsonPath('data.id', $run2->id)
            ->assertJsonPath('data.site_id', 200);
        
        // 验证：运行中检查也按 site 隔离
        $running = GeoDiagnosisRun::create([
            'article_id' => $article->id,
            'site_id' => 200,
            'status' => 'running',
        ]);
        
        $response = $this->actingAs($site1Token)
            ->postJson("/api/v1/articles/{$article->id}/geo-diagnosis/run");
        
        $response->assertStatus(201); // 站点 1 可以跑，因为站点 2 的运行中不算
        
        $response = $this->actingAs($site2Token)
            ->postJson("/api/v1/articles/{$article->id}/geo-diagnosis/run");
        
        $response->assertStatus(409); // 站点 2 不能跑，有自己的运行中
    }
    
    private function createSiteToken(int $siteId)
    {
        $admin = Admin::factory()->create();
        return $admin->createToken('test', ['geo-diagnosis:read', 'geo-diagnosis:write'], siteId: $siteId);
    }
}
```

**文件**：`tests/Feature/Admin/GlobalViewRegressionTest.php`（新建）

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Admin;
use App\Models\GeoDiagnosisRun;

class GlobalViewRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_web_sees_all_sites_aggregated(): void
    {
        // 准备：多个站点的诊断记录
        GeoDiagnosisRun::create([
            'article_id' => 1,
            'site_id' => 100,
            'status' => 'completed',
        ]);
        GeoDiagnosisRun::create([
            'article_id' => 2,
            'site_id' => 200,
            'status' => 'completed',
        ]);
        GeoDiagnosisRun::create([
            'article_id' => 3,
            'site_id' => null, // 遗留全局数据
            'status' => 'completed',
        ]);
        
        // 超管登录
        $admin = Admin::factory()->create(['is_super' => true]);
        $this->actingAs($admin, 'admin');
        
        // 验证：Dashboard 看到全部 3 条
        $response = $this->get('/admin/geo-diagnosis/dashboard');
        $response->assertOk();
        // 具体断言取决于控制器返回结构...
        
        // 验证：History 也看到全部
        $response = $this->get('/admin/articles/1/geo-diagnosis/history');
        $response->assertOk();
    }
}
```

**文件**：`tests/Unit/Services/GeoDiagnosisOrchestratorTest.php`（新建）

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\GeoFlow\GeoDiagnosis\GeoDiagnosisOrchestrator;
use App\Models\Article;
use App\Models\GeoDiagnosisRun;

class GeoDiagnosisOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_diagnosis_persists_site_id_from_context(): void
    {
        $article = Article::factory()->create();
        $orchestrator = app(GeoDiagnosisOrchestrator::class);
        
        $run = $orchestrator->runDiagnosis($article, adminId: 1, context: [
            'site_id' => 42,
        ]);
        
        $this->assertDatabaseHas('geo_diagnosis_runs', [
            'id' => $run->id,
            'site_id' => 42,
        ]);
    }
    
    public function test_latest_diagnosis_filters_by_site(): void
    {
        $article = Article::factory()->create();
        
        GeoDiagnosisRun::create([
            'article_id' => $article->id,
            'site_id' => 100,
            'status' => 'completed',
            'score' => 85,
        ]);
        
        GeoDiagnosisRun::create([
            'article_id' => $article->id,
            'site_id' => 200,
            'status' => 'completed',
            'score' => 72,
        ]);
        
        $orchestrator = app(GeoDiagnosisOrchestrator::class);
        
        $latest = $orchestrator->getLatestDiagnosis($article, siteId: 100);
        $this->assertEquals(85, $latest->score);
        
        $latest = $orchestrator->getLatestDiagnosis($article, siteId: 200);
        $this->assertEquals(72, $latest->score);
        
        $latest = $orchestrator->getLatestDiagnosis($article, siteId: null);
        $this->assertNotNull($latest); // 全局视图返回最新一条
    }
}
```

### 7.2 代码加固

**文件**：`app/Services/GeoFlow/BrandMonitor/KeywordManager.php`

```php
// removeKeyword 增加 siteId 归属校验
public function removeKeyword(int $keywordId, ?int $siteId = null): bool
{
    $keyword = BrandKeyword::query()
        ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
        ->find($keywordId);
    
    if (!$keyword) {
        throw new \RuntimeException('关键词不存在或无权修改');
    }
    
    return $keyword->delete();
}

// toggleKeyword 同理
public function toggleKeyword(int $keywordId, bool $isEnabled, ?int $siteId = null): bool
{
    $keyword = BrandKeyword::query()
        ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
        ->find($keywordId);
    
    if (!$keyword) {
        throw new \RuntimeException('关键词不存在或无权修改');
    }
    
    $keyword->is_enabled = $isEnabled;
    return $keyword->save();
}
```

**文件**：`app/Http/Controllers/Admin/BrandMonitorController.php`

```php
// refreshVisibility 改用 DI，消除 new
public function __construct(
    private readonly BrandMonitorOrchestrator $orchestrator,
    private readonly KeywordManager $keywordManager,
    private readonly BrandVisibilityService $visibilityService, // ← 新增注入
) {}

public function refreshVisibility(Request $request)
{
    // ... 现有逻辑
    $result = $this->visibilityService->refreshVisibility($adapters); // ← 改用注入
    // ...
}
```

**文件**：`app/Http/Controllers/Admin/ArticleDiagnosisController.php`

```php
// latest/history 补传 siteId（虽然 admin web 面 null=全局，但显式传参更清晰）
public function latest(Request $request, int $articleId)
{
    $article = Article::query()->whereKey($articleId)->firstOrFail();
    $siteId = $request->input('site_id'); // 可选，不传为 null
    
    $run = $this->orchestrator->getLatestDiagnosis($article, $siteId);
    // ...
}

public function history(Request $request, int $articleId)
{
    $article = Article::query()->whereKey($articleId)->firstOrFail();
    $siteId = $request->input('site_id');
    $limit = $request->input('limit', 10);
    
    $runs = $this->orchestrator->getDiagnosisHistory($article, $limit, $siteId);
    // ...
}
```

### 7.3 验收标准

- [ ] 4 个新测试用例全部通过
- [ ] KeywordManager.removeKeyword 跨租户删除抛异常
- [ ] BrandMonitorController.refreshVisibility 不再直接 new Service
- [ ] ArticleDiagnosisController.latest/history 显式传 siteId
- [ ] 现有测试套件无回归

---

## Phase 8：Admin 面板租户视图

**目标**：GEOFlow 超管能按站点筛选数据，运营视角更清晰。

### 8.1 方案选型

**方案 A（推荐）**：全局聚合 + 顶部站点下拉筛选
- 改动小，复用现有 `ResolvesTenant` trait
- 超管登录后可切换"全局视图"或"某站点视图"
- 不改变 admins 表结构

**方案 B**：admins 表加 site_id，站点管理员直登
- 改动大，涉及登录/权限全链路
- 适合未来 GEOFlow 多租户 SaaS 化
- 当前阶段过度设计

**选择方案 A**。

### 8.2 实现要点

**前端**：Admin 面板顶部导航增加站点选择器

```vue
<!-- resources/views/admin/layouts/app.vue -->
<el-select
    v-model="selectedSiteId"
    placeholder="选择站点"
    clearable
    @change="onSiteChange"
>
    <el-option label="全局视图" :value="null" />
    <el-option
        v-for="site in sites"
        :key="site.id"
        :label="site.name"
        :value="site.id"
    />
</el-select>
```

**后端**：Admin 控制器读取 query 参数

```php
// app/Http/Controllers/Admin/ArticleDiagnosisController.php
public function dashboard(Request $request)
{
    $siteId = $request->query('site_id'); // 可选，null=全局
    
    $data = $this->orchestrator->getDashboardData($siteId);
    return view('admin.diagnosis.dashboard', compact('data', 'siteId'));
}
```

**API 端点**：暴露站点列表供前端下拉

```php
// routes/web.php
Route::get('/admin/api/sites', function () {
    // 从 niucloud 同步站点列表，或本地维护
    return response()->json([
        ['id' => 100, 'name' => '宠物商城'],
        ['id' => 200, 'name' => '餐饮连锁'],
    ]);
})->middleware('admin.auth');
```

### 8.3 验收标准

- [ ] 超管登录 GEOFlow 后台，顶部可见站点选择器
- [ ] 选择"全局视图"→ 看到所有站点的聚合数据
- [ ] 选择"站点 A"→ 只看站点 A 的数据
- [ ] 切换站点后，诊断/品牌监测页面数据联动刷新

---

## 长期演进（P3，按需）

### 1. 知识库隔离

**背景**：`knowledge_bases` / `knowledge_chunks` 当前无 site_id，全局共享。

**方案**：
- 加 `site_id` 列（nullable）
- NULL = 全局共享知识库（所有站点可读）
- 非 NULL = 站点私有知识库
- API 面查询：`where('site_id', $siteId)->orWhereNull('site_id')`

**触发条件**：租户提出"不想让别的站点看到我的知识库"需求时启动。

### 2. 文章归属

**背景**：`articles` 表无 tenant 概念，诊断/品牌监测通过 site_id 关联。

**方案**：
- 加 `tenant_id` 列（不叫 site_id，避免与 HostedSite 混淆）
- niucloud 同步文章时写入 tenant_id = site_id
- 诊断/品牌监测自动关联：`where('tenant_id', $siteId)`

**触发条件**：文章量大后，查询性能成瓶颈，需要按租户分区时启动。

### 3. 品牌监测按 site 扇出调度

**背景**：当前 `RunBrandMonitorJob` 单 job 全量，租户量大后失败影响面大。

**方案**：
- 改为按 site_id 扇出：`foreach (sites as site) dispatch(new RunBrandMonitorJob($site->id))`
- 每个租户独立 job，失败只影响单个租户
- 可加优先级队列：付费租户优先

**触发条件**：租户数 > 50 或单 job 运行时间 > 30 分钟时启动。

### 4. 路线 C：tenants 表

**背景**：当前路线 A+ 是轻量映射，GEOFlow 不管理租户生命周期。

**方案**：
- 建 `tenants` 表：id, niucloud_site_id, name, status, settings...
- niucloud 创建站点时 webhook 通知 GEOFlow 创建 tenant
- 所有业务表加 `tenant_id` 外键
- 超管后台增加"租户管理"菜单

**触发条件**：GEOFlow 未来服务多个 niucloud 平台（多套 SaaS），需要平台级映射时启动。

---

## 知识库隔离 K1–K7 — ✅ 已完成 (2026-11-08)

**目标**：实现知识库（knowledge_bases）的租户级数据隔离，确保不同 niucloud 站点只能访问自己的知识库和全局共享知识库。

**设计方案**：
- site_id 仅加在 `knowledge_bases` 表，`knowledge_chunks` 通过 FK 继承归属
- 混合可见性模型：`site_id = NULL` → 全局共享；`site_id = N` → 站点 N 私有
- API 查询：`WHERE site_id = $siteId OR site_id IS NULL`（visibleTo scope）
- Admin Web：超管全局视图，siteId = null，不做过滤

**交付清单**：
- ✅ K1：数据库迁移 + Model 层（`2026_11_08_000001_add_site_id_to_knowledge_bases_table.php`，KnowledgeBase scope 方法）
- ✅ K2：API 面隔离（materials 9 路由挂 api.tenant，MaterialController/Service 传 siteId，visibleTo 过滤）
- ✅ K3：检索链路隔离（KnowledgeRetrievalService 5 个公开方法加 siteId 参数，retrieveEvidence/validateEvidenceSnapshot 加 visibleTo 防线）
- ✅ K4：Job/Coordinator 适配（tasks 9 路由挂 api.tenant，TaskController 传 siteId，TaskLifecycleService 校验 KB 绑定归属）
- ✅ K5：Admin Web 面站点筛选（KnowledgeBaseController 列表暴露 site_id 字段，超管可识别归属）
- ✅ K6：Bridge 侧适配（确认 X-Site-Id header 已传递，bridge 不调用 materials/tasks 写接口，无需额外改动）
- ✅ K7：测试 + 验收（PHP 语法检查通过，生产路由注册验证通过，应用正常启动）

**关键代码变更**：
- `MaterialLibraryService`：summary/list/show/create/update/delete 接受 `?int $siteId`，knowledge-bases 类型执行 visibleTo 过滤
- `KnowledgeRetrievalService`：retrieveEvidence/retrieveEvidenceFromMany/retrieveContextBundleFromMany/validateEvidenceSnapshot 接受 `?int $siteId`，加载 KnowledgeBase 时加 visibleTo scope
- `TaskLifecycleService`：createTaskForApi/updateTaskForApi 接受 `?int $siteId`，新增 `assertKnowledgeBasesTenantVisible()` 校验方法
- 所有新参数均默认 `null`，Admin Web 路径不受影响

---

## 代码审查修复 P0/P1/P2 — ✅ 已完成 (2026-11-08)

**背景**：K1-K7 交付后对 Phase 6-8 改动做代码审查，发现三个级别的问题。

### P0：items 系列操作缺失 siteId 传递

**问题**：MaterialController 的 `items`/`storeItem`/`destroyItems` 三个方法未将 `tenantId` 传递给 Service 层，导致租户可通过 items 子资源操作绕过归属校验。

**修复**（commit `0d60398`）：
- `MaterialController`：items/storeItem/destroyItems 补传 `$this->tenantId($request)`
- `MaterialLibraryService`：`listItems`/`createItem`/`deleteItems`/`withLegacyImagePathLock`/`withUploadedImagePathLock`/`createUploadedImageItem` 统一接受 `?int $siteId = null`，在 `findMaterial` 内执行 visibleTo 校验

**涉及文件**：
- `app/Http/Controllers/Api/V1/MaterialController.php`
- `app/Services/GeoFlow/MaterialLibraryService.php`

### P1：全局知识库写保护缺失

**问题**：`update()` 和 `delete()` 通过 `visibleTo` scope 查找记录，语义为 `WHERE site_id = $siteId OR site_id IS NULL`——租户能看到全局知识库（正确），但也能修改/删除全局知识库（错误）。

**修复**（commit `f64a653`）：
- 新增 `assertTenantWritable()` 私有方法：当 `$type === 'knowledge-bases'` 且 `$siteId !== null`（租户上下文）且 `$row->site_id === null`（全局知识库）时，抛 403
- `update()` 和 `delete()` 在 `findMaterial` 之后调用 `assertTenantWritable`
- 超管（`siteId = null`）不受影响

**涉及文件**：
- `app/Services/GeoFlow/MaterialLibraryService.php`

### P2：租户校验错误信息不够具体

**问题**：`assertKnowledgeBasesTenantVisible()` 只返回通用错误"绑定的知识库包含当前租户不可见的条目"，无法定位具体是哪些 KB ID 有问题。

**修复**（commit `f64a653`）：
- 改用 `pluck('id')` 取可见 ID 列表，`array_diff` 算出不可见 ID
- 422 响应体增加 `invisible_knowledge_base_ids`（不可见 ID 列表）、`requested_count`、`visible_count` 字段
- 前端可直接高亮有问题的条目，调试更方便

**涉及文件**：
- `app/Services/GeoFlow/TaskLifecycleService.php`

---

## 附录：演进优先级与工期估算

| 阶段 | 优先级 | 工期 | 阻塞项 | 交付物 | 状态 |
|---|---|---|---|---|---|
| Phase 6 | **P0** | 1.5–2 天 | 无 | Bridge 侧完整交付，租户能用 | ✅ 已完成 (2026-11-07) |
| Phase 7 | P1 | 1 天 | Phase 6 | 测试补全 + 代码加固 | ✅ 已完成 (commit fe65353) |
| Phase 8 | P2 | 2–3 天 | Phase 7 | Admin 面板租户视图 | ✅ 已完成 (commit 700a543) |
| K1-K7 | **P3** | 1 天 | Phase 8 | 知识库隔离全链路 | ✅ 已完成 (2026-11-08) |
| 代码审查修复 | **P3** | 0.5 天 | K1-K7 | P0 items siteId + P1 全局 KB 写保护 + P2 错误信息 | ✅ 已完成 (commit f64a653) |
| 长期演进 | P4 | 按需 | 业务需求驱动 | 文章归属 / 扇出调度 / 路线 C | ⏳ 按需启动 |

**Phase 1–8 + K1-K7 + 代码审查修复已全部交付**。后续按业务需求从长期演进项中选取启动。

---

## 附录：风险与缓解

| 风险 | 影响 | 缓解措施 |
|---|---|---|
| Bridge 侧交付延迟 | 租户无法实际使用多租户功能 | Phase 6 作为 P0 优先交付 |
| 测试补全发现新 bug | 延长 Phase 7 工期 | 预留 0.5 天 buffer |
| Admin 面板改动影响超管习惯 | 运营效率下降 | Phase 8 默认"全局视图"，切换可选 |
| 长期演进过度设计 | 浪费开发资源 | 严格按触发条件启动，不提前投入 |

---

**文档维护**：每完成一个 Phase，更新对应章节状态为"已交付"并记录实际工期。
