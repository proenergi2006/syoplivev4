<script setup lang="ts">
import axios from '@axios'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { showErrorToast } from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'

interface AxiosErrorShape {
  response?: {
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface SelectOption {
  title: string
  value: string
}

interface SystemLogItem {
  id: string
  timestamp?: string | null
  environment?: string | null
  level?: string | null
  source?: string | null
  module?: string | null
  message?: string | null
  context?: unknown
  exception_class?: string | null
  file?: string | null
  line?: number | string | null
  has_trace?: boolean
  trace?: string | null
  raw?: string | null
}

interface LogSummary {
  total: number
  critical: number
  error: number
  warning: number
  info: number
}

interface LogMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  count: number
  source_file?: string
}

interface LogViewerResponse {
  success?: boolean
  message?: string
  data?: SystemLogItem[]
  summary?: Partial<LogSummary>
  filter_options?: {
    environments?: unknown[]
    sources?: unknown[]
    modules?: unknown[]
  }
  meta?: Partial<LogMeta>
}

const AUTO_REFRESH_SECONDS = 30

const keyword = ref('')
const selectedPeriod = ref('last_7_days')
const selectedLevel = ref('all')
const selectedEnvironment = ref('')
const selectedSource = ref('')
const selectedModule = ref('')
const selectedTrace = ref('')
const startDate = ref('')
const endDate = ref('')

const page = ref(1)
const perPage = ref(25)
const totalItems = ref(0)
const lastPage = ref(1)

const logs = ref<SystemLogItem[]>([])
const summary = ref<LogSummary>({
  total: 0,
  critical: 0,
  error: 0,
  warning: 0,
  info: 0,
})

const environmentOptions = ref<SelectOption[]>([])
const sourceOptions = ref<SelectOption[]>([])
const moduleOptions = ref<SelectOption[]>([])

const isLoading = ref(false)
const isRefreshing = ref(false)
const isAutoRefresh = ref(true)
const refreshCountdown = ref(AUTO_REFRESH_SECONDS)
const lastUpdatedAt = ref<Date | null>(null)

const selectedLog = ref<SystemLogItem | null>(null)
const isDetailDialogOpen = ref(false)
const detailTab = ref('overview')

let searchTimeout: ReturnType<typeof setTimeout> | null = null
let autoRefreshTimer: ReturnType<typeof setInterval> | null = null

const periodOptions: SelectOption[] = [
  { title: 'Hari Ini', value: 'today' },
  { title: '24 Jam Terakhir', value: 'last_24_hours' },
  { title: '7 Hari Terakhir', value: 'last_7_days' },
  { title: 'Minggu Ini', value: 'this_week' },
  { title: 'Minggu Lalu', value: 'last_week' },
  { title: '30 Hari Terakhir', value: 'last_30_days' },
  { title: 'Rentang Tanggal', value: 'custom' },
]

const levelOptions: SelectOption[] = [
  { title: 'Semua Jenis Utama', value: 'all' },
  { title: 'Critical', value: 'CRITICAL' },
  { title: 'Error', value: 'ERROR' },
  { title: 'Warning', value: 'WARNING' },
  { title: 'Info', value: 'INFO' },
  { title: 'Debug', value: 'DEBUG' },
]

const traceOptions: SelectOption[] = [
  { title: 'Semua Log', value: '' },
  { title: 'Ada Stack Trace', value: 'true' },
  { title: 'Tanpa Stack Trace', value: 'false' },
]

const isCustomPeriod = computed(() => {
  return selectedPeriod.value === 'custom'
})

const totalError = computed(() => {
  return summary.value.error + summary.value.critical
})

const hasFilter = computed(() => {
  return Boolean(
    keyword.value
      || selectedPeriod.value !== 'last_7_days'
      || selectedLevel.value !== 'all'
      || selectedEnvironment.value
      || selectedSource.value
      || selectedModule.value
      || selectedTrace.value
      || startDate.value
      || endDate.value,
  )
})

const paginationText = computed(() => {
  const firstIndex = totalItems.value
    ? ((page.value - 1) * perPage.value) + 1
    : 0

  const lastIndex = Math.min(
    logs.value.length + ((page.value - 1) * perPage.value),
    totalItems.value,
  )

  return `${firstIndex}-${lastIndex} dari ${totalItems.value}`
})

const lastUpdatedText = computed(() => {
  if (!lastUpdatedAt.value)
    return '-'

  return new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(lastUpdatedAt.value)
})

const normalizeLevel = (level?: string | null): string => {
  return String(level ?? '').trim().toUpperCase()
}

const getLevelColor = (level?: string | null): string => {
  const value = normalizeLevel(level)

  if (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'].includes(value))
    return 'error'

  if (value === 'WARNING')
    return 'warning'

  if (value === 'INFO')
    return 'info'

  if (value === 'DEBUG')
    return 'secondary'

  return 'default'
}

const getLevelIcon = (level?: string | null): string => {
  const value = normalizeLevel(level)

  if (['EMERGENCY', 'ALERT', 'CRITICAL'].includes(value))
    return 'tabler-alert-triangle'

  if (value === 'ERROR')
    return 'tabler-circle-x'

  if (value === 'WARNING')
    return 'tabler-alert-circle'

  if (value === 'INFO')
    return 'tabler-info-circle'

  if (value === 'DEBUG')
    return 'tabler-bug'

  return 'tabler-bell'
}

const formatTimestamp = (value?: string | null): string => {
  if (!value)
    return '-'

  const normalizedValue = value.includes('T')
    ? value
    : value.replace(' ', 'T')

  const date = new Date(normalizedValue)

  if (Number.isNaN(date.getTime()))
    return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(date)
}

const truncateText = (
  value?: string | null,
  maxLength = 150,
): string => {
  const text = String(value ?? '').trim()

  if (!text)
    return '-'

  if (text.length <= maxLength)
    return text

  return `${text.slice(0, maxLength)}…`
}

const prettyJson = (value: unknown): string => {
  if (value === null || value === undefined || value === '')
    return '-'

  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2)
    } catch {
      return value
    }
  }

  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

