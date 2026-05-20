<script setup lang="ts">
import { computed, onMounted, ref, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from '@axios'
import {
  showLoadingAlert,
  showSuccessToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
  showWarningToast,
  showInfoToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { formatDate } from '@/utils/textFormatter'


interface PurchaseRequestItem {
  id: number
  public_id: string
  nomor_pr: string | null
  tanggal_pr: string | null
  cabang: string | null
  department: string | null
  kategori: string | null
  status: string | null
  status_po: string | null
}

interface PurchaseRequestApiResponse {
  success?: boolean
  status?: boolean
  status_po?: boolean
  data?: PurchaseRequestItem[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const rows = ref<PurchaseRequestItem[]>([])

const searchQuery = ref('')
const selectedStatus = ref('')
const tanggalMulai = ref<string | null>(null)
const tanggalSelesai = ref<string | null>(null)
const tanggalMulaiPicker = useNativeDatePicker(tanggalMulai)
const tanggalSelesaiPicker = useNativeDatePicker(tanggalSelesai)
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalData = ref(0)
const totalPage = ref(1)

const loadError = ref(false)
const detailDialog = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detailPurchaseRequest = ref<any | null>(null)
const detailPurchaseRequestPublicId = ref<string | null>(null)
const openedVendorPanels = ref<number[]>([])

const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<any | null>(null)

const selectedStatusPO = ref('')

const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Draft', value: 'DRAFT' },
  { title: 'In Progress', value: 'IN PROGRESS' },
  { title: 'Approved', value: 'APPROVED' },
  { title: 'Rejected', value: 'REJECTED' },
]

const statusPOItems = [
  { title: 'Semua', value: '' },
  { title: 'Open', value: 'OPEN' },
  { title: 'Partial PO', value: 'PARTIAL' },
  { title: 'Completed', value: 'COMPLETED' },
]

const paginationData = computed(() => {
  if (!totalData.value) return '0-0 of 0'

  const firstIndex = (currentPage.value - 1) * rowPerPage.value + 1
  const lastIndex = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${firstIndex}-${lastIndex} of ${totalData.value}`
})

const detailVendors = computed(() => {
  const vendors = detailPurchaseRequest.value?.vendors || []

  return [...vendors].sort((a, b) => {
    return Number(b.is_selected || false) - Number(a.is_selected || false)
  })
})

const visiblePoCount = ref(2)

const visiblePurchaseOrders = computed(() => {
  const list = detailPurchaseRequest.value?.purchase_orders || []

  return list.slice(0, visiblePoCount.value)
})

const hasMorePurchaseOrders = computed(() => {
  const list = detailPurchaseRequest.value?.purchase_orders || []

  return visiblePoCount.value < list.length
})

const showMorePurchaseOrders = (): void => {
  visiblePoCount.value += 5
}

const formatStatus = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'draft') return 'DRAFT'
  if (normalized === 'in progress') return 'IN PROGRESS'
  if (normalized === 'approved') return 'APPROVED'
  if (normalized === 'rejected') return 'REJECTED'

  return status
}

const getStatusColor = (status: string | null): string => {
  const normalized = String(status || '').toLowerCase()

  if (normalized === 'draft') return 'secondary'
  if (normalized === 'in progress') return 'warning'
  if (normalized === 'approved') return 'success'
  if (normalized === 'rejected') return 'error'

  return 'secondary'
}

const formatStatusPO = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toUpperCase()

  if (normalized === 'OPEN') return 'OPEN'
  if (normalized === 'PARTIAL') return 'PARTIAL PO'
  if (normalized === 'COMPLETED') return 'COMPLETED'

  return status
}

const getStatusPOColor = (status: string | null): string => {
  const normalized = String(status || '').toUpperCase()

  if (normalized === 'OPEN') return 'info'
  if (normalized === 'PARTIAL') return 'warning'
  if (normalized === 'COMPLETED') return 'success'

  return 'secondary'
}

const fetchPurchaseRequests = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get<PurchaseRequestApiResponse>(
      '/transaction/purchase-request',
      {
        headers: {
          Accept: 'application/json',
        },
        params: {
          search: searchQuery.value || undefined,
          status: selectedStatus.value || undefined,
          status_po: selectedStatusPO.value || undefined,
          tanggal_mulai: tanggalMulai.value || undefined,
          tanggal_selesai: tanggalSelesai.value || undefined,
          page: currentPage.value,
          per_page: rowPerPage.value,
        },
      },
    )

    const responseData = response.data

    rows.value = Array.isArray(responseData?.data)
      ? responseData.data
      : []

    const meta = responseData?.meta

    totalData.value = Number(meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(meta?.last_page ?? 1)
    currentPage.value = Number(meta?.current_page ?? 1)
  } catch (error: unknown) {
    loadError.value = true
    const err = error as AxiosErrorShape

    console.error('[Purchase Request] FETCH ERROR:', err)

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat data purchase request'),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = ''
  selectedStatusPO.value = ''
  tanggalMulai.value = null
  tanggalSelesai.value = null
  currentPage.value = 1

  await fetchPurchaseRequests()
}

const submitPurchaseRequest = async (row: any): Promise<void> => {
  if (!row?.public_id) return

  const confirm = await showConfirmAlert({
    title: 'Submit Purchase Request?',
    text: `${row.nomor_pr} akan dikirim untuk proses approval`,
    confirmButtonText: 'Ya, submit',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Submit Purchase Request...', 'Mohon tunggu sebentar')

    const response = await axios.patch(
      `/transaction/purchase-request/${row.public_id}/submit`,
      {},
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Purchase Request berhasil disubmit.',
    })

    await fetchPurchaseRequests()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal submit Purchase Request.'),
    })
  }
}

const goToCreate = (): void => {
  router.push('/non_trade/purchase_request/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/non_trade/purchase_request/edit?id=${publicId}`)
}

const openDelete = (row: any): void => {
  deleteTarget.value = row
  deleteDialog.value = true
}

const closeDelete = (): void => {
  deleteDialog.value = false
  deleteTarget.value = null
}

const confirmDelete = async (): Promise<void> => {
  if (!deleteTarget.value || deleteLoading.value) return

  deleteLoading.value = true

  const prPublicId = deleteTarget.value.public_id
  const nomorPr = deleteTarget.value.nomor_pr

  try {
    closeDelete()

    showLoadingAlert('Menghapus Purchase Request...', 'Mohon tunggu sebentar')

    await axios.delete(`/transaction/purchase-request/${prPublicId}`)

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: `Purchase Request "${nomorPr}" berhasil dihapus`,
    })

    await fetchPurchaseRequests()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal menghapus Purchase Request'),
    })
  } finally {
    deleteLoading.value = false
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  if (!publicId) return

  visiblePoCount.value = 2
  detailDialog.value = true
  detailLoading.value = true
  detailError.value = ''
  detailPurchaseRequest.value = null
  detailPurchaseRequestPublicId.value = publicId

  try {
    const response = await axios.get(`/transaction/purchase-request/${publicId}`, {
      headers: {
        Accept: 'application/json',
      },
    })

    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data purchase request tidak ditemukan')
    }

    detailPurchaseRequest.value = detail

    await nextTick()

    const selectedIndex = detailVendors.value.findIndex(v => v.is_selected)
    openedVendorPanels.value = selectedIndex >= 0 ? [selectedIndex] : []
  } catch (error: unknown) {
    const err = error as AxiosErrorShape

    detailError.value = getApiErrorMessage(
      err,
      'Gagal memuat detail purchase request',
    )

    detailLoading.value = false

  } finally {
    detailLoading.value = false
  }
}

