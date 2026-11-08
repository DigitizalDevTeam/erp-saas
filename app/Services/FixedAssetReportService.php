<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetAuditEvent;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationRun;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetMaintenanceCost;
use App\Models\FixedAssetMaintenanceRequest;
use App\Models\FixedAssetPhysicalVerification;
use App\Models\FixedAssetTransfer;
use App\Models\FixedAssetVerificationItem;
use App\Models\JournalItem;
use App\Models\User;
use Carbon\Carbon;

/**
 * Fixed Assets Phase 6 — dashboard + reports (plan §9, §10).
 *
 * READ-ONLY aggregation. This service never creates a posting, a
 * journal, an asset or a workflow row: every figure is computed
 * from the data Phases 0–5 already wrote. The dashboard and the
 * register report share one totals method (registerTotals()), so
 * the two can never disagree — a dashboard total that differs
 * from the register report is a bug, not a rounding difference.
 *
 * Money is base currency throughout (capitalized_cost,
 * current_book_value and posted amounts are all stored in base
 * currency by Phases 1–3); the asset's own currency is shown for
 * reference only. All sums are bcmath, never float.
 *
 * Every query is scoped by creatorId(). A lost / stolen / damaged
 * asset keeps depreciating until it is written off (Phase 3
 * decision) — the depreciation report flags those assets
 * explicitly instead of letting them depreciate silently.
 */
class FixedAssetReportService
{
    public const REPORT_REGISTER = 'register';
    public const REPORT_ACQUISITION = 'acquisition';
    public const REPORT_DEPRECIATION = 'depreciation';
    public const REPORT_MOVEMENTS = 'movements';
    public const REPORT_MAINTENANCE = 'maintenance';
    public const REPORT_DISPOSAL = 'disposal';
    public const REPORT_VERIFICATION = 'verification';
    public const REPORT_CONTROL = 'control';
    public const REPORT_AUDIT_TRAIL = 'audit-trail';

    public const REPORTS = [
        self::REPORT_REGISTER => 'Asset Register',
        self::REPORT_ACQUISITION => 'Acquisitions & Capitalization',
        self::REPORT_DEPRECIATION => 'Depreciation Schedule',
        self::REPORT_MOVEMENTS => 'Movements',
        self::REPORT_MAINTENANCE => 'Maintenance Cost',
        self::REPORT_DISPOSAL => 'Disposals & Gains/Losses',
        self::REPORT_VERIFICATION => 'Verification Exceptions',
        self::REPORT_CONTROL => 'Control & Reconciliation',
        self::REPORT_AUDIT_TRAIL => 'Audit Trail',
    ];

    /** Assets that left the books — excluded from live totals. */
    public const GONE_STATUSES = [
        FixedAsset::STATUS_DISPOSED,
        FixedAsset::STATUS_WRITTEN_OFF,
    ];

    /** Assets that keep depreciating until written off (Phase 3 decision). */
    public const DEPRECIATING_WHILE_EXCEPTION_STATUSES = [
        FixedAsset::STATUS_LOST,
        FixedAsset::STATUS_STOLEN,
        FixedAsset::STATUS_DAMAGED,
    ];

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * Normalise raw request input into the filter shape every report
     * understands. Invalid dates are dropped, never guessed; a from
     * after a to is swapped so the range still means something.
     *
     * @return array{from: ?string, to: ?string, category_id: ?int, location_id: ?int, status: ?string, custodian_id: ?int, branch_id: ?int, cost_centre_id: ?int, search: ?string, days: int}
     */
    public function normalizeFilters(array $input): array
    {
        $from = $this->parseDate($input['from'] ?? null);
        $to = $this->parseDate($input['to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $id = fn ($key) => isset($input[$key]) && ctype_digit((string) $input[$key])
            ? (int) $input[$key] : null;

        return [
            'from' => $from,
            'to' => $to,
            'category_id' => $id('category_id'),
            'location_id' => $id('location_id'),
            'status' => isset($input['status']) && in_array($input['status'], FixedAsset::STATUSES, true)
                ? $input['status'] : null,
            'custodian_id' => $id('custodian_id'),
            'branch_id' => $id('branch_id'),
            'cost_centre_id' => $id('cost_centre_id'),
            'search' => isset($input['search']) && trim((string) $input['search']) !== ''
                ? substr(trim((string) $input['search']), 0, 100) : null,
            'days' => isset($input['days']) && ctype_digit((string) $input['days'])
                ? max(1, min(365, (int) $input['days'])) : 30,
        ];
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Base-currency label for totals (default MZN). */
    public function baseCurrency(int $creatorId): string
    {
        return FixedAssetSettings::baseCurrency($creatorId);
    }

    // ------------------------------------------------------------------
    // Shared totals — dashboard and register report read the same one
    // ------------------------------------------------------------------

    /**
     * Live register totals for a company (and optional filters).
     * The dashboard and the register report both call this, so the
     * two can never disagree. Cost / accumulated depreciation /
     * book value are base currency; accumulated depreciation is
     * opening accumulated depreciation + posted entries.
     *
     * @return array{count: int, live_count: int, total_cost: string, total_accum_dep: string, total_book_value: string, disposed_cost: string, base_currency: string}
     */
    public function registerTotals(int $creatorId, array $filters = []): array
    {
        $assets = $this->filteredAssets($creatorId, $filters)->get();
        $postedByAsset = $this->postedByAsset($creatorId);

        $count = $assets->count();
        $liveCount = 0;
        $totalCost = '0';
        $totalAccum = '0';
        $totalBook = '0';
        $disposedCost = '0';

        foreach ($assets as $asset) {
            $cost = (string) ($asset->capitalized_cost ?? '0');
            $accum = bcadd(
                (string) ($asset->opening_accumulated_depreciation ?? '0'),
                $postedByAsset[(int) $asset->id] ?? '0',
                4
            );

            if (in_array($asset->status, self::GONE_STATUSES, true)) {
                $disposedCost = bcadd($disposedCost, $cost, 4);
                continue;
            }

            $liveCount++;
            $totalCost = bcadd($totalCost, $cost, 4);
            $totalAccum = bcadd($totalAccum, $accum, 4);
            $totalBook = bcadd($totalBook, (string) ($asset->current_book_value ?? '0'), 4);
        }

        return [
            'count' => $count,
            'live_count' => $liveCount,
            'total_cost' => $totalCost,
            'total_accum_dep' => $totalAccum,
            'total_book_value' => $totalBook,
            'disposed_cost' => $disposedCost,
            'base_currency' => $this->baseCurrency($creatorId),
        ];
    }

    /** Assets for a company, with the report filters applied. */
    protected function filteredAssets(int $creatorId, array $filters)
    {
        return FixedAsset::forCompany($creatorId)
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['custodian_id'] ?? null, fn ($q, $v) => $q->where('custodian_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['cost_centre_id'] ?? null, fn ($q, $v) => $q->where('cost_centre_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('code', 'like', '%'.$v.'%')
                        ->orWhere('name', 'like', '%'.$v.'%')
                        ->orWhere('serial_number', 'like', '%'.$v.'%');
                });
            })
            ->orderBy('code');
    }

