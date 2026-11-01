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
     */
    public function removeKeyword(int $keywordId): bool
    {
        return (bool) BrandKeyword::query()->whereKey($keywordId)->delete();
    }

    /**
     * 切换关键词活跃状态
     */
    public function toggleKeyword(int $keywordId): ?BrandKeyword
    {
        $keyword = BrandKeyword::query()->find($keywordId);
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
