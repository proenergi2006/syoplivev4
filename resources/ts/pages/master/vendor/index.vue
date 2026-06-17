<script setup lang="ts">
import { computed, onMounted, ref, watch, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from '@axios'
import {
  showLoadingAlert,
  showSuccessAlert,
  showErrorAlert,
  closeAlert,
  showSuccessToast,
  showErrorToast,
  showWarningToast,
  showInfoToast,
  showConfirmAlert
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatStatusPKP, formatKategoriVendor, toTitleCase } from '@/utils/textFormatter'
import { usePolling } from '@core/composable/usePolling'
import { usePermissionStore } from '@/stores/permission'

interface Vendor {
  id: number
  public_id: string
  kode_vendor: string
  nama_vendor: string
  inisial_vendor: string | null
  kategori_vendor: string | null
  is_active: boolean
  status_approval: string | null
  created_time?: string | null
  created_ip?: string | null
  created_by?: number | null
  lastupdate_time?: string | null
}

interface VendorListResponse {
  data?: Vendor[]
  total?: number
  last_page?: number
  current_page?: number
  per_page?: number
}

interface VendorQueryParams {
  page: number
  per_page: number
  search?: string
  is_active?: string
}

interface ApiErrorResponse {
  message?: string
  errors?: Record<string, string[]>
}

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: ApiErrorResponse
  }
}

const permissionStore = usePermissionStore()

const canView = computed(() => {
  return permissionStore.can('vendor.view')
})

const canCreate = computed(() => {
  return permissionStore.can('vendor.create')
})

const canUpdate = computed(() => {
  return permissionStore.can('vendor.update')
})

const canDelete = computed(() => {
  return permissionStore.can('vendor.delete')
})

const isCheckingPermission = ref(true)

type SnackbarColor = 'success' | 'error' | 'warning' | 'info'

const route = useRoute()
const router = useRouter()

const vendorApprovalLoading = ref(false)

const rejectVendorDialog = ref(false)
const rejectVendorTarget = ref<any>(null)
const rejectVendorNotes = ref('')
const rejectVendorLoading = ref(false)

// =========================
// Navigation
// =========================
const goToCreate = (): void => {
  router.push('/master/vendor/create')
}

const goToEdit = (public_id: string): void => {
  router.push(`/master/vendor/edit?id=${public_id}`)
}

// =========================
// Status dialog
// =========================
const statusDialog = ref<boolean>(false)
const statusLoading = ref<boolean>(false)
const statusTarget = ref<Vendor | null>(null)
const loadError = ref(false)

const openStatusDialog = (row: Vendor): void => {
  statusTarget.value = row
  statusDialog.value = true
}

const closeStatusDialog = (): void => {
  statusDialog.value = false
  statusTarget.value = null
}

const confirmUpdateStatus = async (): Promise<void> => {
  if (!statusTarget.value || statusLoading.value) return

  statusLoading.value = true

  const vendorPublicId = statusTarget.value.public_id
  const vendorName = statusTarget.value.nama_vendor
  const nextStatus = !statusTarget.value.is_active

  try {
    closeAlert()
    closeStatusDialog()

    showLoadingAlert('Memperbarui status vendor...', 'Mohon tunggu sebentar')

    await axios.patch(`/master/vendor/${vendorPublicId}/status`, {
      is_active: nextStatus,
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: `Status vendor "${vendorName}" berhasil diubah menjadi ${nextStatus ? 'AKTIF' : 'NON AKTIF'}`,
    })

    await fetchRows()
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memperbarui status vendor'),
    })
  } finally {
    statusLoading.value = false
  }
}

const approveVendor = async (vendor: any): Promise<void> => {
  if (!vendor?.public_id || vendorApprovalLoading.value) return

  const confirm = await showConfirmAlert({
    title: 'Approve Vendor?',
    text: `Vendor "${vendor.nama_vendor}" akan disetujui.`,
    confirmButtonText: 'Ya, approve',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  vendorApprovalLoading.value = true

  try {
    showLoadingAlert('Approve Vendor...', 'Mohon tunggu sebentar')

    const response = await axios.patch(`/master/vendor/${vendor.public_id}/approve`, {}, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || `Vendor "${vendor.nama_vendor}" berhasil diapprove.`,
    })

    await fetchRows()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal approve Vendor'),
    })
  } finally {
    vendorApprovalLoading.value = false
  }
}