    /** Posted depreciation per asset id (2dp strings), this company only. */
    protected function postedByAsset(int $creatorId): array
    {
        $rows = FixedAssetDepreciationEntry::where('created_by', $creatorId)
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->selectRaw('asset_id, SUM(posted_amount) as total')
            ->groupBy('asset_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->asset_id] = bcadd((string) $row->total, '0', 2);
        }

        return $out;
    }

    /** Accumulated depreciation for one asset: opening + posted. */
    public function accumDepForAsset(FixedAsset $asset, int $creatorId, ?array $postedByAsset = null): string
    {
        $postedByAsset ??= $this->postedByAsset($creatorId);

        return bcadd(
            (string) ($asset->opening_accumulated_depreciation ?? '0'),
            $postedByAsset[(int) $asset->id] ?? '0',
            4
        );
    }

    // ------------------------------------------------------------------
    // Dashboard (plan §9)
    // ------------------------------------------------------------------

    /**
     * Dashboard KPIs (plan §9). Totals come from registerTotals()
     * — the same method the register report uses — so the dashboard
     * can never disagree with the register.
     */
    public function dashboard(int $creatorId, int $days = 30): array
    {
        $totals = $this->registerTotals($creatorId);
        $assets = FixedAsset::forCompany($creatorId)->with(['category', 'location', 'custodian'])->get();
        $postedByAsset = $this->postedByAsset($creatorId);
        $now = Carbon::now();
        $periodStart = $now->copy()->startOfMonth()->format('Y-m-d');
        $periodEnd = $now->copy()->endOfMonth()->format('Y-m-d');

        $byCategory = [];
        $byLocation = [];
        $byStatus = [];
        $unassigned = 0;
        $fullyDepreciated = 0;
        $underMaintenance = 0;
        $warrantyExpiring = [];
        $highValue = [];
        $threshold = FixedAssetSettings::approvalThresholdBase($creatorId);

        foreach ($assets as $asset) {
            $live = ! in_array($asset->status, self::GONE_STATUSES, true);
            $cost = (string) ($asset->capitalized_cost ?? '0');
            $book = (string) ($asset->current_book_value ?? '0');

            $catKey = $asset->category ? $asset->category->code : '—';
            $byCategory[$catKey] = $byCategory[$catKey] ?? ['label' => $catKey, 'count' => 0, 'cost' => '0', 'book_value' => '0'];
            $byCategory[$catKey]['count']++;
            if ($live) {
                $byCategory[$catKey]['cost'] = bcadd($byCategory[$catKey]['cost'], $cost, 4);
                $byCategory[$catKey]['book_value'] = bcadd($byCategory[$catKey]['book_value'], $book, 4);
            }

            $locKey = $asset->location ? $asset->location->code : '—';
            $byLocation[$locKey] = $byLocation[$locKey] ?? ['label' => $locKey, 'count' => 0, 'cost' => '0', 'book_value' => '0'];
            $byLocation[$locKey]['count']++;
            if ($live) {
                $byLocation[$locKey]['cost'] = bcadd($byLocation[$locKey]['cost'], $cost, 4);
                $byLocation[$locKey]['book_value'] = bcadd($byLocation[$locKey]['book_value'], $book, 4);
            }

            $byStatus[$asset->status] = $byStatus[$asset->status] ?? ['label' => $asset->status, 'count' => 0, 'cost' => '0', 'book_value' => '0'];
            $byStatus[$asset->status]['count']++;
            if ($live) {
                $byStatus[$asset->status]['cost'] = bcadd($byStatus[$asset->status]['cost'], $cost, 4);
                $byStatus[$asset->status]['book_value'] = bcadd($byStatus[$asset->status]['book_value'], $book, 4);
            }

            if ($live && $asset->custodian_id === null) {
                $unassigned++;
            }
            if ($asset->status === FixedAsset::STATUS_FULLY_DEPRECIATED) {
                $fullyDepreciated++;
            }
            if ($asset->status === FixedAsset::STATUS_UNDER_MAINTENANCE) {
                $underMaintenance++;
            }
            if ($live && $asset->warranty_end !== null
                && $asset->warranty_end->format('Y-m-d') >= $now->format('Y-m-d')
                && $asset->warranty_end->format('Y-m-d') <= $now->copy()->addDays($days)->format('Y-m-d')
            ) {
                $warrantyExpiring[] = [
                    'code' => $asset->code,
                    'name' => $asset->name,
                    'warranty_end' => $asset->warranty_end->format('Y-m-d'),
                ];
            }
            if ($live && bccomp($cost, $threshold, 4) >= 0) {
                $highValue[] = [
                    'code' => $asset->code,
                    'name' => $asset->name,
                    'cost' => $cost,
                    'book_value' => $book,
                    'status' => $asset->status,
                ];
            }
        }

        usort($highValue, fn ($a, $b) => bccomp($b['cost'], $a['cost'], 4));

        $depreciationThisPeriod = bcadd((string) FixedAssetDepreciationEntry::where('created_by', $creatorId)
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->whereDate('period', '>=', $periodStart)
            ->whereDate('period', '<=', $periodEnd)
            ->sum('posted_amount'), '0', 2);

        $disposalsThisPeriod = FixedAssetDisposal::forCompany($creatorId)
            ->where('status', FixedAssetDisposal::STATUS_EXECUTED)
            ->whereDate('executed_at', '>=', $periodStart)
            ->whereDate('executed_at', '<=', $periodEnd)
            ->get();
        $disposalProceeds = '0';
        $disposalGain = '0';
        $disposalLoss = '0';
        foreach ($disposalsThisPeriod as $disposal) {
            $disposalProceeds = bcadd($disposalProceeds, (string) ($disposal->actual_proceeds ?? '0'), 2);
            $gl = (string) ($disposal->gain_loss_amount ?? '0');
            if (bccomp($gl, '0', 2) > 0) {
                $disposalGain = bcadd($disposalGain, $gl, 2);
            } elseif (bccomp($gl, '0', 2) < 0) {
                $disposalLoss = bcadd($disposalLoss, bcsub('0', $gl, 2), 2);
            }
        }

        $maintenanceCostThisPeriod = bcadd((string) FixedAssetMaintenanceCost::where('created_by', $creatorId)
            ->whereDate('recorded_at', '>=', $periodStart)
            ->whereDate('recorded_at', '<=', $periodEnd)
            ->sum('amount'), '0', 2);

        $openCampaigns = FixedAssetPhysicalVerification::forCompany($creatorId)
            ->where('status', FixedAssetPhysicalVerification::STATUS_IN_PROGRESS)
            ->get();
        $overdueCampaigns = $openCampaigns->filter(
            fn ($c) => $c->deadline !== null && $c->deadline->format('Y-m-d') < $now->format('Y-m-d')
        )->count();
        $campaignIds = $openCampaigns->pluck('id')->all();
        $checked = 0;
        $campaignTotal = 0;
        if ($campaignIds !== []) {
            $checked = FixedAssetVerificationItem::where('created_by', $creatorId)
                ->whereIn('verification_id', $campaignIds)
                ->where('result', '!=', FixedAssetVerificationItem::RESULT_PENDING)
                ->count();
            $campaignTotal = FixedAssetVerificationItem::where('created_by', $creatorId)
                ->whereIn('verification_id', $campaignIds)
                ->count();
        }
        $missingAssets = FixedAsset::forCompany($creatorId)
            ->where('status', FixedAsset::STATUS_LOST)
            ->count();

        return [
            'totals' => $totals,
            'by_category' => array_values($byCategory),
            'by_location' => array_values($byLocation),
            'by_status' => array_values($byStatus),
            'depreciation_this_period' => $depreciationThisPeriod,
            'period_label' => $now->format('Y-m'),
            'disposals_this_period' => [
                'count' => $disposalsThisPeriod->count(),
                'proceeds' => $disposalProceeds,
                'gain' => $disposalGain,
                'loss' => $disposalLoss,
            ],
            'pending_approvals' => [
                'acquisitions' => FixedAssetAcquisition::forCompany($creatorId)
                    ->where('status', FixedAssetAcquisition::STATUS_PENDING_APPROVAL)->count(),
                'disposals' => FixedAssetDisposal::forCompany($creatorId)
                    ->where('status', FixedAssetDisposal::STATUS_REQUESTED)->count(),
                'depreciation_runs' => FixedAssetDepreciationRun::forCompany($creatorId)
                    ->whereIn('status', [
                        FixedAssetDepreciationRun::STATUS_CALCULATED,
                        FixedAssetDepreciationRun::STATUS_REVIEWED,
                    ])->count(),
            ],
            'unassigned' => $unassigned,
            'fully_depreciated' => $fullyDepreciated,
            'under_maintenance' => $underMaintenance,
            'open_maintenance_requests' => FixedAssetMaintenanceRequest::forCompany($creatorId)
                ->whereIn('status', [
                    FixedAssetMaintenanceRequest::STATUS_REQUESTED,
                    FixedAssetMaintenanceRequest::STATUS_APPROVED,
                    FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS,
                ])->count(),
            'maintenance_cost_this_period' => $maintenanceCostThisPeriod,
            'warranty_expiring' => $warrantyExpiring,
            'verification' => [
                'open_campaigns' => $openCampaigns->count(),
                'overdue_campaigns' => $overdueCampaigns,
                'missing_assets' => $missingAssets,
                'checked' => $checked,
                'total' => $campaignTotal,
            ],
            'proposed_for_disposal' => FixedAssetDisposal::forCompany($creatorId)
                ->whereIn('status', [
                    FixedAssetDisposal::STATUS_REQUESTED,
                    FixedAssetDisposal::STATUS_APPROVED,
                ])->count(),
            'lost_still_depreciating' => $this->lostStillDepreciating($creatorId),
            'high_value' => $highValue,
            'recent_events' => $this->auditTrailRows($creatorId, $this->auditTrailQuery($creatorId, []), 10),
            'base_currency' => $this->baseCurrency($creatorId),
            'approval_threshold' => $threshold,
        ];
    }

