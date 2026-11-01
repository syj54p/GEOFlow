@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0" data-geo-inflow>
        @include('admin.analytics._page-header', [
            'title' => __('admin.geo_inflow.page_title'),
            'subtitle' => __('admin.geo_inflow.page_subtitle'),
        ])

        {{-- KPI 摘要卡片 --}}
        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $kpiCards = [
                    ['key' => 'total_published_articles', 'icon' => 'file-text', 'label' => __('admin.geo_inflow.kpi.total_published'), 'tone' => 'text-gray-950'],
                    ['key' => 'articles_needing_action', 'icon' => 'alert-triangle', 'label' => __('admin.geo_inflow.kpi.needing_action'), 'tone' => 'text-amber-600'],
                    ['key' => 'avg_visibility_score', 'icon' => 'eye', 'label' => __('admin.geo_inflow.kpi.avg_visibility'), 'tone' => 'text-violet-600', 'suffix' => '%'],
                    ['key' => 'avg_distribution_success_rate', 'icon' => 'send', 'label' => __('admin.geo_inflow.kpi.avg_distribution'), 'tone' => 'text-emerald-600', 'suffix' => '%'],
                ];
            @endphp
            @foreach ($kpiCards as $card)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm text-gray-500">{{ $card['label'] }}</p>
                        <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5 text-slate-500"></i>
                    </div>
                    <p class="mt-3 text-right font-mono text-3xl font-semibold tabular-nums {{ $card['tone'] }}">
                        @if ($card['key'] === 'avg_distribution_success_rate')
                            {{ number_format((float) ($summary[$card['key']] ?? 0), 1) }}
                        @else
                            {{ number_format((int) ($summary[$card['key']] ?? 0)) }}
                        @endif
                        {{ $card['suffix'] ?? '' }}
                    </p>
                </article>
            @endforeach
        </section>

        {{-- 待处理动作 --}}
        <section class="mt-6 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 p-5">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.geo_inflow.pending_actions') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.geo_inflow.pending_actions_desc') }}</p>
                </div>
                <span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-700">
                    {{ count($pendingActions) }} {{ __('admin.geo_inflow.items') }}
                </span>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($pendingActions as $action)
                    <div class="flex items-center justify-between gap-4 p-5" data-action-row data-article-id="{{ $action['article_id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold
                                    {{ $action['severity_score'] >= 5 ? 'bg-red-50 text-red-700' : ($action['severity_score'] >= 3 ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700') }}">
                                    {{ __('admin.geo_inflow.severity.' . ($action['severity_score'] >= 5 ? 'high' : ($action['severity_score'] >= 3 ? 'medium' : 'low'))) }}
                                </span>
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                    {{ $action['action_type_label'] }}
                                </span>
                                <span class="inline-flex items-center rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500">
                                    {{ $action['trigger_source_label'] }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm font-medium text-gray-900">{{ $action['article_title'] }}</p>
                            @if (! empty($action['reasons']))
                                <p class="mt-1 text-xs text-gray-500">{{ implode('；', $action['reasons']) }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button"
                                data-execute-action
                                data-article-id="{{ $action['article_id'] }}"
                                data-action-type="{{ $action['action_type'] }}"
                                class="inline-flex min-h-9 items-center rounded-md bg-slate-700 px-3 text-sm font-semibold text-white hover:bg-slate-800 active:scale-[.98]">
                                <i data-lucide="play" class="mr-1.5 h-3.5 w-3.5"></i>
                                {{ __('admin.geo_inflow.execute') }}
                            </button>
                            <a href="{{ route('admin.articles.edit', $action['article_id']) }}"
                                class="inline-flex min-h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <i data-lucide="circle-check" class="mx-auto h-8 w-8 text-emerald-400"></i>
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.geo_inflow.no_pending') }}</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- 单文章评估 --}}
        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.geo_inflow.evaluate_article') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('admin.geo_inflow.evaluate_article_desc') }}</p>
            <div class="mt-4 flex gap-3">
                <select id="geo-inflow-article-select"
                    class="block min-h-10 flex-1 rounded-md border-gray-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="">{{ __('admin.geo_inflow.select_article') }}</option>
                </select>
                <button type="button" id="geo-inflow-evaluate-btn"
                    class="inline-flex min-h-10 items-center rounded-md bg-violet-600 px-4 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50"
                    disabled>
                    <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.geo_inflow.evaluate') }}
                </button>
            </div>
            <div id="geo-inflow-evaluate-result" class="mt-4 hidden">
                {{-- JS 动态填充 --}}
            </div>
        </section>

        {{-- 最近动作历史 --}}
        <section class="mt-6 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 p-5">
                <h2 class="text-lg font-semibold text-gray-950">{{ __('admin.geo_inflow.recent_actions') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left font-medium text-gray-500">{{ __('admin.geo_inflow.table.article') }}</th>
                            <th class="px-5 py-3 text-left font-medium text-gray-500">{{ __('admin.geo_inflow.table.action') }}</th>
                            <th class="px-5 py-3 text-left font-medium text-gray-500">{{ __('admin.geo_inflow.table.trigger') }}</th>
                            <th class="px-5 py-3 text-left font-medium text-gray-500">{{ __('admin.geo_inflow.table.status') }}</th>
                            <th class="px-5 py-3 text-left font-medium text-gray-500">{{ __('admin.geo_inflow.table.severity') }}</th>
                            <th class="px-5 py-3 text-left font-medium text-gray-500">{{ __('admin.geo_inflow.table.time') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentActions as $action)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium text-gray-900">
                                    <a href="{{ route('admin.articles.edit', $action['article_id']) }}" class="text-blue-600 hover:underline">
                                        {{ $action['article_title'] }}
                                    </a>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                        {{ $action['action_type_label'] }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-gray-600">{{ $action['trigger_source_label'] }}</td>
                                <td class="px-5 py-3">
                                    @php
                                        $statusColors = [
                                            'pending' => 'bg-amber-50 text-amber-700',
                                            'executing' => 'bg-blue-50 text-blue-700',
                                            'completed' => 'bg-emerald-50 text-emerald-700',
                                            'failed' => 'bg-red-50 text-red-700',
                                            'cancelled' => 'bg-gray-50 text-gray-500',
                                        ];
                                        $statusColor = $statusColors[$action['status']] ?? 'bg-gray-50 text-gray-500';
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusColor }}">
                                        {{ $action['status_label'] }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-center font-mono tabular-nums">{{ $action['severity_score'] }}</td>
                                <td class="px-5 py-3 text-gray-500">
                                    @if ($action['executed_at'] ?? null)
                                        {{ \Carbon\Carbon::parse($action['executed_at'])->diffForHumans() }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-gray-500">
                                    {{ __('admin.geo_inflow.no_recent_actions') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