const calcDetailGrandTotal = (items: any[] = []): number => {
  return items.reduce((total, item) => {
    return total + Number(item.subtotal || 0)
  }, 0)
}

watch(currentPage, async () => {
  await fetchPurchaseRequests()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchPurchaseRequests()
})

watch([searchQuery, selectedStatus, selectedStatusPO, tanggalMulai, tanggalSelesai], async () => {
  currentPage.value = 1
  await fetchPurchaseRequests()
})

watch(tanggalSelesai, async (newValue) => {
  if (!newValue || !tanggalMulai.value) return

  const startDate = new Date(tanggalMulai.value)
  const endDate = new Date(newValue)

  if (endDate < startDate) {
    tanggalSelesai.value = null

    showErrorToast({
      title: 'Tanggal Tidak Valid',
      text: 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
    })
  }
})

watch(tanggalMulai, async (newValue) => {
  if (!newValue || !tanggalSelesai.value) return

  const startDate = new Date(newValue)
  const endDate = new Date(tanggalSelesai.value)

  if (endDate < startDate) {
    tanggalSelesai.value = null

    showErrorToast({
      title: 'Tanggal Tidak Valid',
      text: 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
    })
  }
})

onMounted(async () => {
  await fetchPurchaseRequests()

  const success = route.query.success

  if (success) {
    await router.replace({
      path: '/non_trade/purchase_request',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Purchase Request berhasil disimpan.',
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Purchase Request berhasil diperbarui.',
        })
      }
    }, 300)
  }
})
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <VTextField
              v-model="searchQuery"
              label="Cari kode PR"
              placeholder="Cari purchase request..."
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="4">
            <div class="position-relative">
              <VTextField
                :model-value="tanggalMulaiPicker.displayValue.value"
                label="Tanggal Awal"
                placeholder="DD/MM/YYYY"
                readonly
                clearable
                density="compact"
                append-inner-icon="tabler-calendar"
                @click="tanggalMulaiPicker.openPicker"
                @click:append-inner="tanggalMulaiPicker.openPicker"
                @click:clear="tanggalMulai = null"
              />

              <input
                :ref="(el) => {
                  tanggalMulaiPicker.nativeDateRef.value = el as HTMLInputElement | null
                }"
                type="date"
                :value="tanggalMulai || ''"
                class="native-date-hidden"
                tabindex="-1"
                aria-hidden="true"
                @change="tanggalMulaiPicker.onDateChange"
              >
            </div>
          </VCol>

          <VCol cols="12" md="4">
            <div class="position-relative">
              <VTextField
                :model-value="tanggalSelesaiPicker.displayValue.value"
                label="Tanggal Akhir"
                placeholder="DD/MM/YYYY"
                readonly
                clearable
                density="compact"
                append-inner-icon="tabler-calendar"
                @click="tanggalSelesaiPicker.openPicker"
                @click:append-inner="tanggalSelesaiPicker.openPicker"
                @click:clear="tanggalSelesai = null"
              />

              <input
                :ref="(el) => {
                  tanggalSelesaiPicker.nativeDateRef.value = el as HTMLInputElement | null
                }"
                type="date"
                :value="tanggalSelesai || ''"
                class="native-date-hidden"
                tabindex="-1"
                aria-hidden="true"
                @change="tanggalSelesaiPicker.onDateChange"
              >
            </div>
          </VCol>
        </VRow>

        <VRow class="mt-1 align-center">
          <VCol cols="12" md="4">
            <VSelect
              v-model="selectedStatus"
              label="Status Approval"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
            />
          </VCol>

          <VCol cols="12" md="4">
            <VSelect
              v-model="selectedStatusPO"
              label="Status PO"
              :items="statusPOItems"
              item-title="title"
              item-value="value"
              density="compact"
            />
          </VCol>

          <VCol cols="12" md="4" class="d-flex justify-end">
            <VBtn
              variant="outlined"
              color="secondary"
              prepend-icon="tabler-refresh"
              @click="resetFilters"
            >
              Reset Filter
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="goToCreate">
          + Tambah Purchase Request
        </VBtn>

        <VSpacer />

        <div class="d-flex align-center gap-2">
          <!-- LOADING -->
          <VChip
            v-if="loading"
            size="small"
            variant="tonal"
          >
            Loading...
          </VChip>

          <!-- ERROR -->
          <VBtn
            v-else-if="loadError"
            size="small"
            color="error"
            variant="tonal"
            prepend-icon="tabler-refresh"
            @click="fetchPurchaseRequests"
          >
            Reload Data
          </VBtn>
        </div>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">NO</th>
            <th scope="col">NOMOR PR</th>
            <th scope="col">TANGGAL</th>
            <th scope="col">CABANG</th>
            <th scope="col">DEPARTMENT</th>
            <th scope="col">STATUS APPROVAL</th>
            <th scope="col">STATUS PO</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id">
            <td class="text-medium-emphasis">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>
            <td class="text-medium-emphasis">{{ v.nomor_pr || '-' }}</td>
            <td class="text-medium-emphasis">{{ formatDate(v.tanggal_pr) }}</td>
            <td class="text-medium-emphasis">{{ v.cabang || '-' }}</td>
            <td class="text-medium-emphasis">{{ v.department || '-' }}</td>
            <td>
              <VChip
                :color="getStatusColor(v.status)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatus(v.status) }}
              </VChip>
            </td>
            <td>
              <template v-if="v.status_po">
                <VChip
                  :color="getStatusPOColor(v.status_po)"
                  size="small"
                  class="text-capitalize"
                >
                  {{ formatStatusPO(v.status_po) }}
                </VChip>
              </template>

              <span
                v-else
                class="text-medium-emphasis"
              >
                -
              </span>
            </td>
            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem
                      href="javascript:void(0)"
                      @click="openDetail(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-eye"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        Lihat Detail
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft'"
                      href="javascript:void(0)"
                      @click="submitPurchaseRequest(v)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-send-outline" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>Submit</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft'"
                      href="javascript:void(0)"
                      @click="goToEdit(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-pencil-outline"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft'"
                      href="javascript:void(0)"
                      @click="openDelete(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-delete-outline"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>Delete</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="8" class="text-center">
              No data available
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <!-- Footer pagination -->
      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 220px;">
          <span class="text-no-wrap me-3">Rows per page:</span>

          <VSelect
            v-model="rowPerPage"
            density="compact"
            variant="plain"
            class="user-pagination-select"
            :items="[10, 20, 30, 50]"
          />
        </div>

        <div class="d-flex align-center">
          <h6 class="text-sm font-weight-regular">
            {{ paginationData }}
          </h6>

          <VPagination
            v-model="currentPage"
            size="small"
            :total-visible="1"
            :length="totalPage"
          />
        </div>
      </VCardText>
    </VCard>

    <VDialog
      v-model="detailDialog"
      max-width="1250"
      persistent
      scrollable
    >
      <VCard class="pr-detail-card">
        <VCardTitle class="pr-detail-header px-6 py-5">
          <div class="d-flex align-center gap-3">
            <VAvatar
              color="primary"
              variant="tonal"
              size="42"
            >
              <VIcon icon="tabler-file-description" />
            </VAvatar>

            <div>
              <div class="text-h6 font-weight-bold">
                Detail Purchase Request
              </div>
            </div>

            <VChip
              v-if="detailPurchaseRequest"
              size="small"
              variant="tonal"
              :color="getStatusColor(detailPurchaseRequest.status)"
              class="text-capitalize ms-2"
            >
              {{ formatStatus(detailPurchaseRequest.status) || '-' }}
            </VChip>
          </div>

          <VBtn
            icon
            variant="text"
            color="primary"
            @click="detailDialog = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText class="pa-6">
          <div
            v-if="detailLoading"
            class="d-flex flex-column align-center justify-center py-12"
          >
            <VProgressCircular
              indeterminate
              size="46"
              width="4"
              color="primary"
            />
            <div class="mt-4 text-medium-emphasis">
              Memuat detail purchase request...
            </div>
          </div>

          <VAlert
            v-else-if="detailError"
            type="error"
            variant="tonal"
            border="start"
          >
            <div class="d-flex align-center justify-space-between w-100">
              <div>{{ detailError }}</div>

              <VBtn
                size="small"
                color="error"
                variant="outlined"
                prepend-icon="tabler-refresh"
                @click="detailPurchaseRequestPublicId && openDetail(detailPurchaseRequestPublicId)"
              >
                Coba Lagi
              </VBtn>
            </div>
          </VAlert>

          <div v-else-if="detailPurchaseRequest">
            <!-- SUMMARY TOP -->
            <VRow class="mb-5">
              <VCol cols="12" md="8">
                <VCard
                  class="h-100 rounded-xl pr-info-card"
                >
                  <VCardText>
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                      <div>
                        <div class="text-caption text-medium-emphasis">
                          Purchase Request
                        </div>
                        <div class="text-h6 font-weight-bold">
                          {{ detailPurchaseRequest.nomor_pr || '-' }}
                        </div>
                      </div>

                      <VChip
                        size="small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="tabler-calendar"
                      >
                        {{ formatDate(detailPurchaseRequest.tanggal_pr) || '-' }}
                      </VChip>
                    </div>

                    <VRow>
                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Cabang</div>
                          <div class="info-value">{{ detailPurchaseRequest.cabang || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Department</div>
                          <div class="info-value">{{ detailPurchaseRequest.department || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Kategori</div>
                          <div class="info-value">{{ detailPurchaseRequest.kategori || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Requested By</div>
                          <div class="info-value">{{ detailPurchaseRequest.requested_by || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12">
                        <div class="info-box">
                          <div class="info-label">Notes</div>
                          <div class="info-value">
                            {{ detailPurchaseRequest.notes || '-' }}
                          </div>
                        </div>
                      </VCol>
                    </VRow>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="4">
                <VCard class="h-100 rounded-xl total-card">
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Grand Total Estimasi
                    </div>
                    <div class="text-h5 font-weight-bold mb-4">
                      Rp {{ formatNumberWithoutRp(detailPurchaseRequest.total_amount || calcDetailGrandTotal(detailPurchaseRequest.items)) }}
                    </div>

                    <div class="text-caption text-medium-emphasis mb-2">
                      Status PO
                    </div>

                    <div class="mb-4">
                      <template v-if="detailPurchaseRequest.status_po">
                        <VChip
                          :color="getStatusPOColor(detailPurchaseRequest.status_po)"
                          size="small"
                          variant="tonal"
                        >
                          {{ formatStatusPO(detailPurchaseRequest.status_po) }}
                        </VChip>
                      </template>

                      <span
                        v-else
                        class="text-medium-emphasis"
                      >
                        -
                      </span>
                    </div>

                    <div
                      v-if="detailPurchaseRequest.status_po
                        && detailPurchaseRequest.status_po !== 'OPEN'
                        && detailPurchaseRequest.purchase_orders?.length"
                      class="mb-4"
                    >
                      <div class="d-flex align-center justify-space-between mb-2">
                        <div class="text-caption text-medium-emphasis">
                          Purchase Order Terkait
                        </div>

                        <VChip
                          size="x-small"
                          color="primary"
                          variant="tonal"
                        >
                          {{ detailPurchaseRequest.purchase_orders.length }} PO
                        </VChip>
                      </div>

                      <div class="related-po-scroll">
                        <div class="d-flex flex-column gap-2">
                          <TransitionGroup
                            name="po-slide"
                            tag="div"
                            class="d-flex flex-column gap-2"
                          >
                            <div
                              v-for="po in visiblePurchaseOrders"
                              :key="po.id"
                              class="related-po-item"
                            >
                              <div class="d-flex align-start gap-2">
                                <VAvatar
                                  size="28"
                                  color="primary"
                                  variant="tonal"
                                >
                                  <VIcon
                                    icon="tabler-file-invoice"
                                    size="16"
                                  />
                                </VAvatar>

                                <div class="flex-grow-1 min-w-0">
                                  <div class="font-weight-bold text-primary related-po-number">
                                    {{ po.nomor_po }}
                                  </div>

                                  <div class="related-po-meta">
                                    <span>Rp {{ formatNumberWithoutRp(po.total_nilai || 0) }}</span>
                                    <span>{{ formatDate(po.tanggal_po) }}</span>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </TransitionGroup>

                          <VBtn
                            v-if="hasMorePurchaseOrders"
                            size="small"
                            variant="tonal"
                            color="primary"
                            block
                            prepend-icon="tabler-chevron-down"
                            @click="showMorePurchaseOrders"
                          >
                            Tampilkan lainnya
                          </VBtn>
                        </div>
                      </div>
                    </div>

                    <VDivider class="my-4" />

                    <div class="text-caption text-medium-emphasis mb-2">
                      Vendor Rekomendasi
                    </div>

                    <div v-if="detailPurchaseRequest.recommended_vendor">
                      <div class="d-flex align-center gap-2 mb-2">
                        <VAvatar
                          size="32"
                          color="success"
                          variant="tonal"
                        >
                          <VIcon icon="tabler-building-store" />
                        </VAvatar>

                        <div>
                          <div class="font-weight-bold">
                            {{ detailPurchaseRequest.recommended_vendor.nama_vendor || '-' }}
                          </div>
                          <div class="text-caption text-medium-emphasis">
                            {{ formatStatusPKP(detailPurchaseRequest.recommended_vendor.status_pkp) }}
                          </div>
                        </div>
                      </div>

                      <VChip
                        color="success"
                        size="small"
                        variant="tonal"
                        prepend-icon="tabler-check"
                      >
                        Direkomendasikan
                      </VChip>
                    </div>

                    <VAlert
                      v-else
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      Tidak ada vendor rekomendasi
                    </VAlert>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <!-- ATTACHMENTS -->
            <VCard
              flat
              class="rounded-xl"
            >
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      icon="tabler-paperclip"
                      color="primary"
                    />
                    <div class="text-subtitle-1 font-weight-bold">
                      Lampiran Purchase Request
                    </div>
                  </div>

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                  >
                    {{ detailPurchaseRequest.attachments?.length || 0 }} File
                  </VChip>
                </div>

                <div
                  v-if="detailPurchaseRequest.attachments?.length"
                  class="d-flex flex-wrap gap-2"
                >
                  <VBtn
                    v-for="(file, index) in detailPurchaseRequest.attachments"
                    :key="index"
                    :href="file.filepath"
                    target="_blank"
                    variant="tonal"
                    color="primary"
                    size="small"
                    prepend-icon="tabler-external-link"
                  >
                    {{ file.original_filename || 'Lampiran PR' }}
                  </VBtn>
                </div>

                <VAlert
                  v-else
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  Tidak ada lampiran purchase request.
                </VAlert>
              </VCardText>
            </VCard>

            <!-- ITEMS -->
            <VCard
              flat
              class="rounded-xl"
            >
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Daftar Item Purchase Request
                    </div>
                  </div>

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                    prepend-icon="tabler-list-details"
                  >
                    {{ detailPurchaseRequest.items?.length || 0 }} Item
                  </VChip>
                </div>

                <div class="detail-item-table-wrapper">
                  <VTable class="detail-item-table">
                    <thead>
                      <tr>
                        <th class="text-center col-no">No</th>
                        <th class="col-item">Nama Item</th>
                        <th class="col-note">Keterangan</th>
                        <th class="text-center col-unit">Satuan</th>
                        <th class="text-center col-qty">Qty</th>
                        <th class="text-center col-qty">Qty PO</th>
                        <th class="text-center col-outstanding">Outstanding</th>
                        <th class="text-end col-money">Harga Unit</th>
                        <th class="text-end col-money">Subtotal PR</th>
                        <th class="text-end col-money">Subtotal PO</th>
                        <th class="text-end col-money">Outstanding Amount</th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="(item, itemIndex) in detailPurchaseRequest.items || []"
                        :key="item.id || itemIndex"
                      >
                        <td class="text-center col-no">{{ Number(itemIndex) + 1 }}</td>

                        <td class="col-item">
                          <div class="item-title">
                            {{ toTitleCase(item.nama_item) || '-' }}
                          </div>
                          <div
                            v-if="item.spesifikasi"
                            class="item-subtitle"
                          >
                            {{ item.spesifikasi }}
                          </div>
                        </td>

                        <td class="col-note">
                          <div class="text-wrap-cell">
                            {{ item.keterangan || '-' }}
                          </div>
                        </td>

                        <td class="text-center col-unit">
                          <VChip size="x-small" variant="tonal" color="secondary">
                            {{ item.satuan?.nama || '-' }}
                          </VChip>
                        </td>

                        <td class="text-center col-qty">{{ formatDecimalQty(item.qty) }}</td>
                        <td class="text-center col-qty">{{ formatDecimalQty(item.qty_po) }}</td>

                        <td class="text-center col-outstanding">
                          <VChip
                            size="default"
                            :color="Number(item.qty_outstanding || 0) > 0 ? 'warning' : 'success'"
                            variant="tonal"
                          >
                            {{ formatDecimalQty(item.qty_outstanding) }}
                          </VChip>
                        </td>

                        <td class="text-end col-money">{{ formatNumberWithoutRp(item.harga_unit) }}</td>
                        <td class="text-end col-money font-weight-bold">{{ formatNumberWithoutRp(item.subtotal) }}</td>
                        <td class="text-end col-money">{{ formatNumberWithoutRp(item.subtotal_po) }}</td>
                        <td class="text-end col-money font-weight-bold">{{ formatNumberWithoutRp(item.subtotal_outstanding) }}</td>
                      </tr>

                      <tr v-if="!detailPurchaseRequest.items?.length">
                        <td colspan="11" class="text-center text-medium-emphasis py-6">
                          Item belum tersedia.
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </div>

                <div class="d-flex justify-end mt-4">
                  <VCard
                    variant="tonal"
                    class="summary-total-box"
                  >
                    <VCardText class="py-3 px-4">
                      <div class="summary-row">
                        <span>Grand Total PR</span>
                        <strong>Rp {{ formatNumberWithoutRp(detailPurchaseRequest.total_amount || 0) }}</strong>
                      </div>

                      <div class="summary-row">
                        <span>Total Sudah PO</span>
                        <strong>Rp {{ formatNumberWithoutRp(detailPurchaseRequest.total_po || 0) }}</strong>
                      </div>

                      <VDivider class="my-2" />

                      <div class="summary-row outstanding">
                        <span>Total Outstanding</span>
                        <strong>Rp {{ formatNumberWithoutRp(detailPurchaseRequest.total_outstanding || 0) }}</strong>
                      </div>
                    </VCardText>
                  </VCard>
                </div>
              </VCardText>
            </VCard>
          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end px-6 py-4">
          <VBtn
            variant="tonal"
            @click="detailDialog = false"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="deleteDialog"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h6">
          Hapus Purchase Request?
        </VCardTitle>

        <VCardText>
          Apakah Anda yakin ingin menghapus Purchase Request
          <strong>{{ deleteTarget?.nomor_pr }}</strong>?
          <br>
          Data yang dihapus tidak dapat dikembalikan.
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="deleteLoading"
            @click="closeDelete"
          >
            Batal
          </VBtn>

          <VBtn
            color="error"
            :loading="deleteLoading"
            @click="confirmDelete"
          >
            Ya, Hapus
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </section>
</template>

<style lang="scss">
.text-capitalize { text-transform: capitalize; }
</style>

<style lang="scss" scoped>

.po-slide-enter-active {
  transition: all 0.28s ease;
}

.po-slide-enter-from {
  opacity: 0;
  transform: translateY(-8px);
  max-height: 0;
}

.po-slide-enter-to {
  opacity: 1;
  transform: translateY(0);
  max-height: 90px;
}

.related-po-scroll {
  max-height: 260px;
  overflow-y: auto;
  padding-right: 4px;
}

.related-po-scroll::-webkit-scrollbar {
  width: 6px;
}

.related-po-scroll::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(var(--v-theme-primary), 0.25);
}

.related-po-item {
  padding: 10px 12px;
  border-radius: 14px;
  background: rgba(var(--v-theme-primary), 0.08);
}

.related-po-number {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.3;
}

.related-po-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 4px;
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-size: 12px;
  white-space: nowrap;
}

.related-po-meta span:first-child {
  font-weight: 600;
}

.related-po-meta span:last-child {
  text-align: right;
}

.related-po-amount {
  min-width: 110px;
  font-size: 12px;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.78);
}

@media (max-width: 600px) {
  .related-po-amount {
    min-width: auto;
    font-size: 11px;
  }
}

.detail-item-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-radius: 18px;
}

.detail-item-table {
  width: 100%;
  min-width: 1180px;
  table-layout: fixed;
}

.detail-item-table th,
.detail-item-table td {
  padding: 12px 10px !important;
  vertical-align: top;
}

.detail-item-table th {
  white-space: nowrap;
}

.col-no {
  width: 56px;
}

.col-item {
  width: 220px;
}

.col-note {
  width: 220px;
}

.col-unit {
  width: 90px;
}

.col-qty {
  width: 80px;
}

.col-outstanding {
  width: 120px;
}

.col-money {
  width: 150px;
}

.item-title {
  font-weight: 700;
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.35;
}

.item-subtitle,
.text-wrap-cell {
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-size: 12px;
  line-height: 1.35;
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
}

@media (max-width: 1280px) {
  .detail-item-table {
    min-width: 1080px;
  }

  .col-item,
  .col-note {
    width: 190px;
  }

  .col-money {
    width: 135px;
  }
}

.summary-total-box {
  min-width: 360px;
  border-radius: 18px;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  margin-block: 6px;
  color: rgba(var(--v-theme-on-surface), 0.72);
}

.summary-row strong {
  color: rgba(var(--v-theme-on-surface), 0.85);
}

.summary-row.outstanding {
  font-weight: 700;
}

.summary-row.outstanding strong {
  color: rgb(var(--v-theme-warning));
}

.pr-detail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.08), rgba(var(--v-theme-primary), 0.02));
}

.pr-info-card {
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.10),
    rgba(var(--v-theme-surface), 1)
  );
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.06);
}

