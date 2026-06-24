<script setup lang="ts">
import axios from '@axios'
import VueApexCharts from 'vue3-apexcharts'
import {
  computed,
  onMounted,
  ref,
} from 'vue'
import { useRouter } from 'vue-router'

type PeriodType =
  | 'day'
  | 'week'
  | 'month'
  | 'year'
  | 'range'

type AlertType =
  | 'success'
  | 'warning'
  | 'info'
  | 'error'

type BreakdownMetric = 'count' | 'amount'

interface DashboardBreakdownItem {
  id: number | null
  name: string

  pr_count: number
  pr_amount: number

  po_count: number
  po_amount: number
}

interface SelectOption {
  title: string
  value: number | string
}

interface OptionRecord {
  id?: number | string
  value?: number | string
  cabang_id?: number | string
  department_id?: number | string

  title?: string
  label?: string
  name?: string
  nama?: string
  nama_cabang?: string
  nama_department?: string
  nama_departemen?: string
  department_name?: string
}

interface DashboardAccess {
  scope_view: string

  cabang_id: number | null
  cabang_name: string | null

  department_id: number | null
  department_name: string | null

  can_filter_cabang: boolean
  can_filter_department: boolean
}

interface DashboardSummary {
  total_pr: number
  total_pr_amount: number
  total_po: number
  total_po_amount: number

  approved_pr: number
  pr_not_ordered: number
  pending_po_approval: number
  outstanding_receipt: number
  rejected_po: number

  conversion_rate: number
}

interface DashboardTrend {
  label: string
  pr_amount: number
  po_amount: number
}

interface DashboardStatus {
  status: string
  label: string
  total: number
}

interface AttentionItem {
  public_id: string
  po_number: string
  po_date: string | null
  cabang_name: string | null
  department_name: string | null
  vendor_name: string | null
  total_amount: number
  status: string
  age_days: number
  reason: string
}

interface DashboardResponse {
  message: string

  data: {
    access: DashboardAccess
    summary: DashboardSummary
    trend: DashboardTrend[]
    statuses: DashboardStatus[]
    attention_items: AttentionItem[]
    breakdown: {
      by_cabang: DashboardBreakdownItem[]
      by_department: DashboardBreakdownItem[]
    }
  }
}

interface ManagementInsight {
  type: AlertType
  icon: string
  title: string
  message: string
}

const router = useRouter()

/*
|--------------------------------------------------------------------------
| Default Date
|--------------------------------------------------------------------------
*/

const today = new Date()
const currentYear = today.getFullYear()

const selectedPeriod = ref<PeriodType>('month')
const selectedDate = ref(getLocalDateValue(today))
const selectedWeek = ref(getCurrentWeekValue(today))
const selectedMonth = ref(getMonthValue(today))
const selectedYear = ref(currentYear)

const startDate = ref(getFirstDateOfMonth(today))
const endDate = ref(getLocalDateValue(today))

const selectedCabangId = ref<number | string | null>(null)
const selectedDepartmentId = ref<number | string | null>(null)

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

const periodOptions = [
  {
    title: 'Harian',
    value: 'day',
    icon: 'mdi-calendar-today-outline',
  },
  {
    title: 'Mingguan',
    value: 'week',
    icon: 'mdi-calendar-week-outline',
  },
  {
    title: 'Bulanan',
    value: 'month',
    icon: 'mdi-calendar-month-outline',
  },
  {
    title: 'Tahunan',
    value: 'year',
    icon: 'mdi-calendar-blank-multiple',
  },
  {
    title: 'Rentang Tanggal',
    value: 'range',
    icon: 'mdi-calendar-range',
  },
]

const yearOptions = Array.from(
  {
    length: 10,
  },
  (_, index) => {
    const year = currentYear - index

    return {
      title: String(year),
      value: year,
    }
  },
)

const cabangOptions = ref<SelectOption[]>([])
const departmentOptions = ref<SelectOption[]>([])

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/

const isLoading = ref(false)
const isLoadingOptions = ref(false)

const errorMessage = ref('')
const lastUpdatedAt = ref<Date | null>(null)
const appliedPeriodLabel = ref('')

const breakdownByCabang = ref<
  DashboardBreakdownItem[]
>([])

const breakdownByDepartment = ref<
  DashboardBreakdownItem[]
>([])

const cabangBreakdownMetric
  = ref<BreakdownMetric>('amount')

const departmentBreakdownMetric
  = ref<BreakdownMetric>('amount')

const breakdownMetricOptions = [
  {
    title: 'Nilai Transaksi',
    value: 'amount',
  },
  {
    title: 'Jumlah Dokumen',
    value: 'count',
  },
]

const access = ref<DashboardAccess>({
  scope_view: 'NONE',

  cabang_id: null,
  cabang_name: null,

  department_id: null,
  department_name: null,

  can_filter_cabang: false,
  can_filter_department: false,
})

function synchronizeFiltersWithAccess(): void {
  /*
   * Cabang terkunci.
   */
  if (!access.value.can_filter_cabang) {
    selectedCabangId.value
      = access.value.cabang_id

    if (
      access.value.cabang_id
      && access.value.cabang_name
    ) {
      cabangOptions.value = [
        {
          value: access.value.cabang_id,
          title: access.value.cabang_name,
        },
      ]
    }
  }

  /*
   * Departemen terkunci.
   */
  if (!access.value.can_filter_department) {
    selectedDepartmentId.value
      = access.value.department_id

    if (
      access.value.department_id
      && access.value.department_name
    ) {
      departmentOptions.value = [
        {
          value: access.value.department_id,
          title: access.value.department_name,
        },
      ]
    }
  }
}

const summary = ref<DashboardSummary>({
  total_pr: 0,
  total_pr_amount: 0,
  total_po: 0,
  total_po_amount: 0,

  approved_pr: 0,
  pr_not_ordered: 0,
  pending_po_approval: 0,
  outstanding_receipt: 0,
  rejected_po: 0,

  conversion_rate: 0,
})

