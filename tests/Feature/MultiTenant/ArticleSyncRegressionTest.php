<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 文章同步 API 回归测试：确保多租户中间件不影响存量同步链路。
 *
 * 文章同步 API（/api/v1/articles）走的是 articles:read/write scope，
 * 不挂 api.tenant 中间件，因此不应受 X-Site-Id / site_id 绑定影响。
 */
class ArticleSyncRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(string $username = 'sync_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret',
            'email' => "{$username}@example.com",
            'display_name' => 'Sync',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createArticle(string $title = 'Sync Article'): Article
    {
        $category = Category::query()->create(['name' => 'SyncCat', 'slug' => 'sync-cat-' . uniqid()]);
        $author = Author::query()->create(['name' => 'SyncAuthor', 'email' => 'sync@' . uniqid() . '.com']);

        return Article::query()->create([
            'title' => $title,
            'slug' => 'sync-slug-' . uniqid(),
            'content' => 'Sync content body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    /**
     * 文章列表 API 不需要 X-Site-Id，Token 也不绑定 site_id。
     */
    public function test_article_list_works_without_tenant_context(): void
    {
        $admin = $this->createAdmin();
        $this->createArticle('Article A');
        $this->createArticle('Article B');

        // 普通 Token，无 site_id 绑定，无 X-Site-Id Header
        $token = $admin->createToken('sync-token', ['articles:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/articles')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
    }

    /**
     * 文章详情 API 同样不受租户中间件影响。
     */
    public function test_article_detail_works_without_tenant_context(): void
    {
        $admin = $this->createAdmin('detail_admin');
        $article = $this->createArticle('Detail Article');

        $token = $admin->createToken('detail-token', ['articles:read'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Detail Article');
    }
}