const normalizeOptionItems = (
  items: unknown[] | undefined,
): SelectOption[] => {
  if (!Array.isArray(items))
    return []

  const result = new Map<string, SelectOption>()

  items.forEach(item => {
    if (typeof item === 'string') {
      const value = item.trim()

      if (value) {
        result.set(value, {
          title: value,
          value,
        })
      }

      return
    }

    if (!item || typeof item !== 'object')
      return

    const rawItem = item as Record<string, unknown>

    const value = String(
      rawItem.value
      ?? rawItem.id
      ?? rawItem.name
      ?? rawItem.title
      ?? '',
    ).trim()

    const title = String(
      rawItem.title
      ?? rawItem.label
      ?? rawItem.name
      ?? value,
    ).trim()

    if (value) {
      result.set(value, {
        title: title || value,
        value,
      })
    }
  })

  return Array.from(result.values())
}

const calculateSummary = (
  items: SystemLogItem[],
): LogSummary => {
  const result: LogSummary = {
    total: items.length,
    critical: 0,
    error: 0,
    warning: 0,
    info: 0,
  }

  items.forEach(item => {
    const level = normalizeLevel(item.level)

    if (['EMERGENCY', 'ALERT', 'CRITICAL'].includes(level))
      result.critical += 1
    else if (level === 'ERROR')
      result.error += 1
    else if (level === 'WARNING')
      result.warning += 1
    else if (level === 'INFO')
      result.info += 1
  })

  return result
}

const getSelectedLevels = (): string => {
  if (selectedLevel.value === 'all') {
    return [
      'EMERGENCY',
      'ALERT',
      'CRITICAL',
      'ERROR',
      'WARNING',
      'INFO',
    ].join(',')
  }

  return selectedLevel.value
}