const trend = ref<DashboardTrend[]>([])
const statuses = ref<DashboardStatus[]>([])
const attentionItems = ref<AttentionItem[]>([])

/*
|--------------------------------------------------------------------------
| Computed Filter
|--------------------------------------------------------------------------
*/

const showCabangFilter = computed(() => {
  return access.value.can_filter_cabang
})

const showDepartmentFilter = computed(() => {
  return access.value.can_filter_department
})

const isFilterValid = computed(() => {
  if (selectedPeriod.value === 'day')
    return Boolean(selectedDate.value)

  if (selectedPeriod.value === 'week')
    return Boolean(selectedWeek.value)

  if (selectedPeriod.value === 'month')
    return Boolean(selectedMonth.value)

  if (selectedPeriod.value === 'year')
    return Boolean(selectedYear.value)

  if (selectedPeriod.value === 'range') {
    return Boolean(
      startDate.value
      && endDate.value
      && startDate.value <= endDate.value,
    )
  }

  return false
})

const selectedPeriodDescription = computed(() => {
  if (selectedPeriod.value === 'day')
    return formatDate(selectedDate.value)

  if (selectedPeriod.value === 'week')
    return formatWeek(selectedWeek.value)

  if (selectedPeriod.value === 'month')
    return formatMonth(selectedMonth.value)

  if (selectedPeriod.value === 'year')
    return `Tahun ${selectedYear.value}`

  if (selectedPeriod.value === 'range') {
    if (!startDate.value || !endDate.value)
      return '-'

    return `${formatDate(startDate.value)} sampai ${formatDate(endDate.value)}`
  }

  return '-'
})

/*
|--------------------------------------------------------------------------
| Statistic Cards
|--------------------------------------------------------------------------
*/

const statisticCards = computed(() => [
  {
    title: 'Total Purchase Requisition',
    shortTitle: 'PR',
    value: formatNumber(summary.value.total_pr),
    fullValue: null,
    subtitle: 'Jumlah kebutuhan yang diajukan',
    icon: 'mdi-file-document-edit-outline',
    color: 'primary',
  },
  {
    title: 'Nilai Purchase Requisition',
    shortTitle: 'Nilai PR',
    value: formatCompactCurrency(
      summary.value.total_pr_amount,
    ),
    fullValue: formatCurrency(
      summary.value.total_pr_amount,
    ),
    subtitle: 'Total nilai kebutuhan pembelian',
    icon: 'mdi-cash-clock',
    color: 'info',
  },
  {
    title: 'Total Purchase Order',
    shortTitle: 'PO',
    value: formatNumber(summary.value.total_po),
    fullValue: null,
    subtitle: 'Jumlah pesanan yang diterbitkan',
    icon: 'mdi-file-sign',
    color: 'success',
  },
  {
    title: 'Nilai Purchase Order',
    shortTitle: 'Nilai PO',
    value: formatCompactCurrency(
      summary.value.total_po_amount,
    ),
    fullValue: formatCurrency(
      summary.value.total_po_amount,
    ),
    subtitle: 'Total nilai realisasi pembelian',
    icon: 'mdi-cash-check',
    color: 'warning',
  },
])

const operationalStatistics = computed(() => [
  {
    title: 'PR Belum Menjadi PO',
    value: formatNumber(summary.value.pr_not_ordered),
    icon: 'mdi-file-document-alert-outline',
    color: 'warning',
  },
  {
    title: 'PO Menunggu Persetujuan',
    value: formatNumber(
      summary.value.pending_po_approval,
    ),
    icon: 'mdi-account-clock-outline',
    color: 'warning',
  },
  {
    title: 'Outstanding Receipt',
    value: formatNumber(
      summary.value.outstanding_receipt,
    ),
    icon: 'mdi-package-variant',
    color: 'info',
  },
  {
    title: 'Konversi PR ke PO',
    value: `${formatDecimal(summary.value.conversion_rate)}%`,
    icon: 'mdi-swap-horizontal-circle-outline',
    color: 'success',
  },
])

/*
|--------------------------------------------------------------------------
| Management Insight
|--------------------------------------------------------------------------
*/

const managementInsight = computed<ManagementInsight>(() => {
  const messages: string[] = []

  if (summary.value.pr_not_ordered > 0) {
    messages.push(
      `${formatNumber(
        summary.value.pr_not_ordered,
      )} PR yang telah disetujui belum diproses menjadi PO`,
    )
  }

  if (summary.value.pending_po_approval > 0) {
    messages.push(
      `${formatNumber(
        summary.value.pending_po_approval,
      )} PO masih menunggu persetujuan`,
    )
  }

  if (summary.value.outstanding_receipt > 0) {
    messages.push(
      `${formatNumber(
        summary.value.outstanding_receipt,
      )} PO belum selesai diterima`,
    )
  }

  if (
    summary.value.total_pr > 0
    && summary.value.conversion_rate < 80
  ) {
    messages.push(
      `tingkat konversi PR ke PO baru mencapai ${formatDecimal(
        summary.value.conversion_rate,
      )}%`,
    )
  }

  if (messages.length === 0) {
    return {
      type: 'success',
      icon: 'mdi-check-decagram-outline',
      title: 'Proses procurement dalam kondisi terkendali',
      message:
        'Belum ditemukan PR atau PO yang membutuhkan perhatian khusus pada periode terpilih.',
    }
  }

  return {
    type: 'warning',
    icon: 'mdi-alert-outline',
    title: 'Perlu perhatian management',
    message: `${messages.join('. ')}.`,
  }
})

/*
|--------------------------------------------------------------------------
| PR vs PO Comparison Chart
|--------------------------------------------------------------------------
*/

