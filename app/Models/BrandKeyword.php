<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandKeyword extends Model
{
    protected $table = 'brand_keywords';

    protected $fillable = [
        'site_id',
        'keyword',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function monitorResults(): HasMany
    {
        return $this->hasMany(BrandMonitorResult::class, 'keyword_id');
    }

    /**
     * Scope: 仅活跃的关键词
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 获取该关键词在各平台的最新排名
     */
    public function getLatestRanksAttribute(): array
    {
        return $this->monitorResults()
            ->selectRaw('platform_id, search_rank, is_in_top10, detected_at')
            ->orderByDesc('detected_at')
            ->get()
            ->groupBy('platform_id')
            ->map(fn($group) => $group->first())
            ->toArray();
    }
}
