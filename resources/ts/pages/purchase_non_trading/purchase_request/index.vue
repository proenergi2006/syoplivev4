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
import { formatStatusPKP, formatNumberWithoutRp } from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'


interface PurchaseRequestItem {
  id: number
  public_id: string
  nomor_pr: string | null
  tanggal_pr: string | null
  cabang: string | null
  department: string | null
  kategori: string | null
  status: string | null
}

interface PurchaseRequestApiResponse {
  success?: boolean
  status?: boolean
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

const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Draft', value: 'DRAFT' },
  { title: 'In Progress', value: 'IN PROGRESS' },
  { title: 'Approved', value: 'APPROVED' },
  { title: 'Rejected', value: 'REJECTED' },
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

const formatDate = (value: string | null): string => {
  if (!value) return '-'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

const formatStatus = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'draft') return 'Draft'
  if (normalized === 'in progress') return 'In Progress'
  if (normalized === 'approved') return 'Approved'
  if (normalized === 'rejected') return 'Rejected'

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
  tanggalMulai.value = null
  tanggalSelesai.value = null
  currentPage.value = 1

  await fetchPurchaseRequests()
}

const submitPurchaseRequest = async (row: any): Promise<void> => {
  if (!row?.public_id) return

  const confirm = await showConfirmAlert({
    title: 'Submit Purchase Request?',
    text: `${row.nomor_pr} akan dikirim untuk proses approval.`,
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
  router.push('/purchase_non_trading/purchase_request/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/purchase_non_trading/purchase_request/edit?id=${publicId}`)
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

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal menghapus Purchase Request'),
    })

    console.error('[Purchase Request] DELETE ERROR:', err)
  } finally {
    deleteLoading.value = false
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  if (!publicId) return

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

watch(currentPage, async () => {
  await fetchPurchaseRequests()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchPurchaseRequests()
})

watch([searchQuery, selectedStatus, tanggalMulai, tanggalSelesai], async () => {
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
      path: '/purchase_non_trading/purchase_request',
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

        <!-- FILTERS -->
        <VRow>
          <VCol cols="12" sm="3">
            <VTextField
              v-model="searchQuery"
              label="Cari kode PR"
              placeholder="Cari purchase request..."
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="3">
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

          <VCol cols="12" sm="3">
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

          <VCol cols="12" sm="3">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
            />
          </VCol>
        </VRow>

        <!-- ACTION -->
        <VRow class="mt-1">
          <VCol cols="12" class="d-flex justify-end">
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
            <th scope="col">KATEGORI</th>
            <th scope="col">STATUS</th>
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
            <td class="text-medium-emphasis">{{ v.kategori || '-' }}</td>
            <td>
              <VChip
                :color="getStatusColor(v.status)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatus(v.status) }}
              </VChip>
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
      max-width="1200"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between px-6 py-4">
          <div class="d-flex align-center gap-3">
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
              class="text-capitalize"
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
              <div>
                {{ detailError }}
              </div>

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
            <VCard
              variant="tonal"
              class="mb-5"
            >
              <VCardText>
                <VRow>
                  <VCol cols="12" md="3">
                    <div class="text-caption text-medium-emphasis">Nomor PR</div>
                    <div class="font-weight-medium">
                      {{ detailPurchaseRequest.nomor_pr || '-' }}
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="text-caption text-medium-emphasis">Tanggal PR</div>
                    <div class="font-weight-medium">
                      {{ formatDate(detailPurchaseRequest.tanggal_pr) || '-' }}
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="text-caption text-medium-emphasis">Cabang</div>
                    <div class="font-weight-medium">
                      {{ detailPurchaseRequest.cabang || '-' }}
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="text-caption text-medium-emphasis">Department</div>
                    <div class="font-weight-medium">
                      {{ detailPurchaseRequest.department || '-' }}
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="text-caption text-medium-emphasis">Kategori</div>
                    <div class="font-weight-medium">
                      {{ detailPurchaseRequest.kategori || '-' }}
                    </div>
                  </VCol>

                  <VCol cols="12" md="9">
                    <div class="text-caption text-medium-emphasis">Notes</div>
                    <div class="font-weight-medium">
                      {{ detailPurchaseRequest.notes || '-' }}
                    </div>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>

            <div class="mb-5">
              <div class="text-subtitle-2 font-weight-bold mb-2">
                Lampiran Purchase Request
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
                  size="small"
                  prepend-icon="tabler-paperclip"
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
            </div>

            <div class="d-flex align-center justify-space-between mb-3">
              <div class="text-subtitle-1 font-weight-bold">
                Vendor Penawaran
              </div>

              <VChip
                size="small"
                variant="tonal"
                color="primary"
              >
                {{ detailPurchaseRequest.vendors?.length || 0 }} Vendor
              </VChip>
            </div>

            <VExpansionPanels v-model="openedVendorPanels" variant="default" multiple>
              <VExpansionPanel
                v-for="(vendor, index) in detailVendors || []"
                :key="index"
                class="mb-3"
              >
                <VExpansionPanelTitle>
                  <div class="d-flex align-center justify-space-between w-100 pe-4">
                    <div>
                      <div class="font-weight-bold">
                        {{ vendor.nama_vendor || '-' }}
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Status PKP: {{ formatStatusPKP(vendor.status_pkp) }}
                      </div>
                    </div>

                    <VChip
                      v-if="vendor.is_selected"
                      color="success"
                      size="small"
                      variant="tonal"
                    >
                      Vendor Rekomendasi
                    </VChip>
                  </div>
                </VExpansionPanelTitle>

                <VExpansionPanelText>
                  <VTable class="border rounded">
                    <thead>
                      <tr>
                        <th>No</th>
                        <th>Item</th>
                        <th class="text-center">Keterangan</th>
                        <th class="text-center">Satuan</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Harga Unit</th>
                        <th class="text-end">Total</th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="(item, itemIndex) in vendor.items || []"
                        :key="itemIndex"
                      >
                        <td>{{ Number(itemIndex) + 1 }}</td>
                        <td>{{ item.nama_item || '-' }}</td>
                        <td>{{ item.keterangan || '-' }}</td>
                        <td class="text-center">{{ item.satuan?.kode }}</td>
                        <td class="text-center">{{ item.qty || 0 }}</td>
                        <td class="text-end">{{ formatNumberWithoutRp(item.harga_unit) }}</td>
                        <td class="text-end">{{ formatNumberWithoutRp(item.subtotal) }}</td>
                      </tr>

                      <tr v-if="!vendor.items?.length">
                        <td colspan="6" class="text-center text-medium-emphasis py-5">
                          Item belum tersedia
                        </td>
                      </tr>
                    </tbody>
                  </VTable>

                  <VCard variant="tonal" class="mt-4">
                    <VCardText class="pa-0">
                      <table class="summary-table">
                        <tbody>

                          <template v-if="vendor.status_pkp === 'PKP'">

                            <tr>
                              <td class="label-col">Subtotal</td>
                              <td class="currency-col">Rp</td>
                              <td class="amount-col">
                                {{
                                  formatNumberWithoutRp(
                                    (vendor.items || []).reduce(
                                      (total: number, item: any) =>
                                        total + Number(item.subtotal || 0),
                                      0,
                                    ),
                                  )
                                }}
                              </td>
                            </tr>

                            <tr>
                              <td class="label-col">DPP</td>
                              <td class="currency-col">Rp</td>
                              <td class="amount-col">
                                {{ formatNumberWithoutRp(vendor.dpp) }}
                              </td>
                            </tr>

                            <tr>
                              <td class="label-col">PPN</td>
                              <td class="currency-col">Rp</td>
                              <td class="amount-col">
                                {{ formatNumberWithoutRp(vendor.ppn) }}
                              </td>
                            </tr>

                            <tr>
                              <td colspan="3" class="divider-row">
                                <VDivider />
                              </td>
                            </tr>

                          </template>

                          <tr class="grand-total-row">
                            <td class="label-col">Grand Total</td>
                            <td class="currency-col">Rp</td>
                            <td class="amount-col">
                              {{ formatNumberWithoutRp(vendor.price_offer) }}
                            </td>
                          </tr>

                        </tbody>
                      </table>
                    </VCardText>
                  </VCard>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
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