const cabangBreakdownSeries = computed(() => {
  const useAmount
    = cabangBreakdownMetric.value === 'amount'

  return [
    {
      name: 'Purchase Requisition',
      data: breakdownByCabang.value.map(item => {
        return useAmount
          ? Number(item.pr_amount ?? 0)
          : Number(item.pr_count ?? 0)
      }),
    },
    {
      name: 'Purchase Order',
      data: breakdownByCabang.value.map(item => {
        return useAmount
          ? Number(item.po_amount ?? 0)
          : Number(item.po_count ?? 0)
      }),
    },
  ]
})

const cabangBreakdownOptions = computed(() => {
  const useAmount
    = cabangBreakdownMetric.value === 'amount'

  return {
    chart: {
      type: 'bar',
      toolbar: {
        show: false,
      },
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 650,
        animateGradually: {
          enabled: true,
          delay: 80,
        },
      },
    },

    plotOptions: {
      bar: {
        horizontal: true,
        barHeight: '58%',
        borderRadius: 5,
        borderRadiusApplication: 'end',
      },
    },

    dataLabels: {
      enabled: false,
    },

    xaxis: {
      categories: breakdownByCabang.value.map(
        item => item.name,
      ),

      labels: {
        formatter: (value: number) => {
          return useAmount
            ? formatCompactCurrency(value)
            : formatNumber(value)
        },
      },
    },

    tooltip: {
      shared: true,
      intersect: false,

      y: {
        formatter: (value: number) => {
          return useAmount
            ? formatCurrency(value)
            : `${formatNumber(value)} dokumen`
        },
      },
    },

    legend: {
      position: 'top',
      horizontalAlign: 'right',
    },

    grid: {
      borderColor:
        'rgba(var(--v-border-color), 0.22)',
      strokeDashArray: 4,
    },
  }
})

const cabangBreakdownChartHeight = computed(() => {
  return Math.max(
    320,
    breakdownByCabang.value.length * 56,
  )
})

const departmentBreakdownSeries = computed(() => {
  const useAmount
    = departmentBreakdownMetric.value === 'amount'

  return [
    {
      name: 'Purchase Requisition',
      data: breakdownByDepartment.value.map(item => {
        return useAmount
          ? Number(item.pr_amount ?? 0)
          : Number(item.pr_count ?? 0)
      }),
    },
    {
      name: 'Purchase Order',
      data: breakdownByDepartment.value.map(item => {
        return useAmount
          ? Number(item.po_amount ?? 0)
          : Number(item.po_count ?? 0)
      }),
    },
  ]
})

const departmentBreakdownOptions = computed(() => {
  const useAmount
    = departmentBreakdownMetric.value === 'amount'

  return {
    chart: {
      type: 'bar',
      toolbar: {
        show: false,
      },
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 650,
        animateGradually: {
          enabled: true,
          delay: 80,
        },
      },
    },

    plotOptions: {
      bar: {
        horizontal: true,
        barHeight: '58%',
        borderRadius: 5,
        borderRadiusApplication: 'end',
      },
    },

    dataLabels: {
      enabled: false,
    },

    xaxis: {
      categories:
        breakdownByDepartment.value.map(
          item => item.name,
        ),

      labels: {
        formatter: (value: number) => {
          return useAmount
            ? formatCompactCurrency(value)
            : formatNumber(value)
        },
      },
    },

    tooltip: {
      shared: true,
      intersect: false,

      y: {
        formatter: (value: number) => {
          return useAmount
            ? formatCurrency(value)
            : `${formatNumber(value)} dokumen`
        },
      },
    },

    legend: {
      position: 'top',
      horizontalAlign: 'right',
    },

    grid: {
      borderColor:
        'rgba(var(--v-border-color), 0.22)',
      strokeDashArray: 4,
    },
  }
})

const departmentBreakdownChartHeight = computed(() => {
  return Math.max(
    320,
    breakdownByDepartment.value.length * 56,
  )
})

const comparisonChartSeries = computed(() => [
  {
    name: 'Nilai',
    data: [
      summary.value.total_pr_amount,
      summary.value.total_po_amount,
    ],
  },
])

const comparisonChartOptions = computed(() => ({
  chart: {
    type: 'bar',
    toolbar: {
      show: false,
    },
    animations: {
      enabled: true,
      easing: 'easeinout',
      speed: 700,
      animateGradually: {
        enabled: true,
        delay: 150,
      },
    },
  },

  plotOptions: {
    bar: {
      borderRadius: 7,
      columnWidth: '48%',
      distributed: true,
    },
  },

  dataLabels: {
    enabled: false,
  },

  legend: {
    show: false,
  },

  xaxis: {
    categories: [
      'Nilai PR',
      'Nilai PO',
    ],
  },

  yaxis: {
    labels: {
      formatter: (value: number) => {
        return formatCompactCurrency(value)
      },
    },
  },

  tooltip: {
    y: {
      formatter: (value: number) => {
        return formatCurrency(value)
      },
    },
  },

  grid: {
    borderColor:
      'rgba(var(--v-border-color), 0.25)',
  },
}))

/*
|--------------------------------------------------------------------------
| Trend Chart
|--------------------------------------------------------------------------
*/

const trendChartSeries = computed(() => [
  {
    name: 'Nilai PR',
    data: trend.value.map(
      item => Number(item.pr_amount ?? 0),
    ),
  },
  {
    name: 'Nilai PO',
    data: trend.value.map(
      item => Number(item.po_amount ?? 0),
    ),
  },
])