const validateFilter = (): boolean => {
  if (!isCustomPeriod.value)
    return true

  if (!startDate.value || !endDate.value) {
    showErrorToast({
      title: 'Rentang Tanggal Belum Lengkap',
      text: 'Tanggal awal dan tanggal akhir wajib dipilih.',
    })

    return false
  }

  if (startDate.value > endDate.value) {
    showErrorToast({
      title: 'Rentang Tanggal Tidak Valid',
      text: 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
    })

    return false
  }

  return true
}

const buildParams = (): Record<string, any> => {
  const params: Record<string, any> = {
    page: page.value,
    per_page: perPage.value,
    period: selectedPeriod.value,
    levels: getSelectedLevels(),
  }

  if (keyword.value.trim())
    params.search = keyword.value.trim()

  if (selectedEnvironment.value)
    params.environment = selectedEnvironment.value

  if (selectedSource.value)
    params.source = selectedSource.value

  if (selectedModule.value)
    params.module = selectedModule.value

  if (selectedTrace.value)
    params.has_trace = selectedTrace.value

  if (isCustomPeriod.value) {
    params.start_date = startDate.value
    params.end_date = endDate.value
  }

  return params
}

const assignResponseData = (
  responseData: LogViewerResponse,
): void => {
  logs.value = Array.isArray(responseData.data)
    ? responseData.data
    : []

  const responseSummary = responseData.summary

  summary.value = responseSummary
    ? {
        total: Number(responseSummary.total ?? logs.value.length),
        critical: Number(responseSummary.critical ?? 0),
        error: Number(responseSummary.error ?? 0),
        warning: Number(responseSummary.warning ?? 0),
        info: Number(responseSummary.info ?? 0),
      }
    : calculateSummary(logs.value)

  page.value = Number(
    responseData.meta?.current_page
    ?? page.value,
  )

  perPage.value = Number(
    responseData.meta?.per_page
    ?? perPage.value,
  )

  totalItems.value = Number(
    responseData.meta?.total
    ?? logs.value.length,
  )

  lastPage.value = Math.max(
    1,
    Number(responseData.meta?.last_page ?? 1),
  )

  environmentOptions.value = normalizeOptionItems(
    responseData.filter_options?.environments,
  )

  sourceOptions.value = normalizeOptionItems(
    responseData.filter_options?.sources,
  )

  moduleOptions.value = normalizeOptionItems(
    responseData.filter_options?.modules,
  )
}

const fetchLogs = async (
  silent = false,
): Promise<void> => {
  if (!validateFilter())
    return

  if (silent)
    isRefreshing.value = true
  else
    isLoading.value = true

  try {
    const response = await axios.get<LogViewerResponse>(
      '/monitoring/logs',
      {
        params: buildParams(),
        headers: {
          Accept: 'application/json',
        },
      },
    )

    assignResponseData(response.data)
    lastUpdatedAt.value = new Date()
    refreshCountdown.value = AUTO_REFRESH_SECONDS
  } catch (error: any) {
    const err = error as AxiosErrorShape

    logs.value = []
    totalItems.value = 0
    lastPage.value = 1

    showErrorToast({
      title: 'Gagal Memuat Data',
      text: getApiErrorMessage(
        err,
        'Gagal memuat monitoring log aplikasi.',
      ),
    })
  } finally {
    isLoading.value = false
    isRefreshing.value = false
  }
}

const reloadData = async (): Promise<void> => {
  page.value = 1
  await fetchLogs()
}

const resetFilter = async (): Promise<void> => {
  keyword.value = ''
  selectedPeriod.value = 'last_7_days'
  selectedLevel.value = 'all'
  selectedEnvironment.value = ''
  selectedSource.value = ''
  selectedModule.value = ''
  selectedTrace.value = ''
  startDate.value = ''
  endDate.value = ''
  page.value = 1

  await fetchLogs()
}

const goToPreviousPage = async (): Promise<void> => {
  if (page.value <= 1)
    return

  page.value -= 1
  await fetchLogs()
}