const openRejectVendor = (vendor: any): void => {
  rejectVendorTarget.value = vendor
  rejectVendorNotes.value = ''
  rejectVendorDialog.value = true
}

const rejectVendor = async (): Promise<void> => {
  if (!rejectVendorTarget.value || rejectVendorLoading.value) return

  const target = { ...rejectVendorTarget.value }
  const notes = rejectVendorNotes.value || null

  rejectVendorDialog.value = false

  await nextTick()

  const confirm = await showConfirmAlert({
    title: 'Reject Vendor?',
    text: `Vendor "${target.nama_vendor}" akan ditolak.`,
    confirmButtonText: 'Ya, reject',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    rejectVendorDialog.value = true
    return
  }

  rejectVendorLoading.value = true

  try {
    showLoadingAlert('Reject Vendor...', 'Mohon tunggu sebentar')

    const response = await axios.patch(`/master/vendor/${target.public_id}/reject`, {
      notes,
    }, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    rejectVendorNotes.value = ''
    rejectVendorTarget.value = null

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || `Vendor "${target.nama_vendor}" berhasil direject.`,
    })

    await fetchRows()
  } catch (error: unknown) {
    closeAlert()

    rejectVendorDialog.value = true

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal reject Vendor'),
    })
  } finally {
    rejectVendorLoading.value = false
  }
}

// =========================
// Main state
// =========================
const loading = ref<boolean>(false)
const rows = ref<Vendor[]>([])
const isVendorDraft = (vendor: any): boolean => {
  return String(vendor?.status_approval || '').toUpperCase() === 'DRAFT'
}

// =========================
// Filters
// =========================
const searchQuery = ref<string | null>('')
const selectedStatus = ref<string>('all')