const trendChartOptions = computed(() => ({
  chart: {
    type: 'bar',
    stacked: false,

    toolbar: {
      show: false,
    },

    zoom: {
      enabled: false,
    },

    animations: {
      enabled: true,
      easing: 'easeinout',
      speed: 650,

      animateGradually: {
        enabled: true,
        delay: 100,
      },

      dynamicAnimation: {
        enabled: true,
        speed: 350,
      },
    },
  },

  plotOptions: {
    bar: {
      horizontal: false,
      columnWidth: '46%',
      borderRadius: 5,
      borderRadiusApplication: 'end',
    },
  },

  dataLabels: {
    enabled: false,
  },

  stroke: {
    show: true,
    width: 2,
    colors: ['transparent'],
  },

  xaxis: {
    categories: trend.value.map(
      item => item.label,
    ),

    labels: {
      rotate: -35,
      rotateAlways: trend.value.length > 8,
      trim: true,
      hideOverlappingLabels: true,
    },

    axisBorder: {
      show: false,
    },

    axisTicks: {
      show: false,
    },
  },

  yaxis: {
    min: 0,

    labels: {
      formatter: (value: number) => {
        return formatCompactCurrency(value)
      },
    },

    title: {
      text: 'Nilai transaksi',
    },
  },

  tooltip: {
    shared: true,
    intersect: false,

    y: {
      formatter: (value: number) => {
        return formatCurrency(value)
      },
    },
  },

  legend: {
    position: 'top',
    horizontalAlign: 'right',
    fontSize: '13px',

    markers: {
      width: 9,
      height: 9,
      radius: 3,
    },

    itemMargin: {
      horizontal: 10,
    },
  },

  grid: {
    borderColor:
      'rgba(var(--v-border-color), 0.22)',

    strokeDashArray: 4,

    xaxis: {
      lines: {
        show: false,
      },
    },

    yaxis: {
      lines: {
        show: true,
      },
    },

    padding: {
      left: 4,
      right: 8,
      top: 0,
      bottom: 0,
    },
  },

  noData: {
    text: 'Belum ada data tren PR dan PO',
    align: 'center',
    verticalAlign: 'middle',
  },
}))

const hasComparisonData = computed(() => {
  return (
    summary.value.total_pr_amount > 0
    || summary.value.total_po_amount > 0
  )
})

const hasTrendData = computed(() => {
  return trend.value.some(item => {
    return (
      Number(item.pr_amount) > 0
      || Number(item.po_amount) > 0
    )
  })
})

const totalStatus = computed(() => {
  return statuses.value.reduce(
    (total, item) => {
      return total + Number(item.total ?? 0)
    },
    0,
  )
})

/*
|--------------------------------------------------------------------------
| Date Helper
|--------------------------------------------------------------------------
*/

function getLocalDateValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(
    date.getMonth() + 1,
  ).padStart(2, '0')

  const day = String(
    date.getDate(),
  ).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function getMonthValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(
    date.getMonth() + 1,
  ).padStart(2, '0')

  return `${year}-${month}`
}

function getFirstDateOfMonth(date: Date): string {
  const firstDate = new Date(
    date.getFullYear(),
    date.getMonth(),
    1,
  )

  return getLocalDateValue(firstDate)
}

function getCurrentWeekValue(date: Date): string {
  const currentDate = new Date(Date.UTC(
    date.getFullYear(),
    date.getMonth(),
    date.getDate(),
  ))

  const dayNumber =
    currentDate.getUTCDay() || 7

  currentDate.setUTCDate(
    currentDate.getUTCDate() + 4 - dayNumber,
  )

  const yearStart = new Date(Date.UTC(
    currentDate.getUTCFullYear(),
    0,
    1,
  ))

  const weekNumber = Math.ceil(
    (
      (
        currentDate.getTime()
        - yearStart.getTime()
      ) / 86400000
      + 1
    ) / 7,
  )

  return `${currentDate.getUTCFullYear()}-W${String(
    weekNumber,
  ).padStart(2, '0')}`
}

/*
|--------------------------------------------------------------------------
| Formatter
|--------------------------------------------------------------------------
*/

function formatNumber(
  value: number | null | undefined,
): string {
  return new Intl.NumberFormat('id-ID').format(
    Number(value ?? 0),
  )
}

function formatDecimal(
  value: number | null | undefined,
): string {
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  }).format(Number(value ?? 0))
}

function formatCurrency(
  value: number | null | undefined,
): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0))
}

function formatCompactCurrency(
  value: number | null | undefined,
): string {
  const amount = Number(value ?? 0)

  if (amount >= 1_000_000_000_000) {
    return `Rp ${formatDecimal(
      amount / 1_000_000_000_000,
    )} T`
  }

  if (amount >= 1_000_000_000) {
    return `Rp ${formatDecimal(
      amount / 1_000_000_000,
    )} M`
  }

  if (amount >= 1_000_000) {
    return `Rp ${formatDecimal(
      amount / 1_000_000,
    )} Jt`
  }

  return formatCurrency(amount)
}

function formatDate(
  value: string | null | undefined,
): string {
  if (!value)
    return '-'

  const date = new Date(`${value}T00:00:00`)

  if (Number.isNaN(date.getTime()))
    return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(date)
}

function formatMonth(
  value: string | null | undefined,
): string {
  if (!value)
    return '-'

  const [year, month] = value.split('-')

  const date = new Date(
    Number(year),
    Number(month) - 1,
    1,
  )

  return new Intl.DateTimeFormat('id-ID', {
    month: 'long',
    year: 'numeric',
  }).format(date)
}

function formatWeek(
  value: string | null | undefined,
): string {
  if (!value)
    return '-'

  const [year, week] = value.split('-W')

  return `Minggu ${Number(week)}, Tahun ${year}`
}

function formatDateTime(
  value: Date | null,
): string {
  if (!value)
    return '-'

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(value)
}

function statusColor(status: string): string {
  const colors: Record<string, string> = {
    DRAFT: 'secondary',
    IN_PROGRESS: 'warning',
    APPROVED: 'success',
    REJECTED: 'error',
    CANCELLED: 'error',
    CLOSED: 'info',
  }

  return colors[
    status.toUpperCase()
  ] ?? 'primary'
}

function statusPercentage(total: number): number {
  if (totalStatus.value <= 0)
    return 0

  return (
    Number(total)
    / totalStatus.value
  ) * 100
}

/*
|--------------------------------------------------------------------------
| Dropdown Normalizer
|--------------------------------------------------------------------------
*/

