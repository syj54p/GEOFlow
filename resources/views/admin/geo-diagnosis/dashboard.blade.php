@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0" data-geo-diagnosis-dashboard>
        {{-- 页面标题 --}}
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-600">GEO 诊断</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900">诊断概览</h1>
                <p class="mt-1 text-sm text-gray-500">一站式 GEO 运营中心，监控内容就绪度与品牌 AI 可见度。</p>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <x-admin.site-selector :sites="$sites ?? []" :selected-site-id="$selectedSiteId ?? null" />
                <a href="{{ route('admin.brand-monitor.index') }}"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="radar" class="w-4 h-4 mr-2"></i>
                    品牌监测
                </a>
                <a href="{{ route('admin.geo-diagnosis.settings.index') }}"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                    诊断设置
                </a>
            </div>
        </div>

        {{-- 4 个指标卡 --}}
        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $metricCards = [
                    [
                        'label' => '文章平均 GEO 分',
                        'value' => $dashboard['avg_geo_score'] ?? 0,
                        'delta' => $dashboard['avg_geo_score_delta'] ?? 0,
                        'suffix' => '分',
                        'icon' => 'clipboard-check',
                        'tone' => 'text-blue-600',
                    ],
                    [
                        'label' => '品牌 AI 可见度',
                        'value' => $dashboard['brand_visibility_pct'] ?? 0,
                        'delta' => null,
                        'suffix' => '%',
                        'icon' => 'eye',
                        'tone' => 'text-violet-600',
                    ],
                    [
                        'label' => '待优化文章',
                        'value' => $dashboard['articles_needing_optimization'] ?? 0,
                        'delta' => null,
                        'suffix' => '篇',
                        'icon' => 'alert-triangle',
                        'tone' => 'text-amber-600',
                    ],
                    [
                        'label' => '监控关键词',
                        'value' => $dashboard['active_keywords'] ?? 0,
                        'delta' => null,
                        'suffix' => '个',
                        'icon' => 'search',
                        'tone' => 'text-emerald-600',
                    ],
                ];
            @endphp
            @foreach ($metricCards as $card)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium text-gray-500">{{ $card['label'] }}</p>
                        <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5 {{ $card['tone'] }}"></i>
                    </div>
                    <p class="mt-3 text-right font-mono text-3xl font-semibold tabular-nums text-gray-900">
                        {{ $card['value'] }}<span class="ml-1 text-base font-normal text-gray-400">{{ $card['suffix'] }}</span>
                    </p>
                    @if ($card['delta'] !== null)
                        <p class="mt-1 text-right text-xs font-semibold {{ $card['delta'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                            @if ($card['delta'] >= 0) ↑ @else ↓ @endif
                            {{ abs($card['delta']) }} 分 vs 上周
                        </p>
                    @endif
                </article>
            @endforeach
        </section>

        {{-- 两栏布局：活动列表 + 品牌快照 --}}
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- 最近诊断活动 --}}
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-5">
                    <h2 class="text-lg font-semibold text-gray-900">最近活动</h2>
                    <p class="mt-1 text-sm text-gray-500">诊断完成、品牌排名变化和竞品预警</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($dashboard['recent_activities'] ?? [] as $activity)
                        <div class="flex items-start gap-3 p-4">
                            @php
                                $iconMap = [
                                    'diagnosis_completed' => ['icon' => 'clipboard-check', 'bg' => 'bg-blue-50', 'color' => 'text-blue-600'],
                                    'brand_rank_change' => ['icon' => 'trending-up', 'bg' => 'bg-violet-50', 'color' => 'text-violet-600'],
                                    'competitor_alert' => ['icon' => 'alert-triangle', 'bg' => 'bg-amber-50', 'color' => 'text-amber-600'],
                                    'sentiment_alert' => ['icon' => 'message-circle-warning', 'bg' => 'bg-red-50', 'color' => 'text-red-600'],
                                ];
                                $iconConfig = $iconMap[$activity['type']] ?? ['icon' => 'info', 'bg' => 'bg-gray-50', 'color' => 'text-gray-600'];
                            @endphp
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $iconConfig['bg'] }}">
                                <i data-lucide="{{ $iconConfig['icon'] }}" class="h-4 w-4 {{ $iconConfig['color'] }}"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-800">{{ $activity['title'] }}</p>
                                @if (!empty($activity['description']))
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $activity['description'] }}</p>
                                @endif
                                <p class="mt-1 text-xs text-gray-400">
                                    {{ \Carbon\Carbon::parse($activity['created_at'])->diffForHumans() }}
                                </p>
                            </div>
                            @if (($activity['severity'] ?? '') === 'alert')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700">预警</span>
                            @endif
                        </div>
                    @empty
                        <div class="p-8 text-center text-sm text-gray-400">
                            <i data-lucide="inbox" class="mx-auto mb-2 h-8 w-8 text-gray-300"></i>
                            暂无活动记录
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- 品牌 AI 可见度快照 --}}
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-5">
                    <h2 class="text-lg font-semibold text-gray-900">品牌 AI 可见度</h2>
                    <p class="mt-1 text-sm text-gray-500">各平台可见度百分比（最近 7 天）</p>
                </div>
                <div class="p-5">
                    @forelse ($dashboard['platform_visibility'] ?? [] as $pv)
                        <div class="mb-4 last:mb-0">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-sm font-medium text-gray-700">{{ $pv['name'] }}</span>
                                <span class="text-sm font-semibold tabular-nums text-gray-900">{{ $pv['visibility_pct'] }}%</span>
                            </div>
                            <div class="h-2.5 w-full rounded-full bg-gray-100">
                                <div class="h-2.5 rounded-full bg-blue-500 transition-all duration-500"
                                    style="width: {{ min(100, $pv['visibility_pct']) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-gray-400">
                            <i data-lucide="radar" class="mx-auto mb-2 h-8 w-8 text-gray-300"></i>
                            暂无平台监测数据<br>
                            <a href="{{ route('admin.brand-monitor.index') }}" class="text-blue-600 hover:underline">前往品牌监测配置</a>
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
