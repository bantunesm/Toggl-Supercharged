<section class="mt-6 rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-sm">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Highlights</h2>
            <p class="mt-1 text-sm text-slate-500">Records all time calculés sur les snapshots synchronisés.</p>
        </div>
        <p class="mono text-xs text-slate-500">
            @if ($trackingSinceLabel !== null)
                Historique depuis {{ $trackingSinceLabel }}
            @else
                Historique indisponible
            @endif
        </p>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="highlight-card rounded-xl border p-4 pr-16">
            <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-cyan-100/75 blur-2xl"></div>
            <div class="highlight-watermark">
                <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path d="M8 3v3M16 3v3M4 9h16M6 5h12a2 2 0 0 1 2 2v11a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="m12 12.5.75 1.55 1.7.26-1.22 1.25.3 1.78L12 16.5l-1.53.84.3-1.78-1.22-1.25 1.7-.26L12 12.5Z" fill="currentColor"/>
                </svg>
            </div>
            <p class="text-xs uppercase tracking-wide text-slate-500">Record jour</p>
            <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                @if ($allTimeDayRecordHours !== null)
                    {{ $allTimeDayRecordHours }} h
                @else
                    n/a
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $allTimeDayRecordDate ?? 'Aucune donnée' }}</p>
        </article>

        <article class="highlight-card rounded-xl border p-4 pr-16">
            <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-violet-100/75 blur-2xl"></div>
            <div class="highlight-watermark">
                <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path d="M8 3v3M16 3v3M4 9h16M6 5h12a2 2 0 0 1 2 2v11a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M8 13h2M10 13h2M12 13h2M14 13h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p class="text-xs uppercase tracking-wide text-slate-500">Record semaine</p>
            <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                @if ($allTimeWeekRecordHours !== null)
                    {{ $allTimeWeekRecordHours }} h
                @else
                    n/a
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $allTimeWeekRecordDate ?? 'Aucune donnée' }}</p>
        </article>

        <article class="highlight-card rounded-xl border p-4 pr-16">
            <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-teal-100/70 blur-2xl"></div>
            <div class="highlight-watermark">
                <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path d="M4 20V9M10 20V5M16 20v-8M22 20H2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="m4 9 5-3 4 2 5-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <p class="text-xs uppercase tracking-wide text-slate-500">Record mois</p>
            <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                @if ($allTimeMonthRecordHours !== null)
                    {{ $allTimeMonthRecordHours }} h
                @else
                    n/a
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $allTimeMonthRecordDate ?? 'Aucune donnée' }}</p>
        </article>

        <article class="highlight-card rounded-xl border p-4 pr-16">
            <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-emerald-100/75 blur-2xl"></div>
            <div class="highlight-watermark">
                <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path d="M7 20h10M9 20v-3h6v3M8 4h8l-1 5a3 3 0 0 1-3 2h0a3 3 0 0 1-3-2L8 4ZM6 6H4a2 2 0 0 0 2 2M18 6h2a2 2 0 0 1-2 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <p class="text-xs uppercase tracking-wide text-slate-500">Record année</p>
            <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                @if ($allTimeYearRecordHours !== null)
                    {{ $allTimeYearRecordHours }} h
                @else
                    n/a
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $allTimeYearRecordDate ?? 'Aucune donnée' }}</p>
        </article>
    </div>

    <div class="mt-3">
        <article class="highlight-card rounded-xl border p-4 pr-24">
            <div class="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full bg-sky-100/75 blur-2xl"></div>
            <div class="highlight-watermark">
                <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path d="M12 21a9 9 0 1 0-9-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="m12 12 5.6-5.6M14.2 6.4h3.4v3.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="1.9" fill="currentColor"/>
                </svg>
            </div>
            <p class="text-xs uppercase tracking-wide text-slate-500">Période actuelle vs record {{ $recordScopeLabel }}</p>
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <p class="mono mt-2 text-2xl font-semibold text-slate-900">
                    @if ($recordProgressPercent !== null)
                        {{ $recordProgressPercent }}%
                    @else
                        n/a
                    @endif
                </p>
                @if ($recordProgressPercent !== null && $recordGapHours !== null)
                    <p class="text-sm {{ $recordGapDirection === 'record' ? 'text-emerald-700 font-medium' : 'text-slate-500' }}">
                        @if ($recordGapDirection === 'record')
                            Record battu de {{ $recordGapHours }} h
                        @elseif ($recordGapDirection === 'egalite')
                            Record égalé
                        @else
                            Encore {{ $recordGapHours }} h pour le record
                        @endif
                    </p>
                @else
                    <p class="text-sm text-slate-500">Base de comparaison indisponible</p>
                @endif
            </div>
        </article>
    </div>
</section>