const goToNextPage = async (): Promise<void> => {
  if (page.value >= lastPage.value)
    return

  page.value += 1
  await fetchLogs()
}

const openDetail = (
  log: SystemLogItem,
): void => {
  selectedLog.value = log
  detailTab.value = 'overview'
  isDetailDialogOpen.value = true
}

const closeDetail = (): void => {
  isDetailDialogOpen.value = false
  selectedLog.value = null
  detailTab.value = 'overview'
}

const copyText = async (
  value: string | null | undefined,
  label: string,
): Promise<void> => {
  const text = String(value ?? '')

  if (!text)
    return

  try {
    await navigator.clipboard.writeText(text)
  } catch {
    showErrorToast({
      title: 'Gagal Menyalin',
      text: `${label} tidak dapat disalin.`,
    })
  }
}

const startAutoRefresh = (): void => {
  if (autoRefreshTimer)
    clearInterval(autoRefreshTimer)

  autoRefreshTimer = setInterval(async () => {
    if (
      !isAutoRefresh.value
      || isLoading.value
      || isRefreshing.value
      || isDetailDialogOpen.value
    ) {
      return
    }

    refreshCountdown.value -= 1

    if (refreshCountdown.value <= 0)
      await fetchLogs(true)
  }, 1000)
}

watch(keyword, () => {
  if (searchTimeout)
    clearTimeout(searchTimeout)

  searchTimeout = setTimeout(async () => {
    page.value = 1
    await fetchLogs()
  }, 500)
})

watch(
  [
    selectedPeriod,
    selectedLevel,
    selectedEnvironment,
    selectedSource,
    selectedModule,
    selectedTrace,
  ],
  async () => {
    if (selectedPeriod.value !== 'custom') {
      startDate.value = ''
      endDate.value = ''
    }

    page.value = 1
    await fetchLogs()
  },
)

watch(isAutoRefresh, () => {
  refreshCountdown.value = AUTO_REFRESH_SECONDS
})

onMounted(async () => {
  await fetchLogs()
  startAutoRefresh()
})

onBeforeUnmount(() => {
  if (searchTimeout)
    clearTimeout(searchTimeout)

  if (autoRefreshTimer)
    clearInterval(autoRefreshTimer)
})
</script>