function extractArray(
  payload: unknown,
): OptionRecord[] {
  if (Array.isArray(payload))
    return payload as OptionRecord[]

  if (
    payload
    && typeof payload === 'object'
    && 'data' in payload
  ) {
    const firstData = (
      payload as {
        data?: unknown
      }
    ).data

    if (Array.isArray(firstData))
      return firstData as OptionRecord[]

    if (
      firstData
      && typeof firstData === 'object'
      && 'data' in firstData
    ) {
      const secondData = (
        firstData as {
          data?: unknown
        }
      ).data

      if (Array.isArray(secondData))
        return secondData as OptionRecord[]
    }
  }

  return []
}

function normalizeOptions(
  records: OptionRecord[],
  type: 'cabang' | 'department',
): SelectOption[] {
  return records
    .map(record => {
      const value =
        record.id
        ?? record.value
        ?? (
          type === 'cabang'
            ? record.cabang_id
            : record.department_id
        )

      let title =
        record.title
        ?? record.label
        ?? record.name
        ?? record.nama

      if (type === 'cabang') {
        title =
          record.nama_cabang
          ?? title
      }

      if (type === 'department') {
        title =
          record.nama_department
          ?? record.nama_departemen
          ?? record.department_name
          ?? title
      }

      if (
        value === undefined
        || value === null
        || !title
      ) {
        return null
      }

      return {
        value,
        title: String(title),
      }
    })
    .filter(
      (item): item is SelectOption => {
        return item !== null
      },
    )
}

/*
|--------------------------------------------------------------------------
| API Filter Options
|--------------------------------------------------------------------------
*/

async function fetchFilterOptions(): Promise<void> {
  isLoadingOptions.value = true

  try {
    /*
     * Scope ALL atau OWN_DEPARTMENT:
     * cabang dapat dipilih.
     */
    if (access.value.can_filter_cabang) {
      const cabangResponse = await axios.get(
        '/master/cabang/options',
      )

      cabangOptions.value = normalizeOptions(
        extractArray(cabangResponse.data),
        'cabang',
      )
    }
    else if (
      access.value.cabang_id
      && access.value.cabang_name
    ) {
      cabangOptions.value = [
        {
          value: access.value.cabang_id,
          title: access.value.cabang_name,
        },
      ]
    }

    /*
     * Hanya scope ALL yang dapat memilih departemen.
     */
    if (
      access.value.can_filter_department
    ) {
      const departmentResponse = await axios.get(
        '/master/department/dropdown-select',
      )

      departmentOptions.value = normalizeOptions(
        extractArray(
          departmentResponse.data,
        ),
        'department',
      )
    }
    else if (
      access.value.department_id
      && access.value.department_name
    ) {
      departmentOptions.value = [
        {
          value: access.value.department_id,
          title: access.value.department_name,
        },
      ]
    }
  }
  catch (error) {
    console.error(
      'Failed to load dashboard filter options:',
      error,
    )
  }
  finally {
    isLoadingOptions.value = false
  }
}

/*
|--------------------------------------------------------------------------
| API Dashboard
|--------------------------------------------------------------------------
*/

function buildFilterParams(): Record<
  string,
  string | number
> {
  const params: Record<
    string,
    string | number
  > = {
    period: selectedPeriod.value,
  }

  if (selectedPeriod.value === 'day')
    params.date = selectedDate.value

  if (selectedPeriod.value === 'week')
    params.week = selectedWeek.value

  if (selectedPeriod.value === 'month')
    params.month = selectedMonth.value

  if (selectedPeriod.value === 'year')
    params.year = selectedYear.value

  if (selectedPeriod.value === 'range') {
    params.start_date = startDate.value
    params.end_date = endDate.value
  }

  if (
    selectedCabangId.value !== null
    && selectedCabangId.value !== ''
  ) {
    params.cabang_id =
      selectedCabangId.value
  }

  if (
    selectedDepartmentId.value !== null
    && selectedDepartmentId.value !== ''
  ) {
    params.department_id =
      selectedDepartmentId.value
  }

  return params
}

