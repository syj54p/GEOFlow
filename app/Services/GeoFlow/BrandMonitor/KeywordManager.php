<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor;

use App\Models\BrandKeyword;
use Illuminate\Database\Eloquent\Collection;

/**
 * 品牌监测关键词管理器。
 *
 * 负责关键词的增删改查和活跃状态管理。
 */
final class KeywordManager
{
    /**
     * 获取站点的所有关键词
     *
     * @return Collection<int, BrandKeyword>
     */
    public function getKeywords(?int $siteId = null, bool $activeOnly = false): Collection
    {
        $query = BrandKeyword::query();

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('created_at')->get();
    }

    /**
     * 添加关键词
     */
    public function addKeyword(string $keyword, ?int $siteId = null): BrandKeyword
    {
        return BrandKeyword::updateOrCreate(
            ['keyword' => $keyword, 'site_id' => $siteId],
            ['is_active' => true]
        );
    }

    /**
     * 删除关键词
     *
     * @param int $keywordId 关键词 ID
     * @param int|null $siteId 租户站点 ID（非 null 时校验归属，防跨租户删除）
     * @throws \RuntimeException 关键词不存在或无权修改
     */
    public function removeKeyword(int $keywordId, ?int $siteId = null): bool
    {
        $keyword = BrandKeyword::query()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->find($keywordId);

        if ($keyword === null) {
            throw new \RuntimeException('关键词不存在或无权修改');
        }

        return (bool) $keyword->delete();
    }

    /**
     * 切换关键词活跃状态
     *
     * @param int $keywordId 关键词 ID
     * @param int|null $siteId 租户站点 ID（非 null 时校验归属，防跨租户改）
     */
    public function toggleKeyword(int $keywordId, ?int $siteId = null): ?BrandKeyword
    {
        $keyword = BrandKeyword::query()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->find($keywordId);

        if ($keyword === null) {
            return null;
        }

        $keyword->update(['is_active' => !$keyword->is_active]);
        return $keyword->refresh();
    }

    /**
     * 批量添加关键词
     *
     * @param  list<string>  $keywords
     * @return Collection<int, BrandKeyword>
     */
    public function addKeywords(array $keywords, ?int $siteId = null): Collection
    {
        foreach ($keywords as $kw) {
            $this->addKeyword(trim($kw), $siteId);
        }

        return $this->getKeywords($siteId);
    }
}