<template>
  <section>
    <!-- Header -->
    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row justify-space-between gap-4">
          <div>
            <div class="text-overline text-primary font-weight-bold mb-1 text-none">
              Monitoring System
            </div>

            <h2 class="text-h5 font-weight-bold mb-1">
              Log Viewer
            </h2>

            <div class="text-body-2 text-medium-emphasis">
              Pantau error, warning, dan aktivitas aplikasi dari file Laravel log.
            </div>
          </div>

          <div class="d-flex flex-column flex-sm-row align-sm-center gap-3">
            <div class="text-caption text-medium-emphasis text-sm-end">
              <div>
                Terakhir diperbarui: {{ lastUpdatedText }}
              </div>

              <div v-if="isAutoRefresh">
                Refresh otomatis dalam {{ refreshCountdown }} detik
              </div>
            </div>

            <VSwitch
              v-model="isAutoRefresh"
              color="success"
              density="compact"
              hide-details
              label="Auto Refresh"
            />

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-refresh"
              :loading="isLoading || isRefreshing"
              class="text-none"
              @click="fetchLogs"
            >
              Refresh
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Summary -->
    <VRow class="mb-6">
      <VCol cols="12" sm="6" lg="3">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Total Log
                </div>

                <div class="text-h5 font-weight-bold">
                  {{ summary.total }}
                </div>
              </div>

              <VAvatar color="primary" variant="tonal" rounded>
                <VIcon icon="tabler-list-details" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" lg="3">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Error
                </div>

                <div class="text-h5 font-weight-bold text-error">
                  {{ totalError }}
                </div>
              </div>

              <VAvatar color="error" variant="tonal" rounded>
                <VIcon icon="tabler-circle-x" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" lg="3">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Warning
                </div>

                <div class="text-h5 font-weight-bold text-warning">
                  {{ summary.warning }}
                </div>
              </div>

              <VAvatar color="warning" variant="tonal" rounded>
                <VIcon icon="tabler-alert-circle" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" lg="3">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Info
                </div>

                <div class="text-h5 font-weight-bold text-info">
                  {{ summary.info }}
                </div>
              </div>

              <VAvatar color="info" variant="tonal" rounded>
                <VIcon icon="tabler-info-circle" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filter -->
    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="6" lg="4">
            <VTextField
              v-model="keyword"
              label="Cari log"
              placeholder="Cari pesan, exception, file, atau nomor dokumen..."
              prepend-inner-icon="tabler-search"
              clearable
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2">
            <VSelect
              v-model="selectedPeriod"
              label="Periode"
              :items="periodOptions"
              item-title="title"
              item-value="value"
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2">
            <VSelect
              v-model="selectedLevel"
              label="Jenis Log"
              :items="levelOptions"
              item-title="title"
              item-value="value"
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2">
            <VSelect
              v-model="selectedEnvironment"
              label="Environment"
              :items="[
                { title: 'Semua', value: '' },
                ...environmentOptions,
              ]"
              item-title="title"
              item-value="value"
              clearable
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>

          <VCol
            cols="12"
            sm="6"
            lg="2"
            class="d-flex align-center"
          >
            <VBtn
              block
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-filter-off"
              :disabled="!hasFilter"
              class="text-none"
              @click="resetFilter"
            >
              Reset
            </VBtn>
          </VCol>

          <template v-if="isCustomPeriod">
            <VCol cols="12" sm="6" lg="3">
              <VTextField
                v-model="startDate"
                type="date"
                label="Tanggal Awal"
                density="comfortable"
              />
            </VCol>

            <VCol cols="12" sm="6" lg="3">
              <VTextField
                v-model="endDate"
                type="date"
                label="Tanggal Akhir"
                density="comfortable"
              />
            </VCol>
          </template>

          <VCol cols="12" sm="6" lg="4">
            <VAutocomplete
              v-model="selectedModule"
              label="Modul"
              :items="[
                { title: 'Semua Modul', value: '' },
                ...moduleOptions,
              ]"
              item-title="title"
              item-value="value"
              clearable
              density="comfortable"
              no-data-text="Modul tidak ditemukan"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="4">
            <VSelect
              v-model="selectedSource"
              label="Sumber"
              :items="[
                { title: 'Semua Sumber', value: '' },
                ...sourceOptions,
              ]"
              item-title="title"
              item-value="value"
              clearable
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="4">
            <VSelect
              v-model="selectedTrace"
              label="Stack Trace"
              :items="traceOptions"
              item-title="title"
              item-value="value"
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- List -->
    <VCard class="rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row align-md-center justify-space-between gap-3 mb-5">
          <div>
            <h3 class="text-h6 font-weight-bold mb-1">
              Daftar Monitoring Log
            </h3>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Log terbaru ditampilkan paling atas dari file laravel.log.
            </p>
          </div>

          <VChip color="primary" variant="tonal">
            {{ totalItems }} Log
          </VChip>
        </div>

        <div
          v-if="isLoading"
          class="py-4"
        >
          <VSkeletonLoader
            v-for="n in 5"
            :key="n"
            type="list-item-avatar-two-line"
            class="mb-3"
          />
        </div>

        <div
          v-else-if="!logs.length"
          class="py-10 text-center"
        >
          <VAvatar
            color="secondary"
            variant="tonal"
            size="64"
            class="mb-4"
          >
            <VIcon
              icon="tabler-database-off"
              size="34"
            />
          </VAvatar>

          <div class="text-h6 font-weight-semibold mb-1">
            Log tidak ditemukan
          </div>

          <div class="text-body-2 text-medium-emphasis mb-5">
            Tidak ada data yang sesuai dengan filter yang dipilih.
          </div>

          <VBtn
            color="primary"
            prepend-icon="tabler-filter-off"
            class="text-none"
            @click="resetFilter"
          >
            Reset Filter
          </VBtn>
        </div>

        <div
          v-else
          class="log-table-wrapper"
        >
          <VTable class="log-table">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Level</th>
                <th>Modul</th>
                <th>Sumber</th>
                <th>Pesan</th>
                <th>Exception / File</th>
                <th
                  class="text-center"
                  style="width: 90px;"
                >
                  Actions
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="log in logs"
                :key="log.id"
                class="log-row"
                @click="openDetail(log)"
              >
                <td>
                  <div class="log-time-cell">
                    <VAvatar
                      :color="getLevelColor(log.level)"
                      variant="tonal"
                      size="40"
                      class="log-avatar"
                    >
                      <VIcon
                        :icon="getLevelIcon(log.level)"
                        size="20"
                      />
                    </VAvatar>

                    <div class="log-time-info">
                      <div class="font-weight-bold log-time">
                        {{ formatTimestamp(log.timestamp) }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ log.environment || '-' }}
                      </div>
                    </div>
                  </div>
                </td>

                <td>
                  <VChip
                    :color="getLevelColor(log.level)"
                    size="small"
                    variant="tonal"
                  >
                    {{ normalizeLevel(log.level) || 'UNKNOWN' }}
                  </VChip>
                </td>

                <td>
                  <div class="table-text-wrap">
                    {{ log.module || '-' }}
                  </div>
                </td>

                <td>
                  <div class="table-text-wrap">
                    {{ log.source || '-' }}
                  </div>
                </td>

                <td>
                  <div class="log-message">
                    {{ truncateText(log.message, 180) }}
                  </div>

                  <VChip
                    v-if="log.has_trace"
                    color="warning"
                    variant="outlined"
                    size="x-small"
                    class="mt-2"
                  >
                    <VIcon
                      start
                      icon="tabler-code"
                      size="13"
                    />
                    Stack Trace
                  </VChip>
                </td>

                <td>
                  <div
                    v-if="log.exception_class"
                    class="font-weight-medium exception-text"
                  >
                    {{ log.exception_class }}
                  </div>

                  <div
                    v-if="log.file"
                    class="text-caption text-medium-emphasis file-text mt-1"
                  >
                    {{ truncateText(log.file, 80) }}
                    <template v-if="log.line">
                      :{{ log.line }}
                    </template>
                  </div>

                  <span
                    v-if="!log.exception_class && !log.file"
                    class="text-medium-emphasis"
                  >
                    -
                  </span>
                </td>

                <td class="text-center">
                  <VBtn
                    icon
                    size="small"
                    color="primary"
                    variant="tonal"
                    @click.stop="openDetail(log)"
                  >
                    <VIcon icon="tabler-eye" />

                    <VTooltip
                      activator="parent"
                      location="top"
                    >
                      Lihat Detail
                    </VTooltip>
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <VDivider
          v-if="logs.length"
          class="my-5"
        />

        <div
          v-if="logs.length"
          class="d-flex flex-column flex-md-row justify-space-between align-md-center gap-3"
        >
          <div class="text-body-2 text-medium-emphasis">
            {{ paginationText }}
          </div>

          <div class="d-flex align-center gap-2">
            <VSelect
              v-model="perPage"
              :items="[10, 25, 50, 100]"
              density="compact"
              hide-details
              style="max-width: 100px;"
              @update:model-value="reloadData"
            />

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-chevron-left"
              :disabled="page <= 1"
              class="text-none"
              @click="goToPreviousPage"
            >
              Prev
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              append-icon="tabler-chevron-right"
              :disabled="page >= lastPage"
              class="text-none"
              @click="goToNextPage"
            >
              Next
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Detail -->
    <VDialog
      v-model="isDetailDialogOpen"
      max-width="1050"
      scrollable
    >
      <VCard
        v-if="selectedLog"
        class="rounded-lg"
      >
        <VCardItem>
          <template #prepend>
            <VAvatar
              :color="getLevelColor(selectedLog.level)"
              variant="tonal"
              rounded
            >
              <VIcon :icon="getLevelIcon(selectedLog.level)" />
            </VAvatar>
          </template>

          <VCardTitle>
            Detail Monitoring Log
          </VCardTitle>

          <VCardSubtitle>
            {{ formatTimestamp(selectedLog.timestamp) }}
          </VCardSubtitle>

          <template #append>
            <VBtn
              icon
              variant="text"
              color="secondary"
              @click="closeDetail"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </template>
        </VCardItem>

        <VDivider />

        <VTabs
          v-model="detailTab"
          color="primary"
          class="px-4"
        >
          <VTab value="overview">
            Ringkasan
          </VTab>

          <VTab value="context">
            Context
          </VTab>

          <VTab value="trace">
            Stack Trace
          </VTab>

          <VTab value="raw">
            Raw Log
          </VTab>
        </VTabs>

        <VDivider />

        <VCardText>
          <VWindow v-model="detailTab">
            <VWindowItem value="overview">
              <VAlert
                :color="getLevelColor(selectedLog.level)"
                variant="tonal"
                class="mb-5"
              >
                <div class="d-flex flex-wrap align-center gap-2 mb-2">
                  <VChip
                    :color="getLevelColor(selectedLog.level)"
                    size="small"
                    variant="flat"
                  >
                    {{ normalizeLevel(selectedLog.level) }}
                  </VChip>

                  <VChip
                    v-if="selectedLog.module"
                    color="primary"
                    size="small"
                    variant="tonal"
                  >
                    {{ selectedLog.module }}
                  </VChip>

                  <VChip
                    v-if="selectedLog.source"
                    color="secondary"
                    size="small"
                    variant="tonal"
                  >
                    {{ selectedLog.source }}
                  </VChip>
                </div>

                <div class="font-weight-medium text-break">
                  {{ selectedLog.message || '-' }}
                </div>
              </VAlert>

              <VRow>
                <VCol cols="12" md="6">
                  <div class="detail-field">
                    <div class="detail-field-label">
                      Environment
                    </div>

                    <div class="detail-field-value">
                      {{ selectedLog.environment || '-' }}
                    </div>
                  </div>
                </VCol>

                <VCol cols="12" md="6">
                  <div class="detail-field">
                    <div class="detail-field-label">
                      Exception
                    </div>

                    <div class="detail-field-value text-break">
                      {{ selectedLog.exception_class || '-' }}
                    </div>
                  </div>
                </VCol>

                <VCol cols="12" md="9">
                  <div class="detail-field">
                    <div class="detail-field-label">
                      File
                    </div>

                    <div class="detail-field-value text-break">
                      {{ selectedLog.file || '-' }}
                    </div>
                  </div>
                </VCol>

                <VCol cols="12" md="3">
                  <div class="detail-field">
                    <div class="detail-field-label">
                      Line
                    </div>

                    <div class="detail-field-value">
                      {{ selectedLog.line || '-' }}
                    </div>
                  </div>
                </VCol>

                <VCol cols="12">
                  <div class="detail-field">
                    <div class="d-flex align-center justify-space-between gap-3 mb-2">
                      <div class="detail-field-label mb-0">
                        Message
                      </div>

                      <VBtn
                        size="small"
                        variant="text"
                        color="primary"
                        @click="copyText(selectedLog.message, 'Message')"
                      >
                        <VIcon
                          start
                          icon="tabler-copy"
                        />
                        Salin
                      </VBtn>
                    </div>

                    <div class="detail-field-value text-break">
                      {{ selectedLog.message || '-' }}
                    </div>
                  </div>
                </VCol>
              </VRow>
            </VWindowItem>

            <VWindowItem value="context">
              <div class="code-panel">
                <div class="code-panel-header">
                  <span>Context JSON</span>

                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    @click="copyText(prettyJson(selectedLog.context), 'Context')"
                  >
                    <VIcon
                      start
                      icon="tabler-copy"
                    />
                    Salin
                  </VBtn>
                </div>

                <pre>{{ prettyJson(selectedLog.context) }}</pre>
              </div>
            </VWindowItem>

            <VWindowItem value="trace">
              <div class="code-panel">
                <div class="code-panel-header">
                  <span>Stack Trace</span>

                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    :disabled="!selectedLog.trace"
                    @click="copyText(selectedLog.trace, 'Stack Trace')"
                  >
                    <VIcon
                      start
                      icon="tabler-copy"
                    />
                    Salin
                  </VBtn>
                </div>

                <pre>{{ selectedLog.trace || 'Stack trace tidak tersedia.' }}</pre>
              </div>
            </VWindowItem>

            <VWindowItem value="raw">
              <div class="code-panel">
                <div class="code-panel-header">
                  <span>Raw Laravel Log</span>

                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    @click="copyText(selectedLog.raw, 'Raw Log')"
                  >
                    <VIcon
                      start
                      icon="tabler-copy"
                    />
                    Salin
                  </VBtn>
                </div>

                <pre>{{ selectedLog.raw || '-' }}</pre>
              </div>
            </VWindowItem>
          </VWindow>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-5 justify-end">
          <VBtn
            color="primary"
            class="text-none"
            @click="closeDetail"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