.pr-detail-card {
  overflow: hidden;
}

.info-box {
  padding: 12px 14px;
  border: 1px solid rgba(var(--v-theme-primary), 0.10);
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.72);
}

.info-label {
  margin-bottom: 4px;
  color: rgba(var(--v-theme-on-surface), 0.58);
  font-size: 12px;
}

.info-value {
  color: rgba(var(--v-theme-on-surface), 0.78);
  font-weight: 700;
}

.total-card {
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.12), rgba(var(--v-theme-surface), 1));
}

.detail-item-table-wrapper {
  overflow-x: auto;
  border-radius: 18px;
  background: rgba(var(--v-theme-surface), 1);
}

.detail-item-table {
  border-collapse: separate;
  border-spacing: 0;
}

.detail-item-table th {
  white-space: nowrap;
  background: rgba(var(--v-theme-primary), 0.05);
  color: rgba(var(--v-theme-on-surface), 0.75);
  font-weight: 700;
  border-bottom: 1px solid rgba(var(--v-border-color), 0.08);
}

.detail-item-table td {
  vertical-align: middle;
  border-bottom: 1px solid rgba(var(--v-border-color), 0.06);
}

.detail-item-table tbody tr:last-child td {
  border-bottom: none;
}

.summary-total-box {
  min-width: 320px;
  border-radius: 18px;
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.10),
    rgba(var(--v-theme-primary), 0.03)
  );
  border: 1px solid rgba(var(--v-theme-primary), 0.08);
  box-shadow: 0 8px 20px rgba(0,0,0,.04);
}

