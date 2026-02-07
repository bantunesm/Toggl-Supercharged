<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cockpit;

use App\Http\Controllers\Controller;
use App\Services\TogglService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ProductivityDashboardController extends Controller
{
    public function __invoke(Request $request, TogglService $togglService): View
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $currentYear = (int) $today->year;
        $minYear = $currentYear - 9;
        $hasYearParam = $request->has('year');
        $hasMonthParam = $request->has('month');
        $selectedYear = max($minYear, min($currentYear, (int) $request->integer('year', $currentYear)));
        $selectedMonth = (!$hasYearParam && !$hasMonthParam)
            ? (int) $today->month
            : $this->resolveMonth($request->input('month'));

        if ($selectedMonth !== null && $selectedYear === $currentYear && $selectedMonth > (int) $today->month) {
            $selectedMonth = (int) $today->month;
        }

        $isYearlyView = $selectedMonth === null;
        $isCurrentMonthSelected = $selectedYear === $currentYear && $selectedMonth === (int) $today->month;

        $switchToCurrentMonthUrl = route('cockpit.productivity', [
            'year' => $currentYear,
            'month' => (int) $today->month,
        ]);
        $switchToYearlyUrl = route('cockpit.productivity', [
            'year' => $selectedYear,
            'month' => '',
        ]);

        $previousPeriodUrl = null;
        $nextPeriodUrl = null;
        $previousPeriodLabel = null;
        $nextPeriodLabel = null;

        if ($isYearlyView) {
            if ($selectedYear > $minYear) {
                $previousYear = $selectedYear - 1;
                $previousPeriodUrl = route('cockpit.productivity', ['year' => $previousYear, 'month' => '']);
                $previousPeriodLabel = (string) $previousYear;
            }

            if ($selectedYear < $currentYear) {
                $nextYear = $selectedYear + 1;
                $nextPeriodUrl = route('cockpit.productivity', ['year' => $nextYear, 'month' => '']);
                $nextPeriodLabel = (string) $nextYear;
            }
        } else {
            $currentMonthDate = CarbonImmutable::create(
                $selectedYear,
                (int) $selectedMonth,
                1,
                0,
                0,
                0,
                config('app.timezone')
            )->startOfMonth();
            $previousMonthDate = $currentMonthDate->subMonthNoOverflow();
            $nextMonthDate = $currentMonthDate->addMonthNoOverflow();
            $firstAllowedMonthDate = CarbonImmutable::create($minYear, 1, 1, 0, 0, 0, config('app.timezone'))->startOfMonth();
            $lastAllowedMonthDate = $today->startOfMonth();

            if ($previousMonthDate->gte($firstAllowedMonthDate)) {
                $previousPeriodUrl = route('cockpit.productivity', [
                    'year' => (int) $previousMonthDate->year,
                    'month' => (int) $previousMonthDate->month,
                ]);
                $previousPeriodLabel = ucfirst($previousMonthDate->locale('fr')->isoFormat('MMMM YYYY'));
            }

            if ($nextMonthDate->lte($lastAllowedMonthDate)) {
                $nextPeriodUrl = route('cockpit.productivity', [
                    'year' => (int) $nextMonthDate->year,
                    'month' => (int) $nextMonthDate->month,
                ]);
                $nextPeriodLabel = ucfirst($nextMonthDate->locale('fr')->isoFormat('MMMM YYYY'));
            }
        }

        [$periodStart, $periodEnd, $periodLabel] = $this->resolvePeriod($selectedYear, $selectedMonth, $today);
        $periodMetrics = $togglService->getPeriodMetrics($periodStart, $periodEnd);
        $periodBreakdown = $togglService->getPeriodClientProjectBreakdown($periodStart, $periodEnd);
        $allTimeRecords = $togglService->getAllTimeRecords();

        $daysInPeriod = (int) $periodMetrics['days_in_period'];
        $previousPeriodEnd = $periodStart->subDay();
        $previousPeriodStart = $previousPeriodEnd->subDays($daysInPeriod - 1);
        $previousPeriodMetrics = $togglService->getPeriodMetrics($previousPeriodStart, $previousPeriodEnd);

        $deltaTotalSeconds = (int) $periodMetrics['total_seconds'] - (int) $previousPeriodMetrics['total_seconds'];
        $deltaTotalPercent = $this->computeDeltaPercent(
            (int) $periodMetrics['total_seconds'],
            (int) $previousPeriodMetrics['total_seconds']
        );
        $deltaAverageSeconds = (float) $periodMetrics['daily_average_seconds'] - (float) $previousPeriodMetrics['daily_average_seconds'];

        $monthlyEvolution = $togglService->getMonthlyEvolution($selectedYear, $today);
        $historyYears = max(3, (int) config('toggl.history_years', 5));
        $yearlyEvolution = $togglService->getYearlyEvolution(
            $selectedYear - ($historyYears - 1),
            $selectedYear,
            $today
        );

        $monthlyHours = array_map(fn (int $seconds): float => round($seconds / 3600, 2), $monthlyEvolution['seconds']);
        $yearlyHours = array_map(fn (int $seconds): float => round($seconds / 3600, 2), $yearlyEvolution['seconds']);
        $progressPercent = $periodMetrics['progress_ratio'] * 100;

        $bestMonthIndex = array_search(max($monthlyEvolution['seconds']), $monthlyEvolution['seconds'], true);
        $bestMonthLabel = $bestMonthIndex !== false ? $monthlyEvolution['labels'][$bestMonthIndex] : '-';
        $bestMonthHours = $bestMonthIndex !== false ? number_format($monthlyHours[$bestMonthIndex], 2) : '0.00';
        $activeMonths = count(array_filter($monthlyEvolution['seconds'], static fn (int $seconds): bool => $seconds > 0));

        $monthRows = [];
        $yearTotalSeconds = array_sum($monthlyEvolution['seconds']);
        foreach ($monthlyEvolution['labels'] as $index => $monthLabel) {
            $monthSeconds = $monthlyEvolution['seconds'][$index];
            $monthRows[] = [
                'label' => $monthLabel,
                'hours' => number_format($monthSeconds / 3600, 2),
                'share_percent' => $yearTotalSeconds > 0 ? number_format(($monthSeconds / $yearTotalSeconds) * 100, 1) : '0.0',
            ];
        }

        $defaultHeatmapSyncBudget = (int) config('toggl.heatmap_on_demand_max_sync', 14);
        $monthlyHeatmapSyncBudget = (int) config(
            'toggl.heatmap_month_on_demand_max_sync',
            $defaultHeatmapSyncBudget
        );
        $heatmapSyncBudget = $selectedMonth !== null ? $monthlyHeatmapSyncBudget : $defaultHeatmapSyncBudget;
        [$heatmapStart, $heatmapEnd] = $this->resolveHeatmapPeriod($selectedYear, $selectedMonth);
        $dailyHeatmap = $togglService->getDailyHeatmap($heatmapStart, $heatmapEnd, $heatmapSyncBudget, $today);
        $heatmapUi = $this->buildHeatmapUi($dailyHeatmap['days']);

        $quotaLikely = (bool) ($periodMetrics['quota_limited'] ?? false)
            || (bool) ($periodBreakdown['quota_limited'] ?? false)
            || ((int) ($dailyHeatmap['quota_limited_days'] ?? 0) > 0);
        $fallbackDetected = (bool) ($periodMetrics['has_api_fallback'] ?? false)
            || (bool) ($periodBreakdown['has_api_fallback'] ?? false)
            || ((int) ($monthlyEvolution['fallback_count'] ?? 0) > 0)
            || ((int) ($yearlyEvolution['fallback_count'] ?? 0) > 0)
            || ((int) ($dailyHeatmap['fallback_days'] ?? 0) > 0);
        $missingDays = (int) ($dailyHeatmap['missing_days'] ?? 0);
        $showDataWarning = $quotaLikely || $fallbackDetected || $missingDays > 0;

        $warningTitle = $quotaLikely ? 'Quota API Toggl atteint' : 'Données partielles';
        $warningBody = $quotaLikely
            ? 'Toggl limite temporairement les appels API (quota horaire). Le dashboard affiche les derniers snapshots disponibles et des zones peuvent être incomplètes.'
            : 'Une partie des données n\'a pas encore été synchronisée. Le dashboard affiche les snapshots disponibles.';

        if (!$quotaLikely && $missingDays > 0) {
            $warningBody .= sprintf(' %d jour(s) restent à synchroniser.', $missingDays);
        }
        if ($missingDays > 0 && $heatmapSyncBudget === 0) {
            $warningBody .= ' La synchronisation à la demande du heatmap est désactivée (TOGGL_HEATMAP_ON_DEMAND_MAX_SYNC=0).';
        }

        $yearsForSelect = range($currentYear, $minYear);
        $monthsForSelect = $this->monthOptions();
        $comparisonDirection = $deltaTotalSeconds >= 0 ? 'hausse' : 'baisse';
        $currentDailyAverageHours = (float) $periodMetrics['daily_average_seconds'] / 3600;
        $entrepreneurRows = $this->buildBenchmarkRows($currentDailyAverageHours, $this->entrepreneurBenchmarks());
        $countryRows = $this->buildBenchmarkRows($currentDailyAverageHours, $this->countryBenchmarks());

        $allTimeDayRecord = $allTimeRecords['day'];
        $allTimeMonthRecord = $allTimeRecords['month'];
        $allTimeYearRecord = $allTimeRecords['year'];
        $recordScopeLabel = $selectedMonth !== null ? 'mensuel' : 'annuel';
        $currentScopeRecord = $selectedMonth !== null ? $allTimeMonthRecord : $allTimeYearRecord;
        $currentScopeRecordSeconds = (int) ($currentScopeRecord['seconds'] ?? 0);
        $currentScopeSeconds = (int) $periodMetrics['total_seconds'];
        $recordGapSeconds = $currentScopeRecordSeconds > 0
            ? $currentScopeRecordSeconds - $currentScopeSeconds
            : null;
        $recordGapDirection = 'manque';
        if ($recordGapSeconds !== null && $recordGapSeconds < 0) {
            $recordGapDirection = 'record';
        }
        if ($recordGapSeconds === 0) {
            $recordGapDirection = 'egalite';
        }
        $recordProgressPercent = $currentScopeRecordSeconds > 0
            ? number_format(($currentScopeSeconds / $currentScopeRecordSeconds) * 100, 1)
            : null;

        $trackingSince = $allTimeRecords['tracking_since'];
        $trackingSinceLabel = $trackingSince !== null
            ? ucfirst(CarbonImmutable::parse($trackingSince, config('app.timezone'))->locale('fr')->isoFormat('D MMM YYYY'))
            : null;

        $welcomeTitle = 'Bienvenue monsieur Antunes';
        $motivationMessage = $this->dailyMotivationMessage($today);
        $yesterday = $today->subDay()->startOfDay();
        $dayBeforeYesterday = $yesterday->subDay();
        $last7DaysStart = $yesterday->subDays(6);
        $previous7DaysEnd = $last7DaysStart->subDay();
        $previous7DaysStart = $previous7DaysEnd->subDays(6);

        $yesterdayMetrics = $togglService->getPeriodMetrics($yesterday, $yesterday);
        $dayBeforeYesterdayMetrics = $togglService->getPeriodMetrics($dayBeforeYesterday, $dayBeforeYesterday);
        $last7DaysMetrics = $togglService->getPeriodMetrics($last7DaysStart, $yesterday);
        $previous7DaysMetrics = $togglService->getPeriodMetrics($previous7DaysStart, $previous7DaysEnd);

        $yesterdayTotalSeconds = (int) $yesterdayMetrics['total_seconds'];
        $yesterdayProgressPercent = (float) $yesterdayMetrics['progress_ratio'] * 100;
        $yesterdayGoalHours = (float) $yesterdayMetrics['daily_goal_hours'];
        $yesterdayGoalSeconds = (int) round($yesterdayGoalHours * 3600);
        $yesterdayDeltaSeconds = $yesterdayTotalSeconds - (int) $dayBeforeYesterdayMetrics['total_seconds'];
        $yesterdayDeltaPercent = $this->computeDeltaPercent(
            $yesterdayTotalSeconds,
            (int) $dayBeforeYesterdayMetrics['total_seconds']
        );
        $weeklyDeltaAverageSeconds = (float) $last7DaysMetrics['daily_average_seconds'] - (float) $previous7DaysMetrics['daily_average_seconds'];
        $yesterdayDeltaDirection = $yesterdayDeltaSeconds > 0 ? 'hausse' : ($yesterdayDeltaSeconds < 0 ? 'baisse' : 'stable');
        $weeklyDeltaDirection = $weeklyDeltaAverageSeconds > 0 ? 'hausse' : ($weeklyDeltaAverageSeconds < 0 ? 'baisse' : 'stable');
        $weeklyDeltaPercent = $this->computeDeltaPercent(
            (int) $last7DaysMetrics['total_seconds'],
            (int) $previous7DaysMetrics['total_seconds']
        );

        return view('cockpit.productivity', [
            'selectedYear' => $selectedYear,
            'selectedMonth' => $selectedMonth,
            'currentYear' => $currentYear,
            'currentMonthNumber' => (int) $today->month,
            'isYearlyView' => $isYearlyView,
            'isCurrentMonthSelected' => $isCurrentMonthSelected,
            'switchToCurrentMonthUrl' => $switchToCurrentMonthUrl,
            'switchToYearlyUrl' => $switchToYearlyUrl,
            'previousPeriodUrl' => $previousPeriodUrl,
            'nextPeriodUrl' => $nextPeriodUrl,
            'previousPeriodLabel' => $previousPeriodLabel,
            'nextPeriodLabel' => $nextPeriodLabel,
            'yearsForSelect' => $yearsForSelect,
            'monthsForSelect' => $monthsForSelect,
            'periodLabel' => $periodLabel,
            'startDate' => $periodMetrics['start_date'],
            'endDate' => $periodMetrics['end_date'],
            'daysInPeriod' => $daysInPeriod,
            'totalHours' => $this->formatHours((int) $periodMetrics['total_seconds']),
            'dailyAverageHours' => $this->formatHours((float) $periodMetrics['daily_average_seconds']),
            'dailyGoalHours' => number_format((float) $periodMetrics['daily_goal_hours'], 2),
            'progressPercent' => number_format($progressPercent, 1),
            'progressBarPercent' => number_format(min(100.0, max(0.0, $progressPercent)), 1),
            'bestMonthLabel' => $bestMonthLabel,
            'bestMonthHours' => $bestMonthHours,
            'activeMonths' => $activeMonths,
            'monthlyChartLabels' => $monthlyEvolution['labels'],
            'monthlyChartHours' => $monthlyHours,
            'yearlyChartLabels' => $yearlyEvolution['labels'],
            'yearlyChartHours' => $yearlyHours,
            'monthRows' => $monthRows,
            'periodBreakdownClients' => $periodBreakdown['clients'],
            'periodBreakdownProjects' => $periodBreakdown['projects'],
            'periodBreakdownTotalHours' => $this->formatHours((int) $periodBreakdown['total_seconds']),
            'periodBreakdownHasData' => count($periodBreakdown['projects']) > 0,
            'comparisonLabel' => sprintf(
                'Période précédente: %s → %s',
                $previousPeriodStart->toDateString(),
                $previousPeriodEnd->toDateString()
            ),
            'comparisonDirection' => $comparisonDirection,
            'comparisonTotalDeltaHours' => $this->formatSignedHours($deltaTotalSeconds),
            'comparisonTotalDeltaPercent' => $deltaTotalPercent === null ? null : number_format($deltaTotalPercent, 1),
            'comparisonAverageDeltaHours' => $this->formatSignedHours($deltaAverageSeconds),
            'heatmapWeeks' => $heatmapUi['weeks'],
            'heatmapWeekLabels' => $heatmapUi['week_labels'],
            'heatmapTrackedDays' => $heatmapUi['tracked_days'],
            'heatmapMaxHours' => number_format($heatmapUi['max_seconds'] / 3600, 2),
            'heatmapSyncedDays' => (int) $dailyHeatmap['synced_days'],
            'heatmapMissingDays' => $missingDays,
            'showDataWarning' => $showDataWarning,
            'warningTitle' => $warningTitle,
            'warningBody' => $warningBody,
            'syncTtlMinutes' => (int) config('toggl.sync_ttl_minutes', 240),
            'heatmapSyncBudget' => $heatmapSyncBudget,
            'syncedAt' => CarbonImmutable::parse($periodMetrics['synced_at'])
                ->setTimezone('Europe/Paris')
                ->format('Y-m-d H:i:s').' (Europe/Paris)',
            'allTimeDayRecordHours' => $allTimeDayRecord !== null ? $this->formatHours((int) $allTimeDayRecord['seconds']) : null,
            'allTimeDayRecordDate' => $allTimeDayRecord !== null
                ? ucfirst(CarbonImmutable::parse($allTimeDayRecord['date'], config('app.timezone'))->locale('fr')->isoFormat('D MMM YYYY'))
                : null,
            'allTimeMonthRecordHours' => $allTimeMonthRecord !== null ? $this->formatHours((int) $allTimeMonthRecord['seconds']) : null,
            'allTimeMonthRecordDate' => $allTimeMonthRecord !== null
                ? ucfirst(CarbonImmutable::parse($allTimeMonthRecord['start_date'], config('app.timezone'))->locale('fr')->isoFormat('MMMM YYYY'))
                : null,
            'allTimeYearRecordHours' => $allTimeYearRecord !== null ? $this->formatHours((int) $allTimeYearRecord['seconds']) : null,
            'allTimeYearRecordDate' => $allTimeYearRecord !== null
                ? CarbonImmutable::parse($allTimeYearRecord['start_date'], config('app.timezone'))->isoFormat('YYYY')
                : null,
            'recordScopeLabel' => $recordScopeLabel,
            'recordProgressPercent' => $recordProgressPercent,
            'recordGapHours' => $recordGapSeconds === null ? null : $this->formatHours(abs($recordGapSeconds)),
            'recordGapDirection' => $recordGapDirection,
            'trackingSinceLabel' => $trackingSinceLabel,
            'currentDailyAverageHoursDisplay' => number_format($currentDailyAverageHours, 2),
            'entrepreneurBenchmarks' => $entrepreneurRows,
            'countryBenchmarks' => $countryRows,
            'welcomeTitle' => $welcomeTitle,
            'motivationMessage' => $motivationMessage,
            'yesterdayLabel' => ucfirst($yesterday->locale('fr')->isoFormat('dddd D MMMM YYYY')),
            'yesterdayHours' => $this->formatHours($yesterdayTotalSeconds),
            'yesterdayGoalHours' => number_format($yesterdayGoalHours, 2),
            'yesterdayGoalState' => $yesterdayTotalSeconds >= $yesterdayGoalSeconds ? 'Objectif atteint' : 'Objectif à rattraper',
            'yesterdayProgressPercent' => number_format($yesterdayProgressPercent, 1),
            'yesterdayProgressBarPercent' => number_format(min(100.0, max(0.0, $yesterdayProgressPercent)), 1),
            'yesterdayDeltaHours' => $this->formatSignedHours($yesterdayDeltaSeconds),
            'yesterdayDeltaPercent' => $yesterdayDeltaPercent === null ? null : number_format($yesterdayDeltaPercent, 1),
            'yesterdayDeltaDirection' => $yesterdayDeltaDirection,
            'weeklyAverageHours' => $this->formatHours((float) $last7DaysMetrics['daily_average_seconds']),
            'weeklyDeltaAverageHours' => $this->formatSignedHours($weeklyDeltaAverageSeconds),
            'weeklyDeltaPercent' => $weeklyDeltaPercent === null ? null : number_format($weeklyDeltaPercent, 1),
            'weeklyDeltaDirection' => $weeklyDeltaDirection,
            'weeklyProgressPercent' => number_format(((float) $last7DaysMetrics['progress_ratio']) * 100, 1),
            'weeklyProgressBarPercent' => number_format(min(100.0, max(0.0, ((float) $last7DaysMetrics['progress_ratio']) * 100)), 1),
        ]);
    }

    private function formatHours(int|float $seconds): string
    {
        return number_format($seconds / 3600, 2);
    }

    private function formatSignedHours(int|float $seconds): string
    {
        $hours = $seconds / 3600;
        $prefix = $hours > 0 ? '+' : '';

        return $prefix.number_format($hours, 2);
    }

    private function computeDeltaPercent(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function dailyMotivationMessage(CarbonImmutable $today): string
    {
        $messages = [
            'La constance gagne toujours: une petite avance aujourd\'hui vaut une grande promesse demain.',
            'Tu n\'as pas besoin d\'être parfait, seulement régulier et déterminé.',
            'Ton rythme d\'aujourd\'hui construit ta liberté de demain.',
            'Chaque heure de focus est un investissement qui se cumule.',
            'Continue d\'avancer: la discipline transforme l\'effort en résultats.',
            'Le meilleur momentum, c\'est celui que tu protèges chaque jour.',
            'Ton cap est bon: garde l\'exigence, simplifie l\'exécution.',
            'Steve Jobs: l\'innovation, c\'est aussi dire non a ce qui disperse ton energie.',
            'Steve Jobs: rester affamé, rester audacieux, c\'est une discipline quotidienne.',
            'Tony Robbins: la qualité de tes décisions détermine la qualité de ta vie.',
            'Tony Robbins: l\'énergie suit le focus, donc choisis ton focus avec intention.',
            'Tony Robbins: ce que tu pratiques chaque jour devient ton standard.',
            'Grant Cardone: vise 10x, puis agis plus vite que ton doute.',
            'Grant Cardone: le succès est un devoir quand tu prends tes responsabilités.',
            'Grant Cardone: la moyenne est un piège, joue le niveau au-dessus.',
            'Elon Musk: la persistance finit par battre la complexité.',
            'Elon Musk: quand quelque chose compte vraiment, tu avances malgré l\'incertitude.',
            'Jeff Bezos: pense long terme, exécute court terme.',
            'Jeff Bezos: la discipline aujourd\'hui finance la liberté de demain.',
            'Naval Ravikant: l\'effet composé récompense les efforts cohérents.',
            'Naval Ravikant: la constance discrète produit des résultats publics.',
            'Warren Buffett: les habitudes s\'installent vite, choisis les bonnes.',
            'Warren Buffett: construire ta crédibilité demande des années, protège-la chaque jour.',
            'Peter Drucker: ce qui se mesure s\'améliore, ce qui s\'ignore stagne.',
            'Peter Drucker: l\'efficacité, c\'est faire d\'abord l\'essentiel.',
            'Jim Rohn: tu ne peux pas changer ta destination sans changer ta direction.',
            'Jim Rohn: la discipline coûte peu, le regret coûte cher.',
            'Robin Sharma: posséder sa matinée, c\'est posséder sa journée.',
            'Robin Sharma: l\'excellence n\'est pas un acte, c\'est une habitude.',
            'David Goggins: le mental se forge quand tu avances quand c\'est difficile.',
            'David Goggins: inconfort aujourd\'hui, puissance demain.',
            'Andrew Carnegie: concentre ton énergie sur un objectif clair.',
            'Napoleon Hill: un but net transforme l\'effort en progression réelle.',
            'Brian Tracy: commence par la tâche importante, le reste devient plus simple.',
            'Brian Tracy: la clarté crée l\'élan.',
            'Cal Newport: le deep work est un avantage compétitif rare.',
            'Cal Newport: moins de distraction, plus de valeur.',
            'James Clear: chaque vote quotidien construit ton identité.',
            'James Clear: les petits gains répétés battent les grands élans rares.',
            'Seth Godin: mieux vaut être régulier que brillant une fois par mois.',
            'Seth Godin: expédie, apprends, améliore.',
            'Jocko Willink: discipline égale liberté.',
            'Jocko Willink: priorité, exécution, répétition.',
            'Ray Dalio: douleur plus réflexion égale progrès.',
            'Ray Dalio: cherche la vérité, puis ajuste vite.',
            'Simon Sinek: commence avec un pourquoi fort, puis exécute sans bruit.',
            'Simon Sinek: la confiance naît de la cohérence.',
            'Jack Ma: aujourd\'hui est difficile, demain aussi, mais après-demain peut être magnifique.',
            'Jack Ma: la patience stratégique amplifie les efforts quotidiens.',
            'Bill Gates: la plupart surestiment un an et sous-estiment dix ans.',
            'Bill Gates: la cadence l\'emporte sur l\'intensité ponctuelle.',
            'Oprah Winfrey: transforme tes blessures en sagesse opérationnelle.',
            'Oprah Winfrey: l\'intention claire attire l\'action alignée.',
            'Michael Jordan: le talent gagne des matchs, le travail gagne des saisons.',
            'Michael Jordan: les fondamentaux répétés créent la confiance.',
            'Kobe Bryant: la mentalité Mamba, c\'est améliorer un détail de plus chaque jour.',
            'Kobe Bryant: la pression révèle ta préparation.',
            'Leaders construisent la confiance en tenant leurs promesses quotidiennes.',
            'Un jour maîtrisé vaut plus qu\'une semaine improvisée.',
            'Ta meilleure version vient de tes routines, pas de tes intentions.',
            'Si c\'est important, bloque du temps et protège-le.',
            'Ce que tu refuses aujourd\'hui libère ton focus pour l\'essentiel.',
            'Progresser, c\'est répéter le bon geste assez longtemps.',
            'Reste simple: plan clair, exécution propre, revue honnête.',
            'Tu n\'as pas besoin de motivation parfaite, seulement d\'une prochaine action claire.',
            'Ce cockpit n\'est pas un tableau: c\'est ton engagement visible.',
            'Garde le cap: effort mesuré, progression assumée, résultats cumulés.',
            'Chaque session terminée renforce ton identité de bâtisseur.',
            'Tu ne cherches pas la facilité, tu construis la maîtrise.',
            'La victoire du jour: faire ce qui compte avant ce qui rassure.',
            'Ton futur te remerciera pour la rigueur d\'aujourd\'hui.',
            'L\'excellence aime la répétition plus que l\'excitation.',
            'Tu avances mieux quand tu choisis moins, mais mieux.',
            'Le progrès visible commence par une promesse tenue en silence.',
            'Discipline calme, ambition élevée, exécution nette.',
            'Quand tu hésites, reviens au plan et lance le premier bloc.',
            'L\'effort n\'est pas une humeur: c\'est une décision.',
            'Tu n\'es pas en retard: tu es en construction, continue.',
            'Le temps que tu protèges devient le résultat que tu cherches.',
            'Aujourd\'hui encore, tu peux gagner la journée avec une seule priorité tenue.',
        ];

        $index = ((int) $today->dayOfYear - 1) % count($messages);

        return $messages[$index];
    }

    /**
     * @param array<int, array{date: string, seconds: int, synced: bool}> $days
     * @return array{
     *   tracked_days: int,
     *   max_seconds: int,
     *   weeks: array<int, array<int, array{type: string, label?: string, date?: string, hours?: string, color_class?: string, is_missing?: bool}>>,
     *   week_labels: array<int, string>
     * }
     */
    private function buildHeatmapUi(array $days): array
    {
        if ($days === []) {
            return [
                'tracked_days' => 0,
                'max_seconds' => 0,
                'weeks' => [],
                'week_labels' => [],
            ];
        }

        $maxSeconds = max(array_map(static fn (array $day): int => (int) $day['seconds'], $days));
        $trackedDays = count(array_filter($days, static fn (array $day): bool => (int) $day['seconds'] > 0));
        $colorScale = [
            0 => 'bg-slate-100',
            1 => 'bg-emerald-100',
            2 => 'bg-emerald-300',
            3 => 'bg-emerald-500',
            4 => 'bg-emerald-700',
        ];

        $firstDay = CarbonImmutable::parse($days[0]['date'], config('app.timezone'));
        $cells = [];
        for ($i = 1; $i < $firstDay->dayOfWeekIso; $i++) {
            $cells[] = ['type' => 'empty'];
        }

        foreach ($days as $day) {
            $seconds = (int) $day['seconds'];
            $isSynced = (bool) ($day['synced'] ?? false);
            $level = $isSynced ? $this->resolveHeatLevel($seconds, $maxSeconds) : 0;
            $date = CarbonImmutable::parse($day['date'], config('app.timezone'));
            $cells[] = [
                'type' => 'day',
                'label' => ucfirst($date->locale('fr')->isoFormat('ddd D MMM')),
                'date' => $date->toDateString(),
                'hours' => $isSynced ? number_format($seconds / 3600, 2) : 'a completer',
                'color_class' => $isSynced ? $colorScale[$level] : 'bg-white',
                'is_missing' => !$isSynced,
            ];
        }

        $weeks = array_chunk($cells, 7);
        foreach ($weeks as &$week) {
            while (count($week) < 7) {
                $week[] = ['type' => 'empty'];
            }
        }
        unset($week);

        $weekLabels = [];
        $previousNonEmptyLabel = '';
        foreach ($weeks as $index => $week) {
            $label = '';
            foreach ($week as $cell) {
                if (($cell['type'] ?? '') !== 'day' || !isset($cell['date'])) {
                    continue;
                }

                $date = CarbonImmutable::parse((string) $cell['date'], config('app.timezone'));
                if ($index === 0 || $date->day <= 7) {
                    $label = ucfirst($date->locale('fr')->isoFormat('MMM'));
                }
                break;
            }

            if ($label !== '' && $label === $previousNonEmptyLabel) {
                $label = '';
            }

            if ($label !== '') {
                $previousNonEmptyLabel = $label;
            }

            $weekLabels[] = $label;
        }

        return [
            'tracked_days' => $trackedDays,
            'max_seconds' => $maxSeconds,
            'weeks' => $weeks,
            'week_labels' => $weekLabels,
        ];
    }

    private function resolveHeatLevel(int $seconds, int $maxSeconds): int
    {
        if ($seconds <= 0 || $maxSeconds <= 0) {
            return 0;
        }

        $ratio = $seconds / $maxSeconds;
        if ($ratio < 0.25) {
            return 1;
        }

        if ($ratio < 0.50) {
            return 2;
        }

        if ($ratio < 0.75) {
            return 3;
        }

        return 4;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolvePeriod(int $year, ?int $month, CarbonImmutable $today): array
    {
        if ($month === null) {
            $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'))->startOfYear();
            $end = $year === (int) $today->year
                ? $today
                : $start->endOfYear();

            return [$start, $end, sprintf('Année %d', $year)];
        }

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))->startOfMonth();
        $end = $start->endOfMonth();
        if ($year === (int) $today->year && $month === (int) $today->month) {
            $end = $today;
        }

        return [$start, $end, ucfirst($start->locale('fr')->isoFormat('MMMM YYYY'))];
    }

    private function resolveMonth(mixed $month): ?int
    {
        if ($month === null || $month === '') {
            return null;
        }

        if (!is_numeric($month)) {
            return null;
        }

        return max(1, min(12, (int) $month));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveHeatmapPeriod(int $year, ?int $month): array
    {
        if ($month === null) {
            $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'))->startOfYear();

            return [$start, $start->endOfYear()];
        }

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))->startOfMonth();

        return [$start, $start->endOfMonth()];
    }

    /**
     * @return array<int, string>
     */
    private function monthOptions(): array
    {
        $options = [];
        for ($month = 1; $month <= 12; $month++) {
            $date = CarbonImmutable::create(2026, $month, 1, 0, 0, 0, config('app.timezone'));
            $options[$month] = ucfirst($date->locale('fr')->isoFormat('MMMM'));
        }

        return $options;
    }

    /**
     * @return array<int, array{name: string, daily_hours: float, note: string}>
     */
    private function entrepreneurBenchmarks(): array
    {
        $defaults = [
            ['name' => 'Elon Musk', 'daily_hours' => 16.0, 'note' => 'Repere populaire (forte intensite)'],
            ['name' => 'Jack Ma', 'daily_hours' => 12.0, 'note' => 'Modele 996 (~12 h/j)'],
            ['name' => 'Jeff Bezos', 'daily_hours' => 10.0, 'note' => 'Charge elevee sur phases de croissance'],
            ['name' => 'Bill Gates', 'daily_hours' => 9.5, 'note' => 'Longues journees en debut de parcours'],
        ];

        return $this->sanitizeBenchmarkConfig(config('benchmarks.entrepreneurs', $defaults), $defaults);
    }

    /**
     * @return array<int, array{name: string, daily_hours: float, note: string}>
     */
    private function countryBenchmarks(): array
    {
        $defaults = [
            ['name' => 'France', 'daily_hours' => 7.0, 'note' => 'Base hebdo proche 35 h'],
            ['name' => 'USA', 'daily_hours' => 8.1, 'note' => 'Base hebdo proche 40-41 h'],
            ['name' => 'Canada', 'daily_hours' => 8.0, 'note' => 'Base hebdo proche 40 h'],
            ['name' => 'Royaume-Uni', 'daily_hours' => 7.6, 'note' => 'Base hebdo proche 38 h'],
            ['name' => 'Allemagne', 'daily_hours' => 7.4, 'note' => 'Base hebdo proche 37 h'],
            ['name' => 'Japon', 'daily_hours' => 8.3, 'note' => 'Charge moyenne historiquement elevee'],
        ];

        return $this->sanitizeBenchmarkConfig(config('benchmarks.countries', $defaults), $defaults);
    }

    /**
     * @param array<int, array{name: string, daily_hours: float, note: string}> $benchmarks
     * @return array<int, array{
     *   name: string,
     *   daily_hours: string,
     *   note: string,
     *   delta_hours: string,
     *   delta_direction: string,
     *   relative_percent: string
     * }>
     */
    private function buildBenchmarkRows(float $currentDailyHours, array $benchmarks): array
    {
        $rows = [];

        foreach ($benchmarks as $benchmark) {
            $targetHours = (float) $benchmark['daily_hours'];
            $delta = $currentDailyHours - $targetHours;
            $deltaDirection = 'egal';
            if ($delta > 0) {
                $deltaDirection = 'au-dessus';
            } elseif ($delta < 0) {
                $deltaDirection = 'en-dessous';
            }

            $rows[] = [
                'name' => (string) $benchmark['name'],
                'daily_hours' => number_format($targetHours, 2),
                'note' => (string) $benchmark['note'],
                'delta_hours' => number_format(abs($delta), 2),
                'delta_direction' => $deltaDirection,
                'relative_percent' => $targetHours > 0
                    ? number_format(($currentDailyHours / $targetHours) * 100, 1)
                    : '0.0',
            ];
        }

        return $rows;
    }

    /**
     * @param mixed $benchmarks
     * @param array<int, array{name: string, daily_hours: float, note: string}> $defaults
     * @return array<int, array{name: string, daily_hours: float, note: string}>
     */
    private function sanitizeBenchmarkConfig(mixed $benchmarks, array $defaults): array
    {
        if (!is_array($benchmarks) || $benchmarks === []) {
            return $defaults;
        }

        $rows = [];
        foreach ($benchmarks as $benchmark) {
            if (!is_array($benchmark)) {
                continue;
            }

            $name = isset($benchmark['name']) ? trim((string) $benchmark['name']) : '';
            $note = isset($benchmark['note']) ? trim((string) $benchmark['note']) : '';
            $hours = isset($benchmark['daily_hours']) && is_numeric($benchmark['daily_hours'])
                ? (float) $benchmark['daily_hours']
                : null;

            if ($name === '' || $note === '' || $hours === null || $hours <= 0.0) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'daily_hours' => $hours,
                'note' => $note,
            ];
        }

        return $rows === [] ? $defaults : $rows;
    }
}
