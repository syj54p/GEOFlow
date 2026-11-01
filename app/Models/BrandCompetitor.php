<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandCompetitor extends Model
{
    protected $table = 'brand_competitors';

    protected $fillable = [
        'site_id',
        'name',
        'quote_percentage',
        'weekly_change',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'quote_percentage' => 'decimal:2',
            'weekly_change' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope: 仅活跃的竞品
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: 按引用占比降序
     */
    public function scopeByQuotePct($query)
    {
        return $query->orderByDesc('quote_percentage');
    }

    /**
     * 获取变化方向标签
     */
    public function getChangeDirectionAttribute(): string
    {
        return match (true) {
            $this->weekly_change > 0 => 'up',
            $this->weekly_change < 0 => 'down',
            default => 'flat',
        };
    }
}