const statusItems: Array<{ title: string; value: string | null }> = [
  { title: 'Semua', value: 'all' },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

// =========================
// Pagination
// =========================
const rowPerPage = ref<number>(10)
const currentPage = ref<number>(1)
const totalPage = ref<number>(1)
const totalRows = ref<number>(0)

const paginationData = computed<string>(() => {
  if (totalRows.value === 0) {
    return '0-0 of 0'
  }

  const firstIndex = (currentPage.value - 1) * rowPerPage.value + 1
  const lastIndex = Math.min(
    currentPage.value * rowPerPage.value,
    totalRows.value,
  )

  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// =========================
// Detail Vendor
// =========================

interface MasterDokumenItem {
  id: number
  nama_dokumen: string
  deskripsi?: string | null
}

interface MasterTransaksiItem { 
  id: number 
  kategori: string | null 
  pasal_pajak: string | null 
}

const detailDialog = ref(false)
const detailLoading = ref(false)
const detailError = ref('')

const detailVendor = ref<any | null>(null)
const detailVendorPublicId = ref('')

const loadingTransaksi = ref(false)
const transaksiError = ref('')

const masterDokumen = ref<MasterDokumenItem[]>([])
const masterTransaksi = ref<MasterTransaksiItem[]>([])

const isVendorPKP = computed(() => {
  return String(detailVendor.value?.status_pkp ?? '').toLowerCase() === 'pkp'
})

const loadMasterDokumen = async (): Promise<void> => {
  try {
    const response = await axios.get('/master/dokumen-pendukung')
    masterDokumen.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    console.error('[Vendor] LOAD MASTER DOKUMEN ERROR:', error)
  }
}

const loadTransaksi = async (): Promise<void> => {
  loadingTransaksi.value = true
  transaksiError.value = ''

  try {
    const response = await axios.get('/master/keterangan-transaksi')
    masterTransaksi.value = Array.isArray(response.data?.data)
      ? response.data.data
      : []
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    transaksiError.value = getApiErrorMessage(err, 'Gagal memuat data transaksi')
    console.error('[Vendor] LOAD TRANSAKSI ERROR:', err)
  } finally {
    loadingTransaksi.value = false
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  if (!publicId) return

  detailDialog.value = true
  detailLoading.value = true
  detailError.value = ''
  detailVendor.value = null
  detailVendorPublicId.value = publicId

  try {
    const response = await axios.get(`/master/vendor/${publicId}`)
    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data vendor tidak ditemukan')
    }

    detailVendor.value = detail
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    detailError.value = getApiErrorMessage(err, 'Gagal memuat detail vendor')

    showErrorToast({
      title: 'Error',
      text: detailError.value,
    })
  } finally {
    detailLoading.value = false
  }
}

const closeDetail = (): void => {
  detailDialog.value = false
  detailLoading.value = false
  detailError.value = ''
  detailVendor.value = null
  detailVendorPublicId.value = ''
}

const openDokumenFile = (url?: string | null): void => {
  if (!url) return
  window.open(url, '_blank', 'noopener,noreferrer')
}

const getJenisPerusahaanLabel = (value: string | number | null | undefined): string => {
  const map: Record<string, string> = {
    '1': 'Orang Pribadi / Perorangan',
    '2': 'Firma / CV / PD',
    '3': 'PT / Perseroan',
  }

  return map[String(value ?? '')] || '-'
}

const getDokumenName = (dokumenId: number): string => {
  const item = masterDokumen.value.find(doc => doc.id === dokumenId)
  if (!item) return `Dokumen ID: ${dokumenId}`

  return [item.nama_dokumen, item.deskripsi].filter(Boolean).join(' ')
}

const groupedDokumenFiles = computed(() => {
  const files = detailVendor.value?.dokumen_files ?? []

  const grouped: Record<number, typeof files> = {}

  files.forEach((file: any) => {
    if (!grouped[file.dokumen_id]) {
      grouped[file.dokumen_id] = []
    }

    grouped[file.dokumen_id].push(file)
  })

  return Object.entries(grouped).map(([dokumenId, items]) => ({
    dokumen_id: Number(dokumenId),
    dokumen_name: getDokumenName(Number(dokumenId)),
    files: items,
  }))
})

const selectedTransaksiList = computed(() => {
  const ids = detailVendor.value?.transaksi_ids ?? []

  return ids
    .map((id: number) => {
      const item = masterTransaksi.value.find(trx => trx.id === id)

      if (!item) {
        return {
          id,
          label: `Transaksi ID ${id}`,
        }
      }

      const parts = [item.kategori, item.pasal_pajak].filter(Boolean)

      return {
        id,
        label: parts.length ? parts.join(' - ') : `Transaksi ID ${id}`,
      }
    })
})

const submitVendorLoading = ref(false)

const submitVendor = async (vendor: any): Promise<void> => {
  if (!vendor?.public_id || submitVendorLoading.value) return

  const confirm = await showConfirmAlert({
    title: 'Submit Vendor?',
    text: `Vendor "${vendor.nama_vendor}" akan masuk proses review Finance.`,
    confirmButtonText: 'Ya, submit',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  submitVendorLoading.value = true

  try {
    showLoadingAlert('Submit Vendor...', 'Mohon tunggu sebentar')

    const response = await axios.patch(`/master/vendor/${vendor.public_id}/submit`, {}, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || `Vendor "${vendor.nama_vendor}" berhasil disubmit.`,
    })

    await fetchRows()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal submit Vendor'),
    })
  } finally {
    submitVendorLoading.value = false
  }
}

// =========================
// Snackbar
// =========================
const snackbar = ref<boolean>(false)
const snackText = ref<string>('')
const snackColor = ref<SnackbarColor>('success')
const snackTimeout = ref<number>(3000)

const notify = (
  text: string,
  color: SnackbarColor = 'success',
  timeout = 3000,
): void => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// =========================
// Delete Vendor
// =========================
const deleteLoading = ref<boolean>(false)

const openDelete = async (row: Vendor): Promise<void> => {
  if (deleteLoading.value) return

  const vendorPublicId = row.public_id
  const vendorName = row.nama_vendor || '-'

  if (!vendorPublicId) {
    showErrorToast({
      title: 'Data Tidak Valid',
      text: 'Public ID vendor tidak ditemukan.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus Vendor?',
    html: `Apakah Anda yakin ingin menghapus vendor <strong>${vendorName}</strong>?`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  deleteLoading.value = true

  try {
    showLoadingAlert(
      'Menghapus vendor...',
      'Mohon tunggu sebentar',
    )

    const response = await axios.delete(
      `/master/vendor/${encodeURIComponent(vendorPublicId)}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    if (response.data?.success) {
      showSuccessToast({
        title: 'Berhasil',
        text: `Vendor "${vendorName}" berhasil dihapus`,
      })

      await fetchRows()

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: response.data?.message || 'Gagal menghapus vendor',
    })
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal menghapus vendor',
      ),
    })

    console.error('[Vendor] DELETE ERROR:', err)
  } finally {
    deleteLoading.value = false
  }
}

// =========================
// Helpers
// =========================
const buildParams = (): VendorQueryParams => {
  const params: VendorQueryParams = {
    page: currentPage.value,
    per_page: rowPerPage.value,
  }

  const keyword = (searchQuery.value ?? '').trim()

  if (keyword !== '') {
    params.search = keyword
  }

  if (selectedStatus.value !== 'all') {
    params.is_active = selectedStatus.value
  }

  return params
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = 'all'
  currentPage.value = 1
  await fetchRows()
}

// =========================
// Fetch data
// =========================
const fetchRows = async (): Promise<void> => {
  if (loading.value) return

  loading.value = true
  loadError.value = false

  try {
    const params = buildParams()

    const response = await axios.get<VendorListResponse>('/master/vendor', {
      params,
    })

    const data = response.data

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value) {
      currentPage.value = totalPage.value
    }
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    loadError.value = true

    rows.value = []
    totalRows.value = 0
    totalPage.value = 1

    console.error('[Purchase Request] FETCH ERROR:', err)

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat data purchase request'),
    })
  } finally {
    loading.value = false
  }
}

// UsePolling
// usePolling(fetchRows, {
//   interval: 30000,
// })

const handleMasterVendorRefresh = async (): Promise<void> => {
  await fetchRows()
}

// =========================
// Watchers
// =========================
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
  }

  searchDebounceTimer = setTimeout(() => {
    if (currentPage.value !== 1) {
      currentPage.value = 1
      return
    }

    fetchRows()
  }, 400)
})

watch(selectedStatus, async () => {
  currentPage.value = 1
  await fetchRows()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchRows()
})

watch(currentPage, async (newPage, oldPage) => {
  if (newPage !== oldPage) {
    await fetchRows()
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('master-vendor:refresh', handleMasterVendorRefresh)
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
  }
})

// =========================
// Initial load
// =========================
onMounted(async () => {

  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false

  await Promise.all([
    fetchRows(),
    loadMasterDokumen(),
    loadTransaksi(),
  ])

  window.addEventListener('master-vendor:refresh', handleMasterVendorRefresh)

  const success = route.query.success

  if (success) {
    await router.replace({
      path: '/master/vendor',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Data Vendor berhasil disimpan.',
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Data Vendor berhasil diperbarui.',
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
          <VCol cols="12" sm="5">
            <VTextField
              v-model="searchQuery"
              label="Cari (kode/nama/inisial)"
              placeholder="Cari vendor..."
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
            />
          </VCol>

          <VCol cols="12" sm="3" class="d-flex align-end">
            <VBtn
              color="secondary"
              prepend-icon="tabler-refresh"
              block
              @click="resetFilters"
              class="text-none"
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
        <VBtn color="primary" @click="goToCreate" class="text-none" v-if="canCreate">
          + Tambah Vendor
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
            @click="fetchRows"
          >
            Reload Data
          </VBtn>
        </div>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">No</th>
            <th scope="col">Kode</th>
            <th scope="col">Nama Vendor</th>
            <th scope="col">Inisial</th>
            <th scope="col">Status</th>
            <th scope="col">Status Pengajuan</th>
            <th scope="col" class="text-center" style="width: 5rem;">Actions</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id">
            <td class="text-medium-emphasis">{{ index + 1 }}</td>
            <td class="text-medium-emphasis">{{ v.kode_vendor }}</td>
            <td class="text-medium-emphasis">{{ v.nama_vendor }}</td>
            <td class="text-medium-emphasis">{{ v.inisial_vendor ?? '-' }}</td>
            <td>
              <VChip :color="v.is_active ? 'success' : 'secondary'" size="small">
                {{ v.is_active ? 'Aktif' : 'Tidak Aktif' }}
              </VChip>
            </td>
            <td>
              <VChip
                :color="
                  v.status_approval === 'DRAFT'
                    ? 'secondary'
                    : v.status_approval === 'APPROVED'
                    ? 'success'
                    : v.status_approval === 'REJECTED'
                    ? 'error'
                    : v.status_approval === 'PENDING REVIEW'
                    ? 'warning'
                    : 'secondary'
                "
                size="small"
              >
                {{toTitleCase(v.status_approval)}}
              </VChip>
            </td>
            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <!-- Selalu muncul -->
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

                      <VListItemTitle>Lihat Detail</VListItemTitle>
                    </VListItem>

                    <!-- Hanya muncul jika DRAFT -->
                    <template v-if="isVendorDraft(v)">
                      <VListItem
                        v-if="canUpdate"
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

                      <VListItem @click="submitVendor(v)">
                        <template #prepend>
                          <VIcon
                            :size="20"
                            class="me-3"
                            icon="mdi-send-outline"
                          />
                        </template>

                        <VListItemTitle>Submit</VListItemTitle>
                      </VListItem>

                      <VListItem
                        v-if="canDelete"
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

                      <VListItem
                        href="javascript:void(0)"
                        @click="openStatusDialog(v)"
                      >
                        <template #prepend>
                          <VIcon
                            :icon="v.is_active ? 'mdi-toggle-switch-off-outline' : 'mdi-toggle-switch-outline'"
                            :size="20"
                            class="me-3"
                          />
                        </template>

                        <VListItemTitle>
                          {{ v.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                        </VListItemTitle>
                      </VListItem>
                    </template>

                    <!-- ========================= -->
                    <!-- ACTION PENDING REVIEW -->
                    <!-- ========================= -->
                    <template v-if="String(v.status_approval).toUpperCase() === 'PENDING REVIEW'">
                      <VListItem @click="approveVendor(v)">
                        <template #prepend>
                          <VIcon
                            icon="mdi-check-circle-outline"
                            :size="20"
                            class="me-3"
                            color="success"
                          />
                        </template>

                        <VListItemTitle>
                          Approve
                        </VListItemTitle>
                      </VListItem>

                      <VListItem @click="openRejectVendor(v)">
                        <template #prepend>
                          <VIcon
                            icon="mdi-close-circle-outline"
                            :size="20"
                            class="me-3"
                            color="error"
                          />
                        </template>

                        <VListItemTitle>
                          Reject
                        </VListItemTitle>
                      </VListItem>
                    </template>
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

    <!-- Confirm Update Status -->
    <VDialog v-model="statusDialog" max-width="420">
      <VCard>
        <VCardTitle class="text-h6">
          Update Status Vendor
        </VCardTitle>

        <VCardText v-if="statusTarget">
          Apakah Anda yakin ingin mengubah status vendor
          <strong>{{ statusTarget.nama_vendor }}</strong>
          menjadi
          <strong>{{ statusTarget.is_active ? 'nonaktif' : 'aktif' }}</strong>?
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="text"
            color="secondary"
            :disabled="statusLoading"
            @click="closeStatusDialog"
          >
            Batal
          </VBtn>

          <VBtn
            color="warning"
            :loading="statusLoading"
            @click="confirmUpdateStatus"
          >
            Ya, Ubah
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Detail vendor -->
     <VDialog
        v-model="detailDialog"
        max-width="1100"
        scrollable
      >
        <VCard rounded="xl">
          <!-- Header -->
          <VCardItem class="px-6 py-5">
            <template #prepend>
              <VAvatar
                size="42"
                color="primary"
                variant="tonal"
              >
                <VIcon icon="tabler-building-store" />
              </VAvatar>
            </template>

            <VCardTitle class="text-h5 font-weight-bold">
              Detail Vendor
            </VCardTitle>

            <VCardSubtitle>
              Informasi lengkap vendor
            </VCardSubtitle>

            <template #append>
              <VBtn
                icon
                variant="text"
                @click="closeDetail"
              >
                <VIcon icon="tabler-x" />
              </VBtn>
            </template>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-6">
            <!-- loading -->
            <div v-if="detailLoading" class="py-10">
              <div class="d-flex flex-column align-center justify-center ga-4">
                <VProgressCircular indeterminate color="primary" size="40" />
                <div class="text-medium-emphasis">Memuat detail vendor...</div>
              </div>
            </div>

            <!-- error -->
            <VAlert
              v-else-if="detailError"
              type="error"
              variant="tonal"
              border="start"
            >
              {{ detailError }}
            </VAlert>

            <!-- content -->
            <div v-else-if="detailVendor" class="vendor-detail-content">
              <!-- Ringkasan -->
              <div class="mb-8">
                <div class="d-flex flex-wrap justify-space-between align-center ga-4">
                  <div>
                    <div class="text-h5 font-weight-bold">
                      {{ detailVendor.nama_vendor || '-' }}
                    </div>
                    <div class="text-body-2 text-medium-emphasis mt-1">
                      Inisial Vendor: {{ detailVendor.inisial_vendor || '-' }}
                    </div>
                  </div>

                  <div class="d-flex flex-wrap ga-2">
                    <VChip
                      :color="detailVendor.is_active ? 'success' : 'error'"
                      variant="tonal"
                    >
                      {{ detailVendor.is_active ? 'Aktif' : 'Nonaktif' }}
                    </VChip>
                  </div>
                </div>
              </div>

              <!-- Data Utama Vendor -->
              <section class="detail-section">
                <div class="detail-section-title">Data Utama Vendor</div>

                <VRow>
                  <VCol cols="12" md="6">
                    <div class="detail-item">
                      <div class="detail-label">Nama Vendor</div>
                      <div class="detail-value">{{ detailVendor.nama_vendor || '-' }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Inisial Vendor</div>
                      <div class="detail-value">{{ detailVendor.inisial_vendor || '-' }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Jenis Perusahaan</div>
                      <div class="detail-value">{{ getJenisPerusahaanLabel(detailVendor.jenis_perusahaan) }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Kategori Vendor</div>
                      <div class="detail-value">{{ formatKategoriVendor(detailVendor.kategori_vendor) || '-' }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Department Vendor</div>
                      <div class="detail-value">{{ detailVendor.department.label || '-' }}</div>
                    </div>
                  </VCol>

                  <VCol cols="12" md="6">
                    <div class="detail-item">
                      <div class="detail-label">Nomor KTP</div>
                      <div class="detail-value">{{ detailVendor.nomor_ktp || '-' }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Telepon</div>
                      <div class="detail-value">{{ detailVendor.telepon || '-' }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Fax</div>
                      <div class="detail-value">{{ detailVendor.fax || '-' }}</div>
                    </div>

                    <div class="detail-item">
                      <div class="detail-label">Email</div>
                      <div class="detail-value">{{ detailVendor.email || '-' }}</div>
                    </div>
                  </VCol>

                  <VCol cols="12">
                    <div class="detail-item">
                      <div class="detail-label">Alamat</div>
                      <div class="detail-value">{{ detailVendor.alamat || '-' }}</div>
                    </div>
                  </VCol>
                </VRow>
              </section>

              <!-- Data PIC -->
              <section class="detail-section">
                <div class="detail-section-title">Data PIC</div>

                <VRow>
                  <VCol cols="12" md="3">
                    <div class="detail-item">
                      <div class="detail-label">Nama PIC</div>
                      <div class="detail-value">{{ detailVendor.contact_nama || '-' }}</div>
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="detail-item">
                      <div class="detail-label">Jabatan PIC</div>
                      <div class="detail-value">{{ detailVendor.contact_jabatan || '-' }}</div>
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="detail-item">
                      <div class="detail-label">HP PIC</div>
                      <div class="detail-value">{{ detailVendor.contact_hp || '-' }}</div>
                    </div>
                  </VCol>

                  <VCol cols="12" md="3">
                    <div class="detail-item">
                      <div class="detail-label">Email PIC</div>
                      <div class="detail-value">{{ detailVendor.contact_email || '-' }}</div>
                    </div>
                  </VCol>
                </VRow>
              </section>

              <!-- Data Pajak / PKP -->
              <section class="detail-section">
                <div class="detail-section-title">Data Pajak / PKP</div>

                <!-- jika NON PKP -->
                <VRow v-if="!isVendorPKP">
                  <VCol cols="12" md="4">
                    <div class="detail-item">
                      <div class="detail-label">Status PKP</div>
                      <div class="detail-value">{{ formatStatusPKP(detailVendor.status_pkp) }}</div>
                    </div>
                  </VCol>
                </VRow>

                <!-- jika PKP -->
                <div v-else>
                  <VRow class="mb-4">
                    <VCol cols="12">
                      <div class="detail-item mb-0">
                        <div class="detail-label">Status PKP</div>
                        <div class="detail-value">{{ formatStatusPKP(detailVendor.status_pkp) }}</div>
                      </div>
                    </VCol>
                  </VRow>

                  <VRow class="pkp-split-row">
                    <!-- kiri : NPWP -->
                    <VCol cols="12" md="6" class="pkp-col pkp-col-left">
                      <div class="text-subtitle-2 font-weight-bold mb-4">Data NPWP</div>

                      <div class="detail-item">
                        <div class="detail-label">NPWP</div>
                        <div class="detail-value">{{ detailVendor.npwp || '-' }}</div>
                      </div>

                      <div class="detail-item mb-0">
                        <div class="detail-label">Alamat NPWP</div>
                        <div class="detail-value">{{ detailVendor.npwp_alamat || '-' }}</div>
                      </div>
                    </VCol>

                    <!-- kanan : SPPKP -->
                    <VCol cols="12" md="6" class="pkp-col pkp-col-right">
                      <div class="text-subtitle-2 font-weight-bold mb-4">Data SPPKP</div>

                      <div class="detail-item">
                        <div class="detail-label">No. SPPKP</div>
                        <div class="detail-value">{{ detailVendor.sppkp_nomor || '-' }}</div>
                      </div>

                      <div class="detail-item">
                        <div class="detail-label">Tanggal SPPKP</div>
                        <div class="detail-value">{{ detailVendor.sppkp_tanggal || '-' }}</div>
                      </div>

                      <div class="detail-item">
                        <div class="detail-label">Alamat sama seperti NPWP</div>
                        <div class="detail-value">{{ detailVendor.same_as_npwp ? 'Ya' : 'Tidak' }}</div>
                      </div>

                      <div class="detail-item mb-0">
                        <div class="detail-label">Alamat SPPKP</div>
                        <div class="detail-value">{{ detailVendor.sppkp_alamat || '-' }}</div>
                      </div>
                    </VCol>
                  </VRow>
                </div>
              </section>

              <!-- Data Pembayaran -->
              <section class="detail-section">
                <div class="detail-section-title">Data Pembayaran</div>

                <VRow>
                  <VCol cols="12" md="6">
                    <div class="detail-item">
                      <div class="detail-label">Jenis Pembayaran</div>
                      <div class="detail-value">{{ detailVendor.jenis_pembayaran || '-' }}</div>
                    </div>
                  </VCol>

                  <VCol cols="12" md="6">
                    <div class="detail-item">
                      <div class="detail-label">TOP</div>
                      <div class="detail-value">{{ detailVendor.top || '-' }}</div>
                    </div>
                  </VCol>
                </VRow>
              </section>

              <!-- Data Transaksi -->
               <section class="detail-section">
                <div class="detail-section-title">Data Transaksi</div>

                <VList
                  v-if="selectedTransaksiList.length"
                  density="compact"
                  class="pa-0"
                >
                  <VListItem
                    v-for="(trx, index) in selectedTransaksiList"
                    :key="trx.id"
                    class="px-0"
                  >
                    <template #prepend>
                      <div class="me-3 text-medium-emphasis font-weight-medium">
                        {{ Number(index) + 1 }}.
                      </div>
                    </template>

                    <VListItemTitle class="text-body-1">
                      {{ trx.label }}
                    </VListItemTitle>
                  </VListItem>
                </VList>

                <div v-else class="text-medium-emphasis">
                  Tidak ada data transaksi
                </div>
              </section>

              <!-- Data Dokumen Pendukung -->
              <section class="detail-section">
                <div class="detail-section-title">Data Dokumen Pendukung</div>

                <div
                  v-if="groupedDokumenFiles.length"
                  class="d-flex flex-column ga-5"
                >
                  <div
                    v-for="group in groupedDokumenFiles"
                    :key="group.dokumen_id"
                  >
                    <div class="text-subtitle-1 font-weight-medium mb-3">
                      {{ group.dokumen_name }}
                    </div>

                    <VList density="compact" border rounded>
                      <VListItem
                        v-for="file in group.files"
                        :key="file.id"
                      >
                        <template #prepend>
                          <VIcon icon="mdi-file-document-outline" />
                        </template>

                        <VListItemTitle>
                          {{ file.file_name }}
                        </VListItemTitle>

                        <template #append>
                          <VBtn
                            size="small"
                            color="primary"
                            variant="text"
                            @click="openDokumenFile(file.file_url || file.file_path)"
                          >
                            Lihat File
                          </VBtn>
                        </template>
                      </VListItem>
                    </VList>
                  </div>
                </div>

                <div v-else class="text-medium-emphasis">
                  Tidak ada dokumen pendukung
                </div>
              </section>

              <!-- Data Rekening Bank -->
              <section class="detail-section">
                <div class="detail-section-title">Data Rekening Bank</div>

                <VTable v-if="detailVendor.banks?.length" class="text-no-wrap">
                  <thead>
                    <tr>
                      <th>No</th>
                      <th>Nama Bank</th>
                      <th>Atas Nama</th>
                      <th>No. Rekening</th>
                      <th>Cabang</th>
                      <th>Alamat Bank</th>
                      <th>Swift Code</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(bank, index) in detailVendor.banks"
                      :key="bank.id || `${bank.bank_id}-${bank.nomor_rekening}`"
                    >
                      <td>{{ Number(index) + 1 }}</td>
                      <td>{{ bank.nama_bank || '-' }}</td>
                      <td>{{ bank.atas_nama || '-' }}</td>
                      <td>{{ bank.nomor_rekening || '-' }}</td>
                      <td>{{ bank.cabang || '-' }}</td>
                      <td>{{ bank.alamat_bank || '-' }}</td>
                      <td>{{ bank.swift_code || '-' }}</td>
                    </tr>
                  </tbody>
                </VTable>

                <div v-else class="text-medium-emphasis">
                  Tidak ada data rekening bank
                </div>
              </section>
            </div>
          </VCardText>

          <VDivider />

          <VCardActions class="justify-end px-6 py-4">
            <VBtn
              color="secondary"
              variant="tonal"
              @click="closeDetail"
              class="text-none"
            >
              Tutup
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VDialog
        v-model="rejectVendorDialog"
        max-width="560"
        persistent
      >
        <VCard>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon
              icon="mdi-close-circle-outline"
              color="error"
            />
            Reject Vendor
          </VCardTitle>

          <VDivider />

          <VCardText>
            <p class="text-body-2 mb-4">
              Anda akan menolak Vendor:
              <strong>{{ rejectVendorTarget?.nama_vendor || '-' }}</strong>
            </p>

            <VTextarea
              v-model="rejectVendorNotes"
              label="Catatan reject"
              placeholder="Masukkan alasan reject jika diperlukan"
              rows="4"
              auto-grow
              clearable
              :disabled="rejectVendorLoading"
            />

            <div class="text-caption text-medium-emphasis mt-2">
              Catatan bersifat optional, namun disarankan diisi agar pembuat vendor mengetahui alasan penolakan.
            </div>
          </VCardText>

          <VDivider />

          <VCardActions>
            <VSpacer />

            <VBtn
              variant="tonal"
              color="secondary"
              :disabled="rejectVendorLoading"
              @click="rejectVendorDialog = false"
            >
              Batal
            </VBtn>

            <VBtn
              color="error"
              :loading="rejectVendorLoading"
              @click="rejectVendor"
            >
              Reject
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar"
      :timeout="snackTimeout"
      :color="snackColor"
      location="top end"
    >
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Tutup</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style lang="scss" scoped>
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