.summary-table {
  width: 100%;
  border-collapse: collapse;
}

.summary-table td {
  padding: 6px 20px;
}

.label-col {
  width: 100%;
}

.currency-col {
  width: 40px;
  text-align: right;
  white-space: nowrap;
  font-weight: 600;
}

.amount-col {
  width: 180px;
  text-align: right;
  white-space: nowrap;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.grand-total-row td {
  padding-top: 14px;
  font-size: 16px;
  font-weight: 700;
}

.divider-row td {
  padding-top: 10px;
  padding-bottom: 10px;
}

.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}

.vendor-detail-content {
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.detail-section {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-top: 20px;
}

.detail-section-title {
  font-size: 1.05rem;
  font-weight: 700;
  margin-bottom: 18px;
}

.detail-item {
  margin-bottom: 16px;
}

.detail-label {
  font-size: 0.78rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 4px;
}

.detail-value {
  font-size: 0.98rem;
  font-weight: 500;
  word-break: break-word;
  line-height: 1.6;
}

.pkp-split-row {
  align-items: stretch;
}

.pkp-col {
  padding-top: 4px;
  padding-bottom: 4px;
}

.pkp-col-right {
  border-left: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

@media (max-width: 959px) {
  .pkp-col-right {
    border-left: none;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    margin-top: 16px;
    padding-top: 20px;
  }
}
</style>