async function fetchDashboard(): Promise<void> {
  if (!isFilterValid.value) {
    errorMessage.value =
      'Periode filter belum diisi dengan benar.'

    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    const response =
      await axios.get<DashboardResponse>(
        '/dashboard/purchase-order',
        {
          params: buildFilterParams(),
        },
      )

    const data = response.data.data

    access.value = data.access

    synchronizeFiltersWithAccess()

    summary.value = {
      total_pr: Number(
        data.summary.total_pr ?? 0,
      ),

      total_pr_amount: Number(
        data.summary.total_pr_amount ?? 0,
      ),

      total_po: Number(
        data.summary.total_po ?? 0,
      ),

      total_po_amount: Number(
        data.summary.total_po_amount ?? 0,
      ),

      approved_pr: Number(
        data.summary.approved_pr ?? 0,
      ),

      pr_not_ordered: Number(
        data.summary.pr_not_ordered ?? 0,
      ),

      pending_po_approval: Number(
        data.summary.pending_po_approval ?? 0,
      ),

      outstanding_receipt: Number(
        data.summary.outstanding_receipt ?? 0,
      ),

      rejected_po: Number(
        data.summary.rejected_po ?? 0,
      ),

      conversion_rate: Number(
        data.summary.conversion_rate ?? 0,
      ),
    }

    trend.value = data.trend ?? []
    statuses.value = data.statuses ?? []

    attentionItems.value =
      data.attention_items ?? []

    breakdownByCabang.value =
        data.breakdown?.by_cabang ?? []

    breakdownByDepartment.value =
      data.breakdown?.by_department ?? []

    appliedPeriodLabel.value =
      selectedPeriodDescription.value

    lastUpdatedAt.value = new Date()
  }
  catch (error) {
    console.error(
      'Failed to load procurement dashboard:',
      error,
    )

    errorMessage.value =
      'Data dashboard Purchase Order gagal dimuat.'
  }
  finally {
    isLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

async function applyFilter(): Promise<void> {
  await fetchDashboard()
}

async function resetFilter(): Promise<void> {
  selectedPeriod.value = 'month'
  selectedDate.value = getLocalDateValue(today)
  selectedWeek.value = getCurrentWeekValue(today)
  selectedMonth.value = getMonthValue(today)
  selectedYear.value = currentYear

  startDate.value = getFirstDateOfMonth(today)
  endDate.value = getLocalDateValue(today)

  selectedCabangId.value = null
  selectedDepartmentId.value = null

  await fetchDashboard()
}

async function refreshDashboard(): Promise<void> {
  await fetchDashboard()
}

function backToDashboard(): void {
  router.push('/dashboards/crm')
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(async () => {
  await fetchDashboard()
  await fetchFilterOptions()
})
</script>

<template>
  <section class="purchase-order-dashboard">
    <!-- Header -->
    <VCard class="dashboard-header mb-6">
      <VCardText class="pa-5 pa-md-7">
        <div
          class="d-flex flex-wrap align-center justify-space-between gap-4"
        >
          <div class="d-flex align-center gap-4">
            <VBtn
              icon
              color="secondary"
              variant="tonal"
              @click="backToDashboard"
            >
              <VIcon icon="mdi-arrow-left" />
            </VBtn>

            <VAvatar
              color="success"
              variant="flat"
              rounded="lg"
              size="58"
              class="header-avatar"
            >
              <VIcon
                icon="mdi-file-sign"
                size="31"
              />
            </VAvatar>

            <div>
              <div
                class="d-flex flex-wrap align-center gap-2 mb-1"
              >
                <h1 class="text-h4 font-weight-bold mb-0">
                  Purchase Order Management Dashboard
                </h1>
              </div>

              <p class="text-body-2 text-medium-emphasis mb-0">
                Perbandingan kebutuhan Purchase Requisition
                dan realisasi Purchase Order.
              </p>
            </div>
          </div>

          <div class="text-md-end">
            <div class="text-caption text-medium-emphasis">
              Terakhir diperbarui
            </div>

            <div class="text-body-2 font-weight-medium">
              {{ formatDateTime(lastUpdatedAt) }}
            </div>

            <VBtn
              size="small"
              variant="text"
              color="primary"
              prepend-icon="mdi-refresh"
              :loading="isLoading"
              class="mt-1"
              @click="refreshDashboard"
            >
              Perbarui
            </VBtn>
          </div>
        </div>
      </VCardText>

      <VProgressLinear
        v-if="isLoading"
        color="success"
        indeterminate
      />
    </VCard>

    <!-- Filter -->
    <VCard class="dashboard-card filter-card mb-6">
      <VCardText class="pa-5">
        <div class="filter-header">
          <div>
            <h2 class="text-h6 font-weight-semibold mb-1">
              Filter Data
            </h2>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Periode aktif:
              <strong>
                {{
                  appliedPeriodLabel
                    || selectedPeriodDescription
                }}
              </strong>
            </p>
          </div>

          <VChip
            color="primary"
            variant="tonal"
            prepend-icon="mdi-shield-account-outline"
          >
            Scope {{ access.scope_view }}
          </VChip>
        </div>

        <div class="filter-grid">
          <VSelect
            v-model="selectedPeriod"
            :items="periodOptions"
            item-title="title"
            item-value="value"
            label="Jenis Periode"
            prepend-inner-icon="mdi-calendar-filter-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VTextField
            v-if="selectedPeriod === 'day'"
            v-model="selectedDate"
            type="date"
            label="Pilih Tanggal"
            prepend-inner-icon="mdi-calendar-today-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VTextField
            v-if="selectedPeriod === 'week'"
            v-model="selectedWeek"
            type="week"
            label="Pilih Minggu"
            prepend-inner-icon="mdi-calendar-week-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VTextField
            v-if="selectedPeriod === 'month'"
            v-model="selectedMonth"
            type="month"
            label="Pilih Bulan"
            prepend-inner-icon="mdi-calendar-month-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VSelect
            v-if="selectedPeriod === 'year'"
            v-model="selectedYear"
            :items="yearOptions"
            item-title="title"
            item-value="value"
            label="Pilih Tahun"
            prepend-inner-icon="mdi-calendar-blank-multiple"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <template v-if="selectedPeriod === 'range'">
            <VTextField
              v-model="startDate"
              type="date"
              label="Tanggal Mulai"
              prepend-inner-icon="mdi-calendar-start"
              variant="outlined"
              density="comfortable"
              hide-details
            />

            <VTextField
              v-model="endDate"
              type="date"
              label="Tanggal Selesai"
              prepend-inner-icon="mdi-calendar-end"
              variant="outlined"
              density="comfortable"
              hide-details
            />
          </template>

          <VSelect
            v-model="selectedCabangId"
            :items="cabangOptions"
            item-title="title"
            item-value="value"
            :label="
              access.can_filter_cabang
                ? 'Semua Cabang'
                : 'Cabang'
            "
            prepend-inner-icon="mdi-office-building-outline"
            variant="outlined"
            density="comfortable"
            hide-details
            :readonly="!access.can_filter_cabang"
            :clearable="access.can_filter_cabang"
            :loading="isLoadingOptions"
          />

          <VSelect
            v-model="selectedDepartmentId"
            :items="departmentOptions"
            item-title="title"
            item-value="value"
            :label="
              access.can_filter_department
                ? 'Semua Departemen'
                : 'Departemen'
            "
            prepend-inner-icon="mdi-account-group-outline"
            variant="outlined"
            density="comfortable"
            hide-details
            :readonly="
              !access.can_filter_department
            "
            :clearable="
              access.can_filter_department
            "
            :loading="isLoadingOptions"
          />
        </div>

        <VDivider class="my-5" />

        <div class="filter-footer">
          <div class="text-body-2 text-medium-emphasis">
            <VIcon
              icon="mdi-information-outline"
              size="18"
              class="me-1"
            />

            Filter cabang dan departemen mengikuti scope
            permission pengguna.
          </div>

          <div class="d-flex align-center gap-2">
            <VBtn
              color="secondary"
              variant="tonal"
              prepend-icon="mdi-filter-remove-outline"
              :disabled="isLoading"
              @click="resetFilter"
            >
              Reset
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="mdi-filter-check-outline"
              :loading="isLoading"
              :disabled="!isFilterValid"
              @click="applyFilter"
            >
              Terapkan
            </VBtn>
          </div>
        </div>

        <VAlert
          v-if="!isFilterValid"
          type="warning"
          variant="tonal"
          density="compact"
          class="mt-4"
        >
          Lengkapi pilihan periode terlebih dahulu.
        </VAlert>
      </VCardText>
    </VCard>

    <VAlert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      closable
      class="mb-6"
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

    <!-- Initial Loading -->
    <VRow
      v-if="isLoading && !lastUpdatedAt"
      class="match-height mb-2"
    >
      <VCol
        v-for="index in 4"
        :key="index"
        cols="12"
        sm="6"
        xl="3"
      >
        <VCard class="dashboard-card">
          <VCardText>
            <VSkeletonLoader
              type="list-item-avatar-three-line"
            />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- PR vs PO Cards -->
    <VRow
      v-else
      class="match-height mb-2"
    >
      <VCol
        v-for="(card, index) in statisticCards"
        :key="card.title"
        cols="12"
        sm="6"
        xl="3"
      >
        <VCard
          class="statistic-card dashboard-card h-100 dashboard-enter"
          :style="{
            animationDelay: `${index * 90}ms`,
          }"
        >
          <VCardText class="pa-5">
            <div class="d-flex justify-space-between align-start gap-3">
              <div>
                <VChip
                  :color="card.color"
                  variant="tonal"
                  size="x-small"
                  class="mb-3"
                >
                  {{ card.shortTitle }}
                </VChip>

                <div class="text-body-2 text-medium-emphasis mb-2">
                  {{ card.title }}
                </div>

                <div
                  class="text-h4 font-weight-bold mb-2"
                  :title="card.fullValue ?? card.value"
                >
                  {{ card.value }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ card.subtitle }}
                </div>
              </div>

              <VAvatar
                :color="card.color"
                variant="tonal"
                rounded="lg"
                size="48"
              >
                <VIcon
                  :icon="card.icon"
                  size="27"
                />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Operational Statistics -->
    <VCard class="dashboard-card mb-6">
      <VCardText>
        <VRow>
          <VCol
            v-for="item in operationalStatistics"
            :key="item.title"
            cols="12"
            sm="6"
            lg="3"
          >
            <div class="operational-statistic">
              <VAvatar
                :color="item.color"
                variant="tonal"
                rounded
                size="42"
              >
                <VIcon :icon="item.icon" />
              </VAvatar>

              <div>
                <div class="text-caption text-medium-emphasis">
                  {{ item.title }}
                </div>

                <div class="text-h6 font-weight-bold">
                  {{ item.value }}
                </div>
              </div>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Management Insight -->
    <VAlert
      :type="managementInsight.type"
      :icon="managementInsight.icon"
      variant="tonal"
      class="mb-6 dashboard-enter"
    >
      <div class="font-weight-semibold mb-1">
        {{ managementInsight.title }}
      </div>

      <div class="text-body-2">
        {{ managementInsight.message }}
      </div>
    </VAlert>

    <!-- Comparison and Trend -->
    <VRow class="match-height mb-2">
      <VCol
        cols="12"
        lg="4"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <VCardTitle>
              Perbandingan Nilai PR dan PO
            </VCardTitle>

            <VCardSubtitle>
              Kebutuhan dibandingkan realisasi pembelian
            </VCardSubtitle>
          </VCardItem>

          <VCardText>
            <VueApexCharts
              v-if="hasComparisonData"
              type="bar"
              height="310"
              :options="comparisonChartOptions"
              :series="comparisonChartSeries"
            />

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-chart-bar"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data PR dan PO
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="8"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <VCardTitle>
              Tren Nilai PR dan PO
            </VCardTitle>

            <VCardSubtitle>
              Pergerakan kebutuhan dan realisasi pada
              {{ appliedPeriodLabel || selectedPeriodDescription }}
            </VCardSubtitle>
          </VCardItem>

          <VCardText>
            <VueApexCharts
              v-if="hasTrendData"
              type="bar"
              height="330"
              :options="trendChartOptions"
              :series="trendChartSeries"
            />

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-chart-line"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data tren
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Breakdown Cabang dan Departemen -->
    <VRow class="match-height mb-2">
      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <VCardTitle>
              Analisis PR dan PO per Cabang
            </VCardTitle>

            <VCardSubtitle>
              Perbandingan kebutuhan dan realisasi
              berdasarkan cabang
            </VCardSubtitle>

            <template #append>
              <VSelect
                v-model="cabangBreakdownMetric"
                :items="breakdownMetricOptions"
                item-title="title"
                item-value="value"
                density="compact"
                variant="outlined"
                hide-details
                style="min-inline-size: 170px;"
              />
            </template>
          </VCardItem>

          <VCardText>
            <div
              v-if="breakdownByCabang.length"
              class="breakdown-chart-scroll"
            >
              <VueApexCharts
                type="bar"
                :height="cabangBreakdownChartHeight"
                :options="cabangBreakdownOptions"
                :series="cabangBreakdownSeries"
              />
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-office-building-outline"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data per cabang
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <VCardTitle>
              Analisis PR dan PO per Departemen
            </VCardTitle>

            <VCardSubtitle>
              Perbandingan kebutuhan dan realisasi
              berdasarkan departemen
            </VCardSubtitle>

            <template #append>
              <VSelect
                v-model="departmentBreakdownMetric"
                :items="breakdownMetricOptions"
                item-title="title"
                item-value="value"
                density="compact"
                variant="outlined"
                hide-details
                style="min-inline-size: 170px;"
              />
            </template>
          </VCardItem>

          <VCardText>
            <div
              v-if="breakdownByDepartment.length"
              class="breakdown-chart-scroll"
            >
              <VueApexCharts
                type="bar"
                :height="departmentBreakdownChartHeight"
                :options="departmentBreakdownOptions"
                :series="departmentBreakdownSeries"
              />
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-account-group-outline"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data per departemen
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Status and Attention -->
    <VRow class="match-height">
      <VCol
        cols="12"
        lg="4"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <VCardTitle>
              Status Purchase Order
            </VCardTitle>

            <VCardSubtitle>
              Kondisi PO pada periode terpilih
            </VCardSubtitle>
          </VCardItem>

          <VCardText>
            <div
              v-if="statuses.length"
              class="status-list"
            >
              <div
                v-for="status in statuses"
                :key="status.status"
                class="status-item"
              >
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-body-2">
                    {{ status.label }}
                  </span>

                  <strong>
                    {{ formatNumber(status.total) }}
                  </strong>
                </div>

                <VProgressLinear
                  :model-value="
                    statusPercentage(status.total)
                  "
                  :color="statusColor(status.status)"
                  height="7"
                  rounded
                />
              </div>
            </div>

            <div
              v-else
              class="empty-state"
            >
              Belum ada data status.
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="8"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="warning"
                variant="tonal"
                rounded
              >
                <VIcon icon="mdi-alert-decagram-outline" />
              </VAvatar>
            </template>

            <VCardTitle>
              Purchase Order yang Perlu Perhatian
            </VCardTitle>

            <VCardSubtitle>
              Prioritas keputusan dan tindak lanjut management
            </VCardSubtitle>
          </VCardItem>

          <VCardText class="pa-0">
            <div
              v-if="attentionItems.length"
              class="table-wrapper"
            >
              <VTable hover>
                <thead>
                  <tr>
                    <th>Purchase Order</th>
                    <th>Cabang / Departemen</th>
                    <th>Vendor</th>
                    <th>Nilai</th>
                    <th>Umur</th>
                    <th>Keterangan</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="item in attentionItems"
                    :key="item.public_id"
                  >
                    <td>
                      <div class="font-weight-semibold">
                        {{ item.po_number }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ formatDate(item.po_date) }}
                      </div>
                    </td>

                    <td>
                      <div>
                        {{ item.cabang_name ?? '-' }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ item.department_name ?? '-' }}
                      </div>
                    </td>

                    <td>
                      {{ item.vendor_name ?? '-' }}
                    </td>

                    <td class="text-no-wrap">
                      {{ formatCurrency(item.total_amount) }}
                    </td>

                    <td class="text-no-wrap">
                      {{ formatNumber(item.age_days) }} hari
                    </td>

                    <td>
                      <VChip
                        color="warning"
                        variant="tonal"
                        size="small"
                      >
                        {{ item.reason }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="success"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-check-all"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Tidak ada PO yang perlu perhatian
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </section>
</template>

<style scoped>
.purchase-order-dashboard {
  min-block-size: 100%;
}

.dashboard-header {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background:
    linear-gradient(
      135deg,
      rgba(var(--v-theme-success), 0.12) 0%,
      rgba(var(--v-theme-surface), 1) 58%,
      rgba(var(--v-theme-primary), 0.07) 100%
    );
}

.dashboard-header::after {
  position: absolute;
  border: 28px solid rgba(var(--v-theme-success), 0.05);
  border-radius: 50%;
  block-size: 180px;
  content: '';
  inline-size: 180px;
  inset-block-start: -85px;
  inset-inline-end: -45px;
  pointer-events: none;
}

.header-avatar {
  box-shadow: 0 8px 20px rgba(var(--v-theme-success), 0.22);
}

.dashboard-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.filter-card {
  overflow: visible;
}

.filter-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-block-end: 22px;
}