    /**
     * Lost / stolen / damaged assets that still have planned
     * depreciation rows left — the Phase 3 decision (they keep
     * depreciating until written off) made visible, not silent.
     *
     * @return array<int, array{code: string, name: string, status: string, book_value: string, planned_remaining: int}>
     */
    public function lostStillDepreciating(int $creatorId): array
    {
        $assets = FixedAsset::forCompany($creatorId)
            ->whereIn('status', self::DEPRECIATING_WHILE_EXCEPTION_STATUSES)
            ->orderBy('code')
            ->get();

        $out = [];
        foreach ($assets as $asset) {
            $planned = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
                ->count();
            if ($planned === 0) {
                continue;
            }
            $out[] = [
                'code' => $asset->code,
                'name' => $asset->name,
                'status' => $asset->status,
                'book_value' => (string) ($asset->current_book_value ?? '0'),
                'planned_remaining' => $planned,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Reports — one entry point (plan §9)
    // ------------------------------------------------------------------

    /**
     * Build one report: title, description, headings, rows and
     * totals. Screen, CSV, XLSX and PDF all read this, so an export
     * can never disagree with its screen. Rows are plain value
     * arrays; totals are label => value strings for the PDF footer.
     */
    public function report(int $creatorId, string $report, array $filters): array
    {
        if (! isset(self::REPORTS[$report])) {
            throw new \InvalidArgumentException('Unknown fixed asset report: '.$report);
        }

        return match ($report) {
            self::REPORT_REGISTER => $this->registerReport($creatorId, $filters),
            self::REPORT_ACQUISITION => $this->acquisitionReport($creatorId, $filters),
            self::REPORT_DEPRECIATION => $this->depreciationReport($creatorId, $filters),
            self::REPORT_MOVEMENTS => $this->movementsReport($creatorId, $filters),
            self::REPORT_MAINTENANCE => $this->maintenanceReport($creatorId, $filters),
            self::REPORT_DISPOSAL => $this->disposalReport($creatorId, $filters),
            self::REPORT_VERIFICATION => $this->verificationReport($creatorId, $filters),
            self::REPORT_CONTROL => $this->controlReport($creatorId, $filters),
            self::REPORT_AUDIT_TRAIL => $this->auditTrailReport($creatorId, $filters),
        };
    }

    // ------------------------------------------------------------------
    // Register
    // ------------------------------------------------------------------

    protected function registerReport(int $creatorId, array $filters): array
    {
        $assets = $this->filteredAssets($creatorId, $filters)
            ->with(['category', 'location', 'custodian'])
            ->get();
        $postedByAsset = $this->postedByAsset($creatorId);
        $totals = $this->registerTotals($creatorId, $filters);

        $rows = [];
        foreach ($assets as $asset) {
            $rows[] = [
                $asset->code,
                $asset->name,
                $asset->category ? $asset->category->code : '—',
                $asset->location ? $asset->location->code : '—',
                $asset->custodian ? $asset->custodian->name : '—',
                ucfirst(str_replace('_', ' ', $asset->status)),
                $this->money((string) ($asset->capitalized_cost ?? '0')),
                $this->money($this->accumDepForAsset($asset, $creatorId, $postedByAsset)),
                $this->money((string) ($asset->current_book_value ?? '0')),
                $asset->currency ?? $totals['base_currency'],
            ];
        }

        return [
            'title' => 'Asset Register',
            'description' => 'Every fixed asset in the register with its cost, accumulated depreciation and net book value (base currency '.$totals['base_currency'].'). Disposed and written-off assets stay in the register with book value 0.',
            'headings' => ['Code', 'Asset', 'Category', 'Location', 'Custodian', 'Status', 'Cost', 'Accum. Dep.', 'Net Book Value', 'Currency'],
            'rows' => $rows,
            'totals' => [
                'Assets' => (string) $totals['count'],
                'Live assets' => (string) $totals['live_count'],
                'Total cost ('.$totals['base_currency'].')' => $this->money($totals['total_cost']),
                'Accum. depreciation ('.$totals['base_currency'].')' => $this->money($totals['total_accum_dep']),
                'Net book value ('.$totals['base_currency'].')' => $this->money($totals['total_book_value']),
                'Disposed cost ('.$totals['base_currency'].')' => $this->money($totals['disposed_cost']),
            ],
            'groups' => $this->registerGroups($assets, $postedByAsset),
            'reconciliation' => ['matches' => true, 'note' => 'Register totals are the dashboard totals — both read registerTotals().'],
        ];
    }

    /** By-category / by-location / by-status breakdowns of the same rows (screen only). */
    protected function registerGroups($assets, array $postedByAsset): array
    {
        $group = function (callable $key) use ($assets, $postedByAsset) {
            $out = [];
            foreach ($assets as $asset) {
                $label = $key($asset);
                $out[$label] = $out[$label] ?? ['label' => $label, 'count' => 0, 'cost' => '0', 'book_value' => '0'];
                $out[$label]['count']++;
                if (! in_array($asset->status, self::GONE_STATUSES, true)) {
                    $out[$label]['cost'] = bcadd($out[$label]['cost'], (string) ($asset->capitalized_cost ?? '0'), 4);
                    $out[$label]['book_value'] = bcadd($out[$label]['book_value'], (string) ($asset->current_book_value ?? '0'), 4);
                }
            }

            return array_values($out);
        };

        return [
            'by_category' => $group(fn ($a) => $a->category ? $a->category->code : '—'),
            'by_location' => $group(fn ($a) => $a->location ? $a->location->code : '—'),
            'by_status' => $group(fn ($a) => ucfirst(str_replace('_', ' ', $a->status))),
        ];
    }

    // ------------------------------------------------------------------
    // Acquisitions
    // ------------------------------------------------------------------

    protected function acquisitionReport(int $creatorId, array $filters): array
    {
        $query = FixedAssetAcquisition::forCompany($creatorId)
            ->with(['asset', 'capitalization', 'costs'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->when($filters['status'] ?? null, function ($q, $v) {
                // The generic status filter is an asset status; for
                // this report it filters the acquisition status
                // only when the value is a valid acquisition status.
                if (in_array($v, FixedAssetAcquisition::STATUSES, true)) {
                    $q->where('status', $v);
                }
            })
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('source_reference', 'like', '%'.$v.'%')
                        ->orWhere('supplier', 'like', '%'.$v.'%');
                });
            })
            ->orderBy('purchase_date', 'desc');

        $acquisitions = $query->get();

        $rows = [];
        $totalAmount = '0';
        $capitalizedCount = 0;
        $capitalizedAmount = '0';
        $underConstruction = 0;
        foreach ($acquisitions as $acq) {
            $amount = $this->acquisitionBaseAmount($acq);
            $totalAmount = bcadd($totalAmount, $amount, 4);
            $capitalized = $acq->capitalization !== null
                || $acq->status === FixedAssetAcquisition::STATUS_CAPITALIZED;
            if ($capitalized) {
                $capitalizedCount++;
                $capitalizedAmount = bcadd($capitalizedAmount, $amount, 4);
            }
            if ($acq->status === FixedAssetAcquisition::STATUS_APPROVED && ! $capitalized) {
                $underConstruction++;
            }
            $rows[] = [
                $acq->purchase_date ? Carbon::parse($acq->purchase_date)->format('Y-m-d') : '—',
                $acq->asset ? $acq->asset->code : '—',
                ucfirst(str_replace('_', ' ', $acq->source_type)),
                $acq->source_reference ?? '—',
                $acq->supplier ?? '—',
                ucfirst(str_replace('_', ' ', $acq->status)),
                $this->money($amount),
                $acq->capitalized_at ? Carbon::parse($acq->capitalized_at)->format('Y-m-d') : '—',
            ];
        }

        return [
            'title' => 'Acquisitions & Capitalization',
            'description' => 'Every acquisition with its source, amount (base currency) and capitalization date. Approved but not yet capitalized acquisitions are assets under construction.',
            'headings' => ['Purchase Date', 'Asset', 'Source', 'Source Ref.', 'Supplier', 'Status', 'Amount (base)', 'Capitalized'],
            'rows' => $rows,
            'totals' => [
                'Acquisitions' => (string) $acquisitions->count(),
                'Total amount ('.$this->baseCurrency($creatorId).')' => $this->money($totalAmount),
                'Capitalized' => (string) $capitalizedCount,
                'Capitalized amount ('.$this->baseCurrency($creatorId).')' => $this->money($capitalizedAmount),
                'Under construction' => (string) $underConstruction,
            ],
        ];
    }

    /** Base-currency amount of one acquisition (its cost lines). */
    protected function acquisitionBaseAmount(FixedAssetAcquisition $acq): string
    {
        return bcadd($acq->total_cost_base, '0', 4);
    }

    // ------------------------------------------------------------------
    // Depreciation
    // ------------------------------------------------------------------

    protected function depreciationReport(int $creatorId, array $filters): array
    {
        $assets = $this->filteredAssets($creatorId, $filters)
            ->with(['category'])
            ->whereNotNull('capitalization_date')
            ->get();
        $postedByAsset = $this->postedByAsset($creatorId);

        $rows = [];
        $totalCost = '0';
        $totalAccum = '0';
        $totalBook = '0';
        $thisPeriod = '0';
        $periodStart = Carbon::now()->startOfMonth()->format('Y-m-d');
        $periodEnd = Carbon::now()->endOfMonth()->format('Y-m-d');
        $thisPeriodByAsset = FixedAssetDepreciationEntry::where('created_by', $creatorId)
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->whereDate('period', '>=', $periodStart)
            ->whereDate('period', '<=', $periodEnd)
            ->selectRaw('asset_id, SUM(posted_amount) as total')
            ->groupBy('asset_id')
            ->pluck('total', 'asset_id')
            ->all();

        foreach ($assets as $asset) {
            $cost = (string) ($asset->capitalized_cost ?? '0');
            $accum = $this->accumDepForAsset($asset, $creatorId, $postedByAsset);
            $book = (string) ($asset->current_book_value ?? '0');
            $postedRows = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->where('status', FixedAssetDepreciationSchedule::STATUS_POSTED)
                ->count();
            $plannedRows = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
                ->count();
            $cancelledRows = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->where('status', FixedAssetDepreciationSchedule::STATUS_CANCELLED)
                ->count();
            $periodPosted = bcadd((string) ($thisPeriodByAsset[$asset->id] ?? '0'), '0', 2);

            $stillDepreciating = in_array($asset->status, self::DEPRECIATING_WHILE_EXCEPTION_STATUSES, true)
                && $plannedRows > 0;

            // Disposed assets keep their posted history but no
            // longer contribute to the live totals below.
            if (! in_array($asset->status, self::GONE_STATUSES, true)) {
                $totalCost = bcadd($totalCost, $cost, 4);
                $totalAccum = bcadd($totalAccum, $accum, 4);
                $totalBook = bcadd($totalBook, $book, 4);
                $thisPeriod = bcadd($thisPeriod, $periodPosted, 2);
            }

            $rows[] = [
                $asset->code,
                $asset->name,
                $asset->category ? $asset->category->code : '—',
                $this->money($cost),
                $this->money($accum),
                $this->money($book),
                ucfirst(str_replace('_', ' ', $asset->status)),
                (string) $plannedRows,
                (string) $postedRows,
                (string) $cancelledRows,
                $this->money($periodPosted),
                $stillDepreciating ? 'Yes — depreciating while '.str_replace('_', ' ', $asset->status) : 'No',
            ];
        }

        // Reconciliation: the accumulated column must equal posted
        // entries + opening accumulated depreciation, over the same
        // live assets. A mismatch is a bug, not rounding.
        $postedLive = '0';
        foreach ($assets as $asset) {
            if (in_array($asset->status, self::GONE_STATUSES, true)) {
                continue;
            }
            $postedLive = bcadd($postedLive, $postedByAsset[(int) $asset->id] ?? '0', 2);
        }
        $openingLive = '0';
        foreach ($assets as $asset) {
            if (in_array($asset->status, self::GONE_STATUSES, true)) {
                continue;
            }
            $openingLive = bcadd($openingLive, (string) ($asset->opening_accumulated_depreciation ?? '0'), 4);
        }
        $reconciled = bccomp(bcadd($postedLive, $openingLive, 2), $totalAccum, 2) === 0;

        return [
            'title' => 'Depreciation Schedule',
            'description' => 'Planned vs posted depreciation per capitalized asset. Cancelled rows are months cut short by a disposal. Assets flagged "depreciating while lost / stolen / damaged" are still being depreciated until written off (Phase 3 decision) — they are listed here, not hidden.',
            'headings' => ['Code', 'Asset', 'Category', 'Cost', 'Accum. Dep.', 'Net Book Value', 'Status', 'Planned', 'Posted', 'Cancelled', 'This Period', 'Still Depreciating?'],
            'rows' => $rows,
            'totals' => [
                'Capitalized assets' => (string) $assets->count(),
                'Total cost ('.$this->baseCurrency($creatorId).')' => $this->money($totalCost),
                'Accum. depreciation ('.$this->baseCurrency($creatorId).')' => $this->money($totalAccum),
                'Net book value ('.$this->baseCurrency($creatorId).')' => $this->money($totalBook),
                'Posted this period ('.$this->baseCurrency($creatorId).')' => $this->money($thisPeriod),
            ],
            'reconciliation' => [
                'matches' => $reconciled,
                'note' => $reconciled
                    ? 'Accumulated depreciation equals posted entries + opening accumulated depreciation.'
                    : 'RECONCILIATION BREAK — accumulated depreciation does not equal posted entries + opening accumulated depreciation.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Movements
    // ------------------------------------------------------------------

    protected function movementsReport(int $creatorId, array $filters): array
    {
        $entries = [];
        $actorNames = $this->userNames($creatorId);

        $assignments = FixedAssetAssignment::forCompany($creatorId)
            ->with(['asset', 'custodian'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('requested_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('requested_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->whereHas('asset', fn ($w) => $w->where('code', 'like', '%'.$v.'%')->orWhere('name', 'like', '%'.$v.'%'));
            })
            ->orderBy('requested_at', 'desc')
            ->limit(250)
            ->get();
        foreach ($assignments as $a) {
            $entries[] = [
                'at' => $a->requested_at ? Carbon::parse($a->requested_at)->format('Y-m-d H:i') : '—',
                'sort' => $a->requested_at ? Carbon::parse($a->requested_at)->timestamp : 0,
                'row' => [
                    $a->requested_at ? Carbon::parse($a->requested_at)->format('Y-m-d') : '—',
                    $a->asset ? $a->asset->code : '—',
                    'Assignment',
                    ($a->custodian ? $a->custodian->name : '—').' — '.ucfirst(str_replace('_', ' ', $a->status)),
                    $actorNames[(int) ($a->requested_by ?? 0)] ?? '—',
                    ucfirst(str_replace('_', ' ', $a->status)),
                ],
            ];
        }

        $transfers = FixedAssetTransfer::forCompany($creatorId)
            ->with(['asset', 'toLocation', 'toCustodian'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('requested_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('requested_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->whereHas('asset', fn ($w) => $w->where('code', 'like', '%'.$v.'%')->orWhere('name', 'like', '%'.$v.'%'));
            })
            ->orderBy('requested_at', 'desc')
            ->limit(250)
            ->get();
        foreach ($transfers as $t) {
            $to = trim(($t->toLocation ? $t->toLocation->code : '').($t->toCustodian ? ' · '.$t->toCustodian->name : ''), ' ·');
            $entries[] = [
                'at' => $t->requested_at ? Carbon::parse($t->requested_at)->format('Y-m-d H:i') : '—',
                'sort' => $t->requested_at ? Carbon::parse($t->requested_at)->timestamp : 0,
                'row' => [
                    $t->requested_at ? Carbon::parse($t->requested_at)->format('Y-m-d') : '—',
                    $t->asset ? $t->asset->code : '—',
                    'Transfer',
                    ($to !== '' ? $to.' — ' : '').ucfirst(str_replace('_', ' ', $t->status)),
                    $actorNames[(int) ($t->requested_by ?? 0)] ?? '—',
                    ucfirst(str_replace('_', ' ', $t->status)),
                ],
            ];
        }

        usort($entries, fn ($a, $b) => $b['sort'] <=> $a['sort']);
        $entries = array_slice($entries, 0, 500);

        return [
            'title' => 'Movements',
            'description' => 'Assignments and transfers, newest first (latest 500). The full history of one asset is on its show page timeline.',
            'headings' => ['Date', 'Asset', 'Type', 'Detail', 'Requested By', 'Status'],
            'rows' => array_map(fn ($e) => $e['row'], $entries),
            'totals' => [
                'Assignments' => (string) $assignments->count(),
                'Transfers' => (string) $transfers->count(),
                'Rows shown' => (string) count($entries),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Maintenance
    // ------------------------------------------------------------------

    protected function maintenanceReport(int $creatorId, array $filters): array
    {
        $costs = FixedAssetMaintenanceCost::where('created_by', $creatorId)
            ->with(['asset', 'request'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('recorded_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('recorded_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->whereHas('asset', fn ($w) => $w->where('code', 'like', '%'.$v.'%')->orWhere('name', 'like', '%'.$v.'%'));
            })
            ->orderBy('recorded_at', 'desc')
            ->get();

        $rows = [];
        $total = '0';
        $byType = [];
        foreach ($costs as $cost) {
            $amount = (string) ($cost->amount ?? '0');
            $total = bcadd($total, $amount, 4);
            $type = ucfirst((string) $cost->cost_type);
            $byType[$type] = bcadd($byType[$type] ?? '0', $amount, 4);
            $rows[] = [
                $cost->recorded_at ? Carbon::parse($cost->recorded_at)->format('Y-m-d') : '—',
                $cost->asset ? $cost->asset->code : '—',
                $cost->request ? ucfirst((string) $cost->request->type) : '—',
                $type,
                $cost->description ?? '—',
                $cost->vendor ?? '—',
                $this->money($amount),
                $cost->journal_id ? 'Journal #'.$cost->journal_id : '—',
            ];
        }

        $openRequests = FixedAssetMaintenanceRequest::forCompany($creatorId)
            ->whereIn('status', [
                FixedAssetMaintenanceRequest::STATUS_REQUESTED,
                FixedAssetMaintenanceRequest::STATUS_APPROVED,
                FixedAssetMaintenanceRequest::STATUS_IN_PROGRESS,
            ])->count();

        $totals = [
            'Cost lines' => (string) $costs->count(),
            'Total cost ('.$this->baseCurrency($creatorId).')' => $this->money($total),
            'Open requests' => (string) $openRequests,
        ];
        foreach ($byType as $type => $amount) {
            $totals[$type.' ('.$this->baseCurrency($creatorId).')'] = $this->money($amount);
        }

        return [
            'title' => 'Maintenance Cost',
            'description' => 'Every recorded maintenance cost with its journal. Maintenance is an expense, never a capitalization — the asset cost never changes because of maintenance.',
            'headings' => ['Date', 'Asset', 'Request Type', 'Cost Type', 'Description', 'Vendor', 'Amount', 'Journal'],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    // ------------------------------------------------------------------
    // Disposals
    // ------------------------------------------------------------------

    protected function disposalReport(int $creatorId, array $filters): array
    {
        $disposals = FixedAssetDisposal::forCompany($creatorId)
            ->with(['asset'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('executed_at', '>=', $v)->orWhere(function ($w) use ($v) {
                $w->whereNull('executed_at')->whereDate('requested_at', '>=', $v);
            }))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('executed_at', '<=', $v)->orWhere(function ($w) use ($v) {
                $w->whereNull('executed_at')->whereDate('requested_at', '<=', $v);
            }))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->whereHas('asset', fn ($w) => $w->where('code', 'like', '%'.$v.'%')->orWhere('name', 'like', '%'.$v.'%'));
            })
            ->orderBy('requested_at', 'desc')
            ->get();

        $rows = [];
        $executed = 0;
        $proceeds = '0';
        $gains = '0';
        $losses = '0';
        $writtenOff = 0;
        foreach ($disposals as $d) {
            $gl = (string) ($d->gain_loss_amount ?? '0');
            if ($d->status === FixedAssetDisposal::STATUS_EXECUTED) {
                $executed++;
                $proceeds = bcadd($proceeds, (string) ($d->actual_proceeds ?? '0'), 2);
                if (bccomp($gl, '0', 2) > 0) {
                    $gains = bcadd($gains, $gl, 2);
                } elseif (bccomp($gl, '0', 2) < 0) {
                    $losses = bcadd($losses, bcsub('0', $gl, 2), 2);
                }
                if ($d->method === FixedAssetDisposal::METHOD_WRITE_OFF) {
                    $writtenOff++;
                }
            }
            $rows[] = [
                $d->executed_at ? Carbon::parse($d->executed_at)->format('Y-m-d') : Carbon::parse($d->requested_at)->format('Y-m-d'),
                $d->asset ? $d->asset->code : '—',
                ucfirst(str_replace('_', ' ', $d->method)),
                ucfirst(str_replace('_', ' ', $d->status)),
                $this->money((string) ($d->book_value_at_disposal ?? '0')),
                $this->money((string) ($d->accumulated_depreciation_at_disposal ?? '0')),
                $this->money((string) ($d->actual_proceeds ?? '0')),
                $d->status === FixedAssetDisposal::STATUS_EXECUTED
                    ? (bccomp($gl, '0', 2) >= 0 ? 'Gain '.$this->money($gl) : 'Loss '.$this->money(bcsub('0', $gl, 2)))
                    : '—',
                $d->journal_id ? 'Journal #'.$d->journal_id : '—',
            ];
        }

        return [
            'title' => 'Disposals & Gains/Losses',
            'description' => 'Every disposal with the book value it left at, the proceeds and the gain or loss recognised. Written-off assets are listed with 0 proceeds.',
            'headings' => ['Date', 'Asset', 'Method', 'Status', 'Book Value', 'Accum. Dep. Removed', 'Proceeds', 'Gain / Loss', 'Journal'],
            'rows' => $rows,
            'totals' => [
                'Disposals' => (string) $disposals->count(),
                'Executed' => (string) $executed,
                'Written off' => (string) $writtenOff,
                'Proceeds ('.$this->baseCurrency($creatorId).')' => $this->money($proceeds),
                'Gains ('.$this->baseCurrency($creatorId).')' => $this->money($gains),
                'Losses ('.$this->baseCurrency($creatorId).')' => $this->money($losses),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Verification exceptions
    // ------------------------------------------------------------------

    protected function verificationReport(int $creatorId, array $filters): array
    {
        $items = FixedAssetVerificationItem::where('created_by', $creatorId)
            ->with(['asset', 'verification', 'expectedLocation', 'expectedCustodian'])
            ->whereIn('result', [
                FixedAssetVerificationItem::RESULT_MISSING,
                FixedAssetVerificationItem::RESULT_DAMAGED,
                FixedAssetVerificationItem::RESULT_WRONG_LOCATION,
                FixedAssetVerificationItem::RESULT_WRONG_CUSTODIAN,
            ])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('checked_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('checked_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->whereHas('asset', fn ($w) => $w->where('code', 'like', '%'.$v.'%')->orWhere('name', 'like', '%'.$v.'%'));
            })
            ->orderBy('checked_at', 'desc')
            ->get();

        $actorNames = $this->userNames($creatorId);
        $rows = [];
        $byResult = [];
        foreach ($items as $item) {
            $result = ucfirst(str_replace('_', ' ', $item->result));
            $byResult[$result] = ($byResult[$result] ?? 0) + 1;
            $rows[] = [
                $item->verification ? $item->verification->name : '—',
                $item->asset ? $item->asset->code : '—',
                $item->expectedLocation ? $item->expectedLocation->code : '—',
                $item->expectedCustodian ? $item->expectedCustodian->name : '—',
                $result,
                $actorNames[(int) ($item->checked_by ?? 0)] ?? '—',
                $item->checked_at ? Carbon::parse($item->checked_at)->format('Y-m-d') : '—',
                $item->note ?? '—',
            ];
        }

        $campaigns = FixedAssetPhysicalVerification::forCompany($creatorId)->get();
        $totals = [
            'Exceptions' => (string) $items->count(),
            'Campaigns' => (string) $campaigns->count(),
            'Open campaigns' => (string) $campaigns->where('status', FixedAssetPhysicalVerification::STATUS_IN_PROGRESS)->count(),
            'Overdue campaigns' => (string) $campaigns
                ->where('status', FixedAssetPhysicalVerification::STATUS_IN_PROGRESS)
                ->filter(fn ($c) => $c->deadline !== null && $c->deadline->format('Y-m-d') < Carbon::now()->format('Y-m-d'))
                ->count(),
        ];
        foreach ($byResult as $result => $count) {
            $totals[$result] = (string) $count;
        }

        return [
            'title' => 'Verification Exceptions',
            'description' => 'Assets a physical verification found missing, damaged, at the wrong location or with the wrong custodian. Missing is a flag, not a write-off — the asset stays on the books and keeps depreciating until Phase 5 acts on it.',
            'headings' => ['Campaign', 'Asset', 'Expected Location', 'Expected Custodian', 'Result', 'Checked By', 'Checked At', 'Note'],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    // ------------------------------------------------------------------
    // Control & reconciliation
    // ------------------------------------------------------------------

    protected function controlReport(int $creatorId, array $filters): array
    {
        $currency = $this->baseCurrency($creatorId);

        // Journal totals for one accounting-entry type, from the
        // module's own bridge (never from raw chart codes, so
        // postings from other modules can never leak in).
        $journalTotals = function (string $type) use ($creatorId) {
            $journalIds = FixedAssetAccountingEntry::where('created_by', $creatorId)
                ->where('transaction_type', $type)
                ->pluck('journal_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($journalIds === []) {
                return ['debit' => '0', 'credit' => '0', 'count' => 0];
            }

            return [
                'debit' => bcadd((string) JournalItem::whereIn('journal', $journalIds)->sum('debit'), '0', 2),
                'credit' => bcadd((string) JournalItem::whereIn('journal', $journalIds)->sum('credit'), '0', 2),
                'count' => count($journalIds),
            ];
        };

        $cap = $journalTotals(FixedAssetAccountingEntry::TYPE_CAPITALIZATION);
        $dep = $journalTotals(FixedAssetAccountingEntry::TYPE_DEPRECIATION);
        $dsp = $journalTotals(FixedAssetAccountingEntry::TYPE_DISPOSAL);
        $mnt = $journalTotals(FixedAssetAccountingEntry::TYPE_MAINTENANCE);

        $assets = FixedAsset::forCompany($creatorId)->get();
        $postedByAsset = $this->postedByAsset($creatorId);

        $everCapitalized = '0';
        $liveCost = '0';
        $goneCost = '0';
        $openingAll = '0';
        $openingLive = '0';
        $postedAll = '0';
        $postedLive = '0';
        $bookLive = '0';
        $assetAccountIds = [];
        foreach ($assets as $asset) {
            if ($asset->capitalization_date === null) {
                continue;
            }
            $cost = (string) ($asset->capitalized_cost ?? '0');
            $everCapitalized = bcadd($everCapitalized, $cost, 4);
            $opening = (string) ($asset->opening_accumulated_depreciation ?? '0');
            $posted = $postedByAsset[(int) $asset->id] ?? '0';
            $openingAll = bcadd($openingAll, $opening, 4);
            $postedAll = bcadd($postedAll, $posted, 2);
            if ($asset->asset_account_id) {
                $assetAccountIds[] = (int) $asset->asset_account_id;
            }
            if (in_array($asset->status, self::GONE_STATUSES, true)) {
                $goneCost = bcadd($goneCost, $cost, 4);
                continue;
            }
            $liveCost = bcadd($liveCost, $cost, 4);
            $openingLive = bcadd($openingLive, $opening, 4);
            $postedLive = bcadd($postedLive, $posted, 2);
            $bookLive = bcadd($bookLive, (string) ($asset->current_book_value ?? '0'), 4);
        }

        // Disposal journals credit the asset account for the cost
        // removed — the only credit lines on asset accounts in
        // those journals. Maintenance journals have one debit line
        // (the expense) and one credit (AP); capitalization
        // journals have one debit (the asset) and one credit.
        $dspJournalIds = FixedAssetAccountingEntry::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetAccountingEntry::TYPE_DISPOSAL)
            ->pluck('journal_id')->filter()->unique()->values()->all();
        $disposedRemoved = '0';
        if ($dspJournalIds !== [] && $assetAccountIds !== []) {
            $disposedRemoved = bcadd((string) JournalItem::whereIn('journal', $dspJournalIds)
                ->whereIn('account', array_unique($assetAccountIds))
                ->sum('credit'), '0', 2);
        }

        $checks = [];
        $addCheck = function (string $name, string $register, string $accounting) use (&$checks) {
            $diff = bcsub($register, $accounting, 2);
            $checks[] = [
                $name,
                $this->money($register),
                $this->money($accounting),
                $this->money($diff),
                bccomp($diff, '0', 2) === 0 ? 'Match' : 'BREAK',
            ];
        };

        $addCheck('Capitalized cost (ever)', $everCapitalized, $cap['debit']);
        $addCheck('Disposed cost removed', $goneCost, $disposedRemoved);
        $addCheck('Live asset cost', $liveCost, bcsub($cap['debit'], $disposedRemoved, 2));
        $addCheck('Accumulated depreciation', bcadd($openingAll, $postedAll, 2), bcadd($dep['credit'], $openingAll, 2));
        $addCheck('Live net book value', $bookLive, bcsub(bcsub($liveCost, $openingLive, 4), $postedLive, 2));
        $addCheck('Maintenance costs posted', bcadd((string) FixedAssetMaintenanceCost::where('created_by', $creatorId)->sum('amount'), '0', 2), $mnt['debit']);

        // Every Fixed Assets journal must balance — the poster
        // guarantees it, the report proves it.
        $allJournalIds = FixedAssetAccountingEntry::where('created_by', $creatorId)
            ->pluck('journal_id')->filter()->unique()->values()->all();
        $unbalanced = 0;
        if ($allJournalIds !== []) {
            $lines = JournalItem::whereIn('journal', $allJournalIds)
                ->selectRaw('journal, SUM(debit) as d, SUM(credit) as c')
                ->groupBy('journal')
                ->get();
            foreach ($lines as $line) {
                if (bccomp(bcsub((string) $line->d, (string) $line->c, 2), '0', 2) !== 0) {
                    $unbalanced++;
                }
            }
        }
        $checks[] = [
            'Unbalanced Fixed Assets journals',
            '0',
            (string) $unbalanced,
            (string) $unbalanced,
            $unbalanced === 0 ? 'Match' : 'BREAK',
        ];

        $matches = true;
        foreach ($checks as $check) {
            if ($check[4] !== 'Match') {
                $matches = false;
                break;
            }
        }

        return [
            'title' => 'Control & Reconciliation',
            'description' => 'The register reconciled to the Fixed Assets module\'s own journals (capitalization, depreciation, disposal, maintenance). Register values come from the asset rows; accounting values come from the journal lines linked through the accounting-entries bridge, so postings from other modules can never leak in.',
            'headings' => ['Check', 'Register ('.$currency.')', 'Accounting ('.$currency.')', 'Difference', 'Status'],
            'rows' => $checks,
            'totals' => [
                'Capitalization journals' => (string) $cap['count'],
                'Depreciation journals' => (string) $dep['count'],
                'Disposal journals' => (string) $dsp['count'],
                'Maintenance journals' => (string) $mnt['count'],
            ],
            'reconciliation' => [
                'matches' => $matches,
                'note' => $matches
                    ? 'Register reconciles to the Fixed Assets journals.'
                    : 'RECONCILIATION BREAK — at least one check above does not match. See the Status column.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    /** Base query for audit events, with the trail filters applied. */
    public function auditTrailQuery(int $creatorId, array $filters)
    {
        return FixedAssetAuditEvent::forCompany($creatorId)
            ->with(['asset', 'actor'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('entity', 'like', '%'.$v.'%')
                        ->orWhere('action', 'like', '%'.$v.'%')
                        ->orWhere('reason', 'like', '%'.$v.'%')
                        ->orWhereHas('asset', fn ($a) => $a->where('code', 'like', '%'.$v.'%'));
                });
            })
            ->orderBy('created_at', 'desc');
    }

    /** Audit rows for a report/export (latest $limit, plain values). */
    protected function auditTrailRows(int $creatorId, $query, int $limit = 500): array
    {
        $actorNames = $this->userNames($creatorId);
        $rows = [];
        foreach ($query->limit($limit)->get() as $event) {
            $rows[] = [
                $event->created_at ? Carbon::parse($event->created_at)->format('Y-m-d H:i') : '—',
                $actorNames[(int) ($event->actor_id ?? 0)] ?? 'User #'.($event->actor_id ?? '—'),
                $event->asset ? $event->asset->code : '—',
                $event->entity,
                $event->action,
                $event->reason ?? '—',
                $this->valuesSummary($event->before_values),
                $this->valuesSummary($event->after_values),
            ];
        }

        return $rows;
    }

    protected function auditTrailReport(int $creatorId, array $filters): array
    {
        $query = $this->auditTrailQuery($creatorId, $filters);
        $total = (clone $query)->count();

        return [
            'title' => 'Audit Trail',
            'description' => 'Who did what, when, on which asset — every recorded Fixed Assets event, newest first (latest 500 in exports; the screen pages through all of them).',
            'headings' => ['Time', 'Actor', 'Asset', 'Entity', 'Action', 'Reason', 'Before', 'After'],
            'rows' => $this->auditTrailRows($creatorId, $query, 500),
            'totals' => [
                'Events' => (string) $total,
            ],
        ];
    }

    /** Short "key=value, ..." summary of a stored before/after JSON blob. */
    protected function valuesSummary(mixed $values): string
    {
        if ($values === null || $values === '') {
            return '—';
        }
        $data = is_array($values) ? $values : json_decode((string) $values, true);
        if (! is_array($data) || $data === []) {
            return '—';
        }

        $parts = [];
        foreach (array_slice($data, 0, 4) as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $parts[] = $key.'='.(string) $value;
        }
        $summary = implode(', ', $parts);
        if (count($data) > 4) {
            $summary .= ', …';
        }

        return $summary;
    }

    /** User id => name map for this company (actors in reports). */
    protected function userNames(int $creatorId): array
    {
        return User::where(function ($q) use ($creatorId) {
            $q->where('created_by', $creatorId)->orWhere('id', $creatorId);
        })->pluck('name', 'id')->all();
    }

    /** 2dp money string for display (bcmath-safe). */
    protected function money(string $value): string
    {
        return number_format((float) bcadd($value, '0', 2), 2, '.', '');
    }
}
