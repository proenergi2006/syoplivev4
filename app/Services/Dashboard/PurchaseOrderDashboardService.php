<?php

namespace App\Services\Dashboard;

use App\Models\GoodsReceive;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseOrderDashboardService
{
    private const PO_STATUS_DRAFT = 'DRAFT';
    private const PO_STATUS_IN_PROGRESS = 'IN PROGRESS';
    private const PO_STATUS_APPROVED = 'APPROVED';
    private const PO_STATUS_REJECTED = 'REJECTED';

    /**
     * Dashboard tahap kedua:
     *
     * Query 1:
     * Ringkasan Purchase Request.
     *
     * Query 2:
     * Ringkasan PO, status, dan outstanding receipt.
     */
    public function getDashboard(array $filters): array
    {
        [
            $startDate,
            $endDate,
        ] = $this->resolveDateRange($filters);

        $purchaseRequestSummary
            = $this->getPurchaseRequestSummary(
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
            );

        $purchaseOrderSummary
            = $this->getPurchaseOrderSummary(
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
            );

        $trend = $this->getTrend(
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $approvedPr = (int) (
            $purchaseRequestSummary->approved_pr ?? 0
        );

        $convertedPr = (int) (
            $purchaseRequestSummary->converted_pr ?? 0
        );

        $conversionRate = $approvedPr > 0
            ? round(
                ($convertedPr / $approvedPr) * 100,
                1,
            )
            : 0;

        $draftPo = (int) (
            $purchaseOrderSummary->draft_po ?? 0
        );

        $inProgressPo = (int) (
            $purchaseOrderSummary->in_progress_po ?? 0
        );

        $approvedPo = (int) (
            $purchaseOrderSummary->approved_po ?? 0
        );

        $rejectedPo = (int) (
            $purchaseOrderSummary->rejected_po ?? 0
        );

        return [
            'filters' => [
                'period' => $filters['period'],

                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),

                'cabang_id' => isset($filters['cabang_id'])
                    ? (int) $filters['cabang_id']
                    : null,

                'department_id' => isset(
                    $filters['department_id'],
                )
                    ? (int) $filters['department_id']
                    : null,
            ],

            'summary' => [
                'total_pr' => (int) (
                    $purchaseRequestSummary->total_pr ?? 0
                ),

                'total_pr_amount' => (float) (
                    $purchaseRequestSummary->total_pr_amount ?? 0
                ),

                'total_po' => (int) (
                    $purchaseOrderSummary->total_po ?? 0
                ),

                'total_po_amount' => (float) (
                    $purchaseOrderSummary->total_po_amount ?? 0
                ),

                'approved_pr' => $approvedPr,

                'pr_not_ordered' => (int) (
                    $purchaseRequestSummary->pr_not_ordered ?? 0
                ),

                'pending_po_approval' => $inProgressPo,

                'outstanding_receipt' => (int) (
                    $purchaseOrderSummary->outstanding_receipt ?? 0
                ),

                'rejected_po' => $rejectedPo,

                'conversion_rate' => $conversionRate,
            ],

            'trend' => $trend,

            'statuses' => [
                [
                    'status' => self::PO_STATUS_DRAFT,
                    'label' => 'Draft',
                    'total' => $draftPo,
                ],
                [
                    'status' => self::PO_STATUS_IN_PROGRESS,
                    'label' => 'In Progress',
                    'total' => $inProgressPo,
                ],
                [
                    'status' => self::PO_STATUS_APPROVED,
                    'label' => 'Approved',
                    'total' => $approvedPo,
                ],
                [
                    'status' => self::PO_STATUS_REJECTED,
                    'label' => 'Rejected',
                    'total' => $rejectedPo,
                ],
            ],

            'attention_items' => [],
        ];
    }

    /**
     * Ringkasan Purchase Request.
     *
     * Hanya menghasilkan satu row agregasi.
     */
    private function getPurchaseRequestSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): object {
        $query = PurchaseRequest::query();

        $this->applyPurchaseRequestFilters(
            query: $query,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        return $query
            ->selectRaw(
                '
                COUNT(purchase_requests.id)
                    AS total_pr,

                COALESCE(
                    SUM(purchase_requests.total_amount),
                    0
                ) AS total_pr_amount,

                SUM(
                    CASE
                        WHEN purchase_requests.status = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS approved_pr,

                SUM(
                    CASE
                        WHEN purchase_requests.status = ?
                        AND (
                            purchase_requests.status_po = ?
                            OR purchase_requests.status_po IS NULL
                        )
                        THEN 1
                        ELSE 0
                    END
                ) AS pr_not_ordered,

                SUM(
                    CASE
                        WHEN purchase_requests.status = ?
                        AND purchase_requests.status_po IN (?, ?)
                        THEN 1
                        ELSE 0
                    END
                ) AS converted_pr
                ',
                [
                    PurchaseRequest::STATUS_APPROVED,

                    PurchaseRequest::STATUS_APPROVED,
                    PurchaseRequest::STATUS_PO_OPEN,

                    PurchaseRequest::STATUS_APPROVED,
                    PurchaseRequest::STATUS_PO_PARTIAL,
                    PurchaseRequest::STATUS_PO_COMPLETED,
                ],
            )
            ->first();
    }

    /**
     * Ringkasan PO, status, dan outstanding receipt.
     *
     * Tetap hanya satu query utama ke purchase_orders.
     *
     * Quantity GR sudah diagregasi dahulu per PO item,
     * sehingga tidak terjadi query per PO atau per item.
     */
    private function getPurchaseOrderSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): object {
        $receiptStateByPurchaseOrder
            = $this->buildReceiptStateByPurchaseOrderSubquery();

        $query = PurchaseOrder::query()
            ->leftJoinSub(
                $receiptStateByPurchaseOrder,
                'receipt_state',
                function ($join): void {
                    $join->on(
                        'receipt_state.purchase_order_id',
                        '=',
                        'purchase_orders.id',
                    );
                },
            );

        $this->applyPurchaseOrderFilters(
            query: $query,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        return $query
            ->selectRaw(
                '
                COUNT(purchase_orders.id)
                    AS total_po,

                COALESCE(
                    SUM(purchase_orders.total_nilai),
                    0
                ) AS total_po_amount,

                SUM(
                    CASE
                        WHEN purchase_orders.status = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS draft_po,

                SUM(
                    CASE
                        WHEN purchase_orders.status = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS in_progress_po,

                SUM(
                    CASE
                        WHEN purchase_orders.status = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS approved_po,

                SUM(
                    CASE
                        WHEN purchase_orders.status = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS rejected_po,

                SUM(
                    CASE
                        WHEN purchase_orders.status = ?
                        AND COALESCE(
                            receipt_state.has_outstanding,
                            0
                        ) = 1
                        THEN 1
                        ELSE 0
                    END
                ) AS outstanding_receipt
                ',
                [
                    self::PO_STATUS_DRAFT,
                    self::PO_STATUS_IN_PROGRESS,
                    self::PO_STATUS_APPROVED,
                    self::PO_STATUS_REJECTED,
                    self::PO_STATUS_APPROVED,
                ],
            )
            ->first();
    }

    /**
     * Total qty GR posted per Purchase Order Item.
     *
     * GR Draft dan Cancelled tidak dihitung.
     */
    private function buildPostedReceivedQuantityByItemSubquery(): QueryBuilder
    {
        return DB::table(
            'goods_receive_items as gri',
        )
            ->join(
                'goods_receives as gr',
                'gr.id',
                '=',
                'gri.goods_receive_id',
            )
            ->where(
                'gr.status',
                GoodsReceive::STATUS_POSTED,
            )
            ->whereNull('gr.deleted_at')
            ->groupBy(
                'gri.purchase_order_item_id',
            )
            ->selectRaw(
                '
                gri.purchase_order_item_id,
                COALESCE(
                    SUM(gri.qty_receive),
                    0
                ) AS received_qty
                ',
            );
    }

    /**
     * Menentukan apakah sebuah PO masih mempunyai
     * minimal satu item outstanding.
     *
     * has_outstanding:
     * 1 = masih ada qty belum diterima
     * 0 = seluruh item sudah diterima
     */
    private function buildReceiptStateByPurchaseOrderSubquery(): QueryBuilder
    {
        $receivedQuantityByItem
            = $this
            ->buildPostedReceivedQuantityByItemSubquery();

        return DB::table(
            'purchase_order_items as poi',
        )
            ->leftJoinSub(
                $receivedQuantityByItem,
                'received',
                function ($join): void {
                    $join->on(
                        'received.purchase_order_item_id',
                        '=',
                        'poi.id',
                    );
                },
            )
            ->whereNull('poi.deleted_at')
            ->groupBy('poi.purchase_order_id')
            ->selectRaw(
                '
                poi.purchase_order_id,

                MAX(
                    CASE
                        WHEN COALESCE(poi.qty, 0)
                            > COALESCE(
                                received.received_qty,
                                0
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS has_outstanding
                ',
            );
    }

    /**
     * Filter tanggal, cabang, dan departemen PR.
     */
    private function applyPurchaseRequestFilters(
        Builder $query,
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $query->whereBetween(
            'purchase_requests.tanggal_pr',
            [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ],
        );

        if (isset($filters['cabang_id'])) {
            $query->where(
                'purchase_requests.cabang',
                (int) $filters['cabang_id'],
            );
        }

        if (isset($filters['department_id'])) {
            $query->where(
                'purchase_requests.id_department',
                (int) $filters['department_id'],
            );
        }
    }

    /**
     * Filter tanggal, cabang, dan departemen PO.
     */
    private function applyPurchaseOrderFilters(
        Builder $query,
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $query->whereBetween(
            'purchase_orders.tanggal_po',
            [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ],
        );

        if (isset($filters['cabang_id'])) {
            $query->where(
                'purchase_orders.cabang',
                (int) $filters['cabang_id'],
            );
        }

        if (isset($filters['department_id'])) {
            $query->where(
                'purchase_orders.id_department',
                (int) $filters['department_id'],
            );
        }
    }

    /**
     * Mengambil tren nilai PR dan PO.
     *
     * PR dan PO digabung menggunakan UNION ALL, kemudian
     * dijumlahkan kembali berdasarkan bucket tanggal.
     *
     * Hanya menghasilkan satu query database.
     */
    private function getTrend(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $granularity = $this->resolveTrendGranularity(
            period: $filters['period'],
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseRequestBucketExpression
            = $this->getTrendBucketExpression(
                column: 'purchase_requests.tanggal_pr',
                granularity: $granularity,
            );

        $purchaseOrderBucketExpression
            = $this->getTrendBucketExpression(
                column: 'purchase_orders.tanggal_po',
                granularity: $granularity,
            );

        /*
     * Query agregasi nilai PR.
     */
        $purchaseRequestTrend = PurchaseRequest::query();

        $this->applyPurchaseRequestFilters(
            query: $purchaseRequestTrend,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseRequestTrend
            ->selectRaw(
                "
            {$purchaseRequestBucketExpression} AS bucket,

            COALESCE(
                SUM(purchase_requests.total_amount),
                0
            ) AS pr_amount,

            0 AS po_amount
            ",
            )
            ->groupByRaw(
                $purchaseRequestBucketExpression,
            );

        /*
     * Query agregasi nilai PO.
     */
        $purchaseOrderTrend = PurchaseOrder::query();

        $this->applyPurchaseOrderFilters(
            query: $purchaseOrderTrend,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseOrderTrend
            ->selectRaw(
                "
            {$purchaseOrderBucketExpression} AS bucket,

            0 AS pr_amount,

            COALESCE(
                SUM(purchase_orders.total_nilai),
                0
            ) AS po_amount
            ",
            )
            ->groupByRaw(
                $purchaseOrderBucketExpression,
            );

        /*
     * UNION ALL lebih ringan daripada UNION karena database
     * tidak perlu melakukan proses penghapusan duplicate row.
     */
        $unionQuery = $purchaseRequestTrend
            ->toBase()
            ->unionAll(
                $purchaseOrderTrend->toBase(),
            );

        $rows = DB::query()
            ->fromSub(
                $unionQuery,
                'trend_rows',
            )
            ->selectRaw(
                '
            bucket,

            COALESCE(
                SUM(pr_amount),
                0
            ) AS pr_amount,

            COALESCE(
                SUM(po_amount),
                0
            ) AS po_amount
            ',
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows
            ->map(function ($row) use ($granularity): array {
                $bucket = CarbonImmutable::parse(
                    (string) $row->bucket,
                );

                return [
                    /*
                 * Nilai bucket mentah berguna untuk debugging
                 * atau pengembangan berikutnya.
                 */
                    'date' => $bucket->toDateString(),

                    /*
                 * Label langsung dibaca grafik frontend.
                 */
                    'label' => $this->formatTrendLabel(
                        date: $bucket,
                        granularity: $granularity,
                    ),

                    'pr_amount' => (float) (
                        $row->pr_amount ?? 0
                    ),

                    'po_amount' => (float) (
                        $row->po_amount ?? 0
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Menentukan interval grafik berdasarkan periode.
     */
    private function resolveTrendGranularity(
        string $period,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): string {
        /*
     * Grafik satu tahun lebih mudah dibaca per bulan.
     */
        if ($period === 'year') {
            return 'month';
        }

        /*
     * Rentang tanggal menggunakan interval adaptif
     * agar jumlah titik grafik tidak terlalu banyak.
     */
        if ($period === 'range') {
            $totalDays = $startDate->diffInDays(
                $endDate,
            ) + 1;

            if ($totalDays <= 31) {
                return 'day';
            }

            if ($totalDays <= 180) {
                return 'week';
            }

            return 'month';
        }

        /*
     * Day, week, dan month ditampilkan per tanggal.
     */
        return 'day';
    }

    /**
     * Menghasilkan ekspresi SQL tanggal berdasarkan database driver.
     *
     * Hanya menerima granularity dari sistem, bukan input langsung
     * dari user, sehingga aman digunakan pada selectRaw.
     */
    private function getTrendBucketExpression(
        string $column,
        string $granularity,
    ): string {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return match ($granularity) {
                'week' => "DATE_TRUNC('week', {$column})::date",
                'month' => "DATE_TRUNC('month', {$column})::date",
                default => "DATE({$column})",
            };
        }

        if ($driver === 'mysql') {
            return match ($granularity) {
                /*
             * Menghasilkan hari Senin sebagai awal minggu.
             */
                'week' => "
                DATE_SUB(
                    DATE({$column}),
                    INTERVAL WEEKDAY({$column}) DAY
                )
            ",

                /*
             * Menghasilkan tanggal pertama bulan.
             */
                'month' => "
                STR_TO_DATE(
                    DATE_FORMAT({$column}, '%Y-%m-01'),
                    '%Y-%m-%d'
                )
            ",

                default => "DATE({$column})",
            };
        }

        /*
     * Fallback untuk database lain.
     */
        return "DATE({$column})";
    }

    /**
     * Membentuk label grafik tanpa bergantung pada locale server.
     */
    private function formatTrendLabel(
        CarbonImmutable $date,
        string $granularity,
    ): string {
        $monthNames = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agu',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ];

        $month = $monthNames[(int) $date->format('n')];

        return match ($granularity) {
            'week' => sprintf(
                'Minggu %s %s',
                $date->format('d'),
                $month,
            ),

            'month' => sprintf(
                '%s %s',
                $month,
                $date->format('Y'),
            ),

            default => sprintf(
                '%s %s',
                $date->format('d'),
                $month,
            ),
        };
    }

    /**
     * Mengubah pilihan periode menjadi
     * start date dan end date.
     */
    private function resolveDateRange(
        array $filters,
    ): array {
        return match ($filters['period']) {
            'day' => $this->resolveDayPeriod(
                $filters['date'],
            ),

            'week' => $this->resolveWeekPeriod(
                $filters['week'],
            ),

            'month' => $this->resolveMonthPeriod(
                $filters['month'],
            ),

            'year' => $this->resolveYearPeriod(
                (int) $filters['year'],
            ),

            'range' => $this->resolveCustomRange(
                $filters['start_date'],
                $filters['end_date'],
            ),

            default => throw new InvalidArgumentException(
                'Periode dashboard tidak valid.',
            ),
        };
    }

    private function resolveDayPeriod(
        string $date,
    ): array {
        $selectedDate = CarbonImmutable::parse(
            $date,
        );

        return [
            $selectedDate->startOfDay(),
            $selectedDate->endOfDay(),
        ];
    }

    private function resolveWeekPeriod(
        string $week,
    ): array {
        if (
            !preg_match(
                '/^(\d{4})-W(\d{2})$/',
                $week,
                $matches,
            )
        ) {
            throw new InvalidArgumentException(
                'Format minggu tidak valid.',
            );
        }

        $year = (int) $matches[1];
        $weekNumber = (int) $matches[2];

        $startDate = CarbonImmutable::now()
            ->setISODate(
                $year,
                $weekNumber,
            )
            ->startOfWeek();

        return [
            $startDate,
            $startDate->endOfWeek(),
        ];
    }

    private function resolveMonthPeriod(
        string $month,
    ): array {
        $selectedMonth
            = CarbonImmutable::createFromFormat(
                'Y-m-d',
                "{$month}-01",
            );

        return [
            $selectedMonth->startOfMonth(),
            $selectedMonth->endOfMonth(),
        ];
    }

    private function resolveYearPeriod(
        int $year,
    ): array {
        $selectedYear = CarbonImmutable::create(
            year: $year,
            month: 1,
            day: 1,
        );

        return [
            $selectedYear->startOfYear(),
            $selectedYear->endOfYear(),
        ];
    }

    private function resolveCustomRange(
        string $startDate,
        string $endDate,
    ): array {
        return [
            CarbonImmutable::parse(
                $startDate,
            )->startOfDay(),

            CarbonImmutable::parse(
                $endDate,
            )->endOfDay(),
        ];
    }
}
