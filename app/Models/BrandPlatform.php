<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandPlatform extends Model
{
    protected $table = 'brand_platforms';

    protected $fillable = [
        'site_id',
        'name',
        'code',
        'icon_color',
        'is_enabled',
        'check_interval_minutes',
        'api_config',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'is_enabled' => 'boolean',
            'check_interval_minutes' => 'integer',
            'api_config' => 'array',
        ];
    }

    // 预定义平台
    public const PLATFORM_DOUBAO = 'doubao';
    public const PLATFORM_KIMI = 'kimi';
    public const PLATFORM_QIANWEN = 'qianwen';
    public const PLATFORM_WENXIN = 'wenxin';
    public const PLATFORM_GOOGLE_AI = 'google_ai';
    public const PLATFORM_CHATGPT = 'chatgpt';

    // 平台展示配置
    public const PLATFORM_CONFIGS = [
        self::PLATFORM_DOUBAO => ['name' => '豆包', 'icon_color' => '#3B82F6', 'order' => 1],
        self::PLATFORM_KIMI => ['name' => 'Kimi', 'icon_color' => '#6366F1', 'order' => 2],
        self::PLATFORM_QIANWEN => ['name' => '通义千问', 'icon_color' => '#8B5CF6', 'order' => 3],
        self::PLATFORM_WENXIN => ['name' => '文心一言', 'icon_color' => '#EC4899', 'order' => 4],
        self::PLATFORM_GOOGLE_AI => ['name' => 'Google AI Overview', 'icon_color' => '#10B981', 'order' => 5],
        self::PLATFORM_CHATGPT => ['name' => 'ChatGPT', 'icon_color' => '#059669', 'order' => 6],
    ];

    public function monitorResults(): HasMany
    {
        return $this->hasMany(BrandMonitorResult::class, 'platform_id');
    }

    /**
     * 获取最新一条监测结果
     */
    public function latestResult(): HasMany
    {
        return $this->hasMany(BrandMonitorResult::class, 'platform_id')
            ->latestOfMany('detected_at');
    }

    /**
     * Scope: 仅启用的平台
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope: 按预定义顺序排序
     */
    public function scopeOrdered($query)
    {
        $codes = array_keys(self::PLATFORM_CONFIGS);
        $cases = implode(' ', array_map(
            fn($code, $i) => "WHEN '{$code}' THEN {$i}",
            $codes,
            array_values(array_map(fn($c) => $c['order'], self::PLATFORM_CONFIGS))
        ));
        return $query->orderByRaw("CASE code {$cases} ELSE 99 END");
    }
}
