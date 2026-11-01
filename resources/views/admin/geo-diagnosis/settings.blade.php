@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0" data-geo-settings x-data="geoSettings()">
        {{-- 页面标题 --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-600">GEO 诊断</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900">诊断设置</h1>
                <p class="mt-1 text-sm text-gray-500">配置四维评分体系的权重分配和检查项开关。权重总和须为 100%。</p>
            </div>
            <a href="{{ route('admin.geo-diagnosis.index') }}"
                class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                返回概览
            </a>
        </div>

        {{-- 维度权重 --}}
        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 p-5">
                <h2 class="text-lg font-semibold text-gray-900">维度权重</h2>
                <p class="mt-1 text-sm text-gray-500">调整各诊断维度在总分中的占比</p>
            </div>
            <div class="divide-y divide-gray-100 p-5">
                @php
                    $dimensions = [
                        ['key' => 'content_structure', 'label' => '内容结构', 'desc' => '标题层级、段落长度、FAQ 结构'],
                        ['key' => 'knowledge_alignment', 'label' => '知识对齐', 'desc' => '知识库覆盖率、术语频次、矛盾检测'],
                        ['key' => 'technical_geo', 'label' => '技术 GEO', 'desc' => 'llms.txt、Schema.org、canonical 链接'],
                        ['key' => 'ai_visibility', 'label' => 'AI 可见度', 'desc' => '搜索排名、竞品引用占比'],
                    ];
                @endphp
                @foreach ($dimensions as $dim)
                    <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                        <div class="w-32 shrink-0">
                            <p class="text-sm font-medium text-gray-800">{{ $dim['label'] }}</p>
                            <p class="text-xs text-gray-400">{{ $dim['desc'] }}</p>
                        </div>
                        <div class="flex flex-1 items-center gap-3">
                            <input type="range" min="0" max="100" step="5"
                                x-model.number="weights.{{ $dim['key'] }}"
                                @input="recalculate()"
                                class="h-2 flex-1 cursor-pointer appearance-none rounded-lg bg-gray-200 accent-blue-600">
                            <span class="w-12 text-right font-mono text-sm font-semibold text-gray-900"
                                x-text="Math.round(weights.{{ $dim['key'] }} * 100) + '%'"></span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex items-center justify-between border-t border-gray-100 bg-gray-50 px-5 py-3">
                <span class="text-sm font-medium text-gray-700">权重总计</span>
                <span class="font-mono text-lg font-bold"
                    :class="totalWeight === 100 ? 'text-emerald-600' : 'text-red-600'"
                    x-text="totalWeight + '%'"></span>
            </div>
        </section>

        {{-- 检查项开关 --}}
        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 p-5">
                <h2 class="text-lg font-semibold text-gray-900">检查项开关</h2>
                <p class="mt-1 text-sm text-gray-500">启用或禁用特定的诊断检查项</p>
            </div>
            <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($allChecks as $check)
                    @php
                        $dimLabels = [
                            'content_structure' => ['label' => '内容结构', 'color' => 'blue'],
                            'knowledge_alignment' => ['label' => '知识对齐', 'color' => 'violet'],
                            'technical_geo' => ['label' => '技术 GEO', 'color' => 'emerald'],
                            'ai_visibility' => ['label' => 'AI 可见度', 'color' => 'amber'],
                        ];
                        $dimInfo = $dimLabels[$check['dimension']] ?? ['label' => '未知', 'color' => 'gray'];
                        $isEnabled = in_array($check['id'], $settings->enabled_checks ?? []);
                    @endphp
                    <label class="flex items-start gap-3 rounded-lg border border-gray-100 p-3 transition-colors hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox"
                            x-model="enabledChecks"
                            value="{{ $check['id'] }}"
                            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="rounded bg-{{ $dimInfo['color'] }}-100 px-1.5 py-0.5 text-xs font-semibold text-{{ $dimInfo['color'] }}-700">
                                    {{ $check['id'] }}
                                </span>
                                <span class="text-sm font-medium text-gray-800">{{ $check['title'] }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-400">{{ $dimInfo['label'] }} · {{ $check['severity'] }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
        </section>

        {{-- 保存按钮 --}}
        <div class="flex justify-end">
            <button @click="saveSettings()" :disabled="saving || totalWeight !== 100"
                class="inline-flex items-center rounded-md bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                <i data-lucide="check" class="mr-2 h-4 w-4"></i>
                <span x-text="saving ? '保存中...' : '保存设置'"></span>
            </button>
            <p x-show="totalWeight !== 100" class="ml-3 self-center text-sm text-red-600">
                权重总和必须为 100%
            </p>
        </div>

        {{-- 保存成功提示 --}}
        <div x-show="saveSuccess" x-transition
            class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">
            设置已保存成功
        </div>
    </div>

    <script>
    function geoSettings() {
        const initialWeights = @json($settings->dimension_weights ?? \App\Models\GeoDiagnosisSetting::DEFAULT_WEIGHTS);
        return {
            weights: {
                content_structure: initialWeights.content_structure || 0.25,
                knowledge_alignment: initialWeights.knowledge_alignment || 0.30,
                technical_geo: initialWeights.technical_geo || 0.20,
                ai_visibility: initialWeights.ai_visibility || 0.25,
            },
            enabledChecks: @json($settings->enabled_checks ?? \App\Models\GeoDiagnosisSetting::ALL_CHECKS),
            saving: false,
            saveSuccess: false,

            get totalWeight() {
                return Math.round(
                    (this.weights.content_structure +
                     this.weights.knowledge_alignment +
                     this.weights.technical_geo +
                     this.weights.ai_visibility) * 100
                );
            },

            recalculate() {
                // 权重变化时自动更新总计
            },

            async saveSettings() {
                if (this.totalWeight !== 100) return;
                this.saving = true;
                this.saveSuccess = false;

                try {
                    const res = await fetch('{{ route("admin.geo-diagnosis.settings.update") }}', {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            dimension_weights: this.weights,
                            enabled_checks: this.enabledChecks,
                        }),
                    });

                    if (res.ok) {
                        this.saveSuccess = true;
                        setTimeout(() => { this.saveSuccess = false; }, 3000);
                    } else {
                        const err = await res.json();
                        alert(err.error?.message || '保存失败');
                    }
                } catch (e) {
                    console.error('Save settings failed', e);
                    alert('网络错误，请重试');
                } finally {
                    this.saving = false;
                }
            },
        };
    }
    </script>
@endsection