.log-table-wrapper {
  overflow-x: auto;
  overflow-y: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
}

.log-table-wrapper :deep(.v-table__wrapper) {
  overflow-x: visible !important;
  overflow-y: visible !important;
}

.log-table {
  min-width: 1280px;
}

.log-table :deep(table) {
  inline-size: 100%;
  min-width: 1280px;
}

.log-table th {
  color: rgba(var(--v-theme-on-surface), 0.72);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  text-transform: uppercase;
  background: rgba(var(--v-theme-background), 0.55);
}

.log-table td {
  padding-block: 14px;
  vertical-align: middle;
}

.log-row {
  cursor: pointer;
  transition: background-color 0.18s ease;
}

.log-row:hover {
  background: rgba(var(--v-theme-primary), 0.035);
}

.log-time-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 230px;
}

.log-avatar {
  flex: 0 0 auto;
}

.log-time-info {
  min-width: 0;
}

.log-time {
  white-space: nowrap;
}

.table-text-wrap {
  min-width: 120px;
  max-width: 180px;
  line-height: 1.35;
  white-space: normal;
  word-break: break-word;
}

.log-message {
  min-width: 280px;
  max-width: 420px;
  line-height: 1.5;
  white-space: normal;
  word-break: break-word;
}

.exception-text,
.file-text {
  min-width: 190px;
  max-width: 270px;
  overflow-wrap: anywhere;
}

.detail-field {
  min-height: 90px;
  padding: 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px;
  background: rgba(var(--v-theme-background), 0.36);
}

.detail-field-label {
  margin-bottom: 8px;
  color: rgba(var(--v-theme-on-surface), 0.58);
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
}

.detail-field-value {
  font-size: 14px;
  font-weight: 500;
  line-height: 1.55;
}

.code-panel {
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
}

.code-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 14px;
  font-weight: 700;
}

.code-panel pre {
  overflow: auto;
  max-height: 520px;
  margin: 0;
  padding: 18px;
  background: rgba(var(--v-theme-on-surface), 0.035);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  line-height: 1.65;
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 960px) {
  .log-table-wrapper {
    margin-inline: -4px;
  }

  .log-table,
  .log-table :deep(table) {
    min-width: 1180px;
  }
}

@media (max-width: 600px) {
  .log-table-wrapper {
    border-radius: 12px;
  }

  .log-table,
  .log-table :deep(table) {
    min-width: 1100px;
  }
}
</style>