.filter-grid {
  display: grid;
  align-items: start;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.filter-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.statistic-card {
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;
}

.statistic-card:hover {
  box-shadow: 0 10px 26px rgba(var(--v-shadow-key-umbra-color), 0.12);
  transform: translateY(-4px);
}

.operational-statistic {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-block: 6px;
}

.status-list {
  display: flex;
  flex-direction: column;
  gap: 22px;
}

.status-item {
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-block-end: 16px;
}

.status-item:last-child {
  border-block-end: none;
  padding-block-end: 0;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-block-size: 280px;
  padding: 24px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  text-align: center;
}

.table-wrapper {
  overflow-x: auto;
}

.table-wrapper table {
  min-inline-size: 900px;
}

.table-wrapper th {
  background-color: rgba(var(--v-theme-on-surface), 0.025);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
}

.dashboard-enter {
  animation: dashboard-enter 0.55s ease both;
}

@keyframes dashboard-enter {
  from {
    opacity: 0;
    transform: translateY(14px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 1279px) {
  .filter-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 600px) {
  .header-avatar {
    display: none;
  }

  .filter-header,
  .filter-footer {
    align-items: stretch;
    flex-direction: column;
  }

  .filter-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .filter-footer > div:last-child {
    justify-content: flex-end;
  }
}

@media (prefers-reduced-motion: reduce) {
  .dashboard-enter {
    animation: none;
  }

  .statistic-card {
    transition: none;
  }
}

.breakdown-chart-scroll {
  overflow-y: auto;
  max-block-size: 620px;
  padding-inline-end: 4px;
}

.breakdown-chart-scroll::-webkit-scrollbar {
  inline-size: 6px;
}

.breakdown-chart-scroll::-webkit-scrollbar-thumb {
  border-radius: 10px;
  background-color: rgba(
    var(--v-theme-on-surface),
    0.16
  );
}
</style>