@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0" data-brand-monitor x-data="brandMonitor()">
        {{-- 页面标题 --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-600">GEO 诊断</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900">品牌监测</h1>
                <p class="mt-1 text-sm text-gray-500">监控品牌在主流 AI 平台的可见度、信源引用和舆情评价。</p>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <x-admin.site-selector :sites="$sites ?? []" :selected-site-id="$selectedSiteId ?? null" />
                <a href="{{ route('admin.geo-diagnosis.index') }}"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                    返回概览
                </a>
                <button @click="refreshData()" :disabled="loading"
                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">
                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-2" :class="{'animate-spin': loading}"></i>
                    刷新数据
                </button>
            </div>
        </div>

        {{-- 标签页导航 --}}
        <nav class="mb-6 border-b border-gray-200">
            <div class="flex space-x-6">
                <template x-for="(tab, idx) in tabs" :key="idx">
                    <button @click="activeTab = idx"
                        :class="activeTab === idx ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap border-b-2 py-3 px-1 text-sm font-medium transition-colors"
                        x-text="tab.label"></button>
                </template>
            </div>
        </nav>

        {{-- Tab 0: AI 可见性 --}}
        <div x-show="activeTab === 0" x-cloak>
            {{-- 平台选择器 --}}
            <section class="mb-6">
                <h2 class="mb-3 text-sm font-semibold text-gray-700">监控平台</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($platforms as $platform)
                        <div class="rounded-lg border p-3 text-center transition-colors {{ $platform->is_enabled ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-gray-50' }}">
                            <div class="mx-auto mb-2 flex h-8 w-8 items-center justify-center rounded-full"
                                style="background-color: {{ $platform->icon_color }}20; color: {{ $platform->icon_color }}">
                                <i data-lucide="globe" class="h-4 w-4"></i>
                            </div>
                            <p class="text-xs font-semibold text-gray-800">{{ $platform->name }}</p>
                            <p class="mt-0.5 text-xs {{ $platform->is_enabled ? 'text-blue-600' : 'text-gray-400' }}">
                                {{ $platform->is_enabled ? '每 ' . $platform->check_interval_minutes . ' 分钟' : '未启用' }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- 关键词管理 --}}
            <section class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-700">监控关键词</h2>
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="newKeyword" @keydown.enter="addKeyword()"
                            placeholder="输入关键词后回车"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <button @click="addKeyword()" :disabled="!newKeyword.trim()"
                            class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            添加
                        </button>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($keywords as $keyword)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700">
                            {{ $keyword->keyword }}
                            <button onclick="removeKeyword({{ $keyword->id }})" class="ml-1 text-gray-400 hover:text-red-500">
                                <i data-lucide="x" class="h-3 w-3"></i>
                            </button>
                        </span>
                    @endforeach
                    @if ($keywords->isEmpty())
                        <p class="text-sm text-gray-400">暂无监控关键词，请添加</p>
                    @endif
                </div>
            </section>

            {{-- 可见性结果表 --}}
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4">
                    <h2 class="text-sm font-semibold text-gray-700">AI 可见性排名</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">关键词</th>
                                @foreach ($platforms->where('is_enabled', true) as $p)
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $p->name }}</th>
                                @endforeach
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">综合排名</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">趋势</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <template x-for="row in visibilityData" :key="row.keyword_id">
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900" x-text="row.keyword"></td>
                                    @foreach ($platforms->where('is_enabled', true) as $p)
                                        <td class="whitespace-nowrap px-4 py-3 text-center text-sm">
                                            <span :class="row.platforms?.['{{ $p->code }}']?.in_top10 ? 'text-emerald-600 font-semibold' : 'text-gray-500'"
                                                x-text="row.platforms?.['{{ $p->code }}']?.rank ?? '-'"></span>
                                        </td>
                                    @endforeach
                                    <td class="whitespace-nowrap px-4 py-3 text-center text-sm font-semibold text-gray-900" x-text="row.avg_rank ?? '-'"></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-center text-sm">
                                        <span :class="{
                                            'text-emerald-600': row.trend_direction === 'up',
                                            'text-red-600': row.trend_direction === 'down',
                                            'text-gray-400': row.trend_direction === 'flat'
                                        }">
                                            <span x-show="row.trend_direction === 'up'">↑</span>
                                            <span x-show="row.trend_direction === 'down'">↓</span>
                                            <span x-show="row.trend_direction === 'flat'">→</span>
                                            <span x-text="row.trend || ''"></span>
                                        </span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="visibilityData.length === 0">
                                <td :colspan="{{ $platforms->where('is_enabled', true)->count() + 3 }}" class="px-4 py-8 text-center text-sm text-gray-400">
                                    暂无可见性数据，请先添加关键词并启用平台
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Tab 1: 信源检测 --}}
        <div x-show="activeTab === 1" x-cloak>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {{-- 品牌引用占比 --}}
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
                    <h2 class="mb-4 text-sm font-semibold text-gray-700">品牌引用占比</h2>
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-700">本站</span>
                                <span class="font-semibold text-blue-600" x-text="(sourceData.brand_quote_pct || 0) + '%'"></span>
                            </div>
                            <div class="h-3 w-full rounded-full bg-gray-100">
                                <div class="h-3 rounded-full bg-blue-500 transition-all duration-500"
                                    :style="'width: ' + (sourceData.brand_quote_pct || 0) + '%'"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-700">竞品</span>
                                <span class="font-semibold text-red-600" x-text="(sourceData.competitor_quote_pct || 0) + '%'"></span>
                            </div>
                            <div class="h-3 w-full rounded-full bg-gray-100">
                                <div class="h-3 rounded-full bg-red-400 transition-all duration-500"
                                    :style="'width: ' + (sourceData.competitor_quote_pct || 0) + '%'"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-700">其他</span>
                                <span class="font-semibold text-gray-500" x-text="(sourceData.other_quote_pct || 0) + '%'"></span>
                            </div>
                            <div class="h-3 w-full rounded-full bg-gray-100">
                                <div class="h-3 rounded-full bg-gray-300 transition-all duration-500"
                                    :style="'width: ' + (sourceData.other_quote_pct || 0) + '%'"></div>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- 高频引用 TOP 5 --}}
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 text-sm font-semibold text-gray-700">高频引用内容 TOP 5</h2>
                    <div class="space-y-3">
                        <template x-for="(article, idx) in (sourceData.top_cited_articles || [])" :key="idx">
                            <div class="flex items-start gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700"
                                    x-text="idx + 1"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-gray-800" x-text="article.title"></p>
                                    <p class="text-xs text-gray-500">被引用 <span class="font-semibold text-blue-600" x-text="article.cite_count"></span> 次</p>
                                </div>
                            </div>
                        </template>
                        <p x-show="!sourceData.top_cited_articles?.length" class="text-sm text-gray-400">暂无数据</p>
                    </div>
                </section>
            </div>

            {{-- 竞品信源追踪 --}}
            <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">竞品信源追踪</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <template x-for="(comp, idx) in (sourceData.competitors || [])" :key="idx">
                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-gray-800" x-text="comp.name"></p>
                                <span class="text-lg font-bold text-gray-900" x-text="(comp.quote_pct || 0) + '%'"></span>
                            </div>
                            <p class="mt-1 text-xs"
                                :class="(comp.weekly_change || 0) >= 0 ? 'text-red-600' : 'text-emerald-600'">
                                <span x-text="(comp.weekly_change || 0) >= 0 ? '↑' : '↓'"></span>
                                <span x-text="Math.abs(comp.weekly_change || 0) + '% 近 7 天'"></span>
                            </p>
                        </div>
                    </template>
                    <p x-show="!sourceData.competitors?.length" class="col-span-full text-sm text-gray-400">暂无竞品追踪数据</p>
                </div>
            </section>
        </div>

        {{-- Tab 2: 舆情评价 --}}
        <div x-show="activeTab === 2" x-cloak>
            {{-- 情感分布 --}}
            <section class="mb-6 grid grid-cols-3 gap-4">
                <article class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-center">
                    <p class="text-sm font-medium text-emerald-700">正面评价</p>
                    <p class="mt-2 text-3xl font-bold text-emerald-600" x-text="(sentimentData.positive_pct || 0) + '%'"></p>
                    <p class="mt-1 text-xs" :class="(sentimentData.positive_delta || 0) >= 0 ? 'text-emerald-600' : 'text-red-600'">
                        <span x-text="(sentimentData.positive_delta || 0) >= 0 ? '↑' : '↓'"></span>
                        <span x-text="Math.abs(sentimentData.positive_delta || 0) + '% vs 上周'"></span>
                    </p>
                </article>
                <article class="rounded-lg border border-gray-200 bg-gray-50 p-5 text-center">
                    <p class="text-sm font-medium text-gray-600">中性评价</p>
                    <p class="mt-2 text-3xl font-bold text-gray-700" x-text="(sentimentData.neutral_pct || 0) + '%'"></p>
                    <p class="mt-1 text-xs text-gray-400">
                        <span x-text="(sentimentData.neutral_delta || 0) >= 0 ? '↑' : '↓'"></span>
                        <span x-text="Math.abs(sentimentData.neutral_delta || 0) + '% vs 上周'"></span>
                    </p>
                </article>
                <article class="rounded-lg border border-red-200 bg-red-50 p-5 text-center">
                    <p class="text-sm font-medium text-red-700">负面评价</p>
                    <p class="mt-2 text-3xl font-bold text-red-600" x-text="(sentimentData.negative_pct || 0) + '%'"></p>
                    <p class="mt-1 text-xs" :class="(sentimentData.negative_delta || 0) <= 0 ? 'text-emerald-600' : 'text-red-600'">
                        <span x-text="(sentimentData.negative_delta || 0) <= 0 ? '↓' : '↑'"></span>
                        <span x-text="Math.abs(sentimentData.negative_delta || 0) + '% vs 上周'"></span>
                    </p>
                </article>
            </section>

            {{-- 近期评价摘要 --}}
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-5">
                    <h2 class="text-sm font-semibold text-gray-700">近期评价摘要</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    <template x-for="(snippet, idx) in (sentimentData.snippets || [])" :key="idx">
                        <div class="p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="{
                                        'bg-emerald-50 text-emerald-700': snippet.sentiment === 'positive',
                                        'bg-gray-100 text-gray-600': snippet.sentiment === 'neutral',
                                        'bg-red-50 text-red-700': snippet.sentiment === 'negative',
                                    }"
                                    x-text="snippet.sentiment === 'positive' ? '正面' : (snippet.sentiment === 'negative' ? '负面' : '中性')">
                                </span>
                                <span class="text-xs text-gray-400" x-text="snippet.platform"></span>
                                <span class="text-xs text-gray-300">·</span>
                                <span class="text-xs text-gray-400" x-text="new Date(snippet.detected_at).toLocaleDateString()"></span>
                            </div>
                            <p class="text-sm text-gray-700" x-text="snippet.text"></p>
                            <div x-show="snippet.sentiment === 'negative'" class="mt-2 rounded-md bg-amber-50 p-3 text-xs text-amber-800">
                                <strong>建议操作：</strong> 检查该评价提到的问题，优化对应内容或补充知识库信息
                            </div>
                        </div>
                    </template>
                    <div x-show="!sentimentData.snippets?.length" class="p-8 text-center text-sm text-gray-400">
                        暂无舆情评价数据
                    </div>
                </div>
            </section>
        </div>
    </div>

    {{-- Alpine.js 组件 --}}
    <script>
    function brandMonitor() {
        return {
            activeTab: 0,
            loading: false,
            newKeyword: '',
            siteId: {{ $selectedSiteId ?? 'null' }},
            tabs: [
                { label: 'AI 可见性' },
                { label: '信源检测' },
                { label: '舆情评价' },
            ],
            visibilityData: [],
            sourceData: {},
            sentimentData: {},

            init() {
                this.loadVisibility();
                this.loadSources();
                this.loadSentiment();
            },

            buildUrl(base, params = {}) {
                const url = new URL(base, window.location.origin);
                if (this.siteId) url.searchParams.set('site_id', this.siteId);
                Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
                return url.toString();
            },

            async loadVisibility() {
                try {
                    const res = await fetch(this.buildUrl('{{ route("admin.brand-monitor.visibility") }}'));
                    const json = await res.json();
                    this.visibilityData = json.data || [];
                } catch (e) { console.error('Load visibility failed', e); }
            },

            async loadSources() {
                try {
                    const res = await fetch(this.buildUrl('{{ route("admin.brand-monitor.sources") }}'));
                    const json = await res.json();
                    this.sourceData = json.data || {};
                } catch (e) { console.error('Load sources failed', e); }
            },

            async loadSentiment() {
                try {
                    const res = await fetch(this.buildUrl('{{ route("admin.brand-monitor.sentiment") }}'));
                    const json = await res.json();
                    this.sentimentData = json.data || {};
                } catch (e) { console.error('Load sentiment failed', e); }
            },

            async addKeyword() {
                const kw = this.newKeyword.trim();
                if (!kw) return;
                try {
                    const res = await fetch('{{ route("admin.brand-monitor.keywords.store") }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ keyword: kw }),
                    });
                    if (res.ok) {
                        this.newKeyword = '';
                        location.reload();
                    }
                } catch (e) { console.error('Add keyword failed', e); }
            },

            async refreshData() {
                this.loading = true;
                try {
                    await Promise.all([this.loadVisibility(), this.loadSources(), this.loadSentiment()]);
                } finally {
                    this.loading = false;
                }
            },
        };
    }

    async function removeKeyword(id) {
        if (!confirm('确定删除该关键词？')) return;
        try {
            await fetch(`/{{ config('geoflow.admin_base_path', 'geo_admin') }}/brand-monitor/keywords/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            location.reload();
        } catch (e) { console.error('Remove keyword failed', e); }
    }
    </script>
@endsection
