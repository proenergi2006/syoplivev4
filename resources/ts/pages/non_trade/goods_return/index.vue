<script setup lang="ts">
import axios from '@axios'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'

import {
  formatDate,
  formatDecimalQty,
  toTitleCase,
} from '@/utils/textFormatter'

import { usePermissionStore } from '@/stores/permission'

interface AxiosErrorShape {
  response?: {
    status?: number

    data?: {
      success?: boolean
      message?: string
      debug?: string | null
      errors?: Record<string, string[]>
    }
  }
}

interface GoodsReturnRow {
  id: number
  public_id: string

  nomor_return: string | null
  tanggal_return: string | null
  status: string
  notes?: string | null

  goods_receive_id?: number | null
  nomor_gr?: string | null

  purchase_order_id?: number | null
  nomor_po?: string | null

  vendor_id?: number | null
  vendor?: string | null

  cabang_id?: number | null
  cabang?: string | null
  inisial_cabang?: string | null

  department_id?: number | null
  department?: string | null
  department_name?: string | null

  total_qty?: number

  active_replacement_gr_count?: number
  has_replacement_gr?: boolean

  created_by_id?: number | null
  created_by?: string | null
  created_at?: string | null

  posted_by_id?: number | null
  posted_by?: string | null
  posted_at?: string | null

  cancelled_by_id?: number | null
  cancelled_by?: string | null
  cancelled_at?: string | null
  cancel_notes?: string | null

  can_update?: boolean
  can_delete?: boolean
  can_post?: boolean
  can_cancel?: boolean
  is_owner?: boolean
}

interface GoodsReturnDetailItem {
  id?: number | string
  public_id?: string

  nama_item?: string | null
  unit_name?: string | null
  unit?: string | null

  qty_received?: number
  qty_returned_before?: number
  qty_return?: number
  qty_returned_after?: number
  qty_returnable_after?: number

  reason_id?: number | null
  reason_code?: string | null
  reason_name?: string | null
  reason_description?: string | null
  reason_notes?: string | null
}

interface GoodsReturnAttachment {
  id?: number | string
  public_id?: string

  file_name?: string | null
  file_original_name?: string | null
  file_path?: string | null
  file_url?: string | null
  file_mime_type?: string | null
  file_size?: number | string | null
  created_at?: string | null
}

interface ReplacementGoodsReceive {
  id?: number | string
  public_id?: string
  nomor_gr?: string | null
  tanggal_gr?: string | null
  status?: string | null
  created_by_name?: string | null
}

interface GoodsReturnDetail {
  id?: number
  public_id?: string

  nomor_return?: string | null
  tanggal_return?: string | null
  status?: string | null
  notes?: string | null

  nomor_gr?: string | null
  tanggal_gr?: string | null

  nomor_po?: string | null
  po_status_receive?: string | null

  vendor?: string | null

  cabang?: string | null
  department?: string | null
  department_name?: string | null

  total_qty?: number

  created_by_name?: string | null
  created_at?: string | null

  posted_by_name?: string | null
  posted_at?: string | null

  cancelled_by_name?: string | null
  cancelled_at?: string | null
  cancel_notes?: string | null

  items?: GoodsReturnDetailItem[]
  attachments?: GoodsReturnAttachment[]

  replacement_goods_receives?: ReplacementGoodsReceive[]
}

const permissionStore = usePermissionStore()

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/
const canView = computed(() => {
  return permissionStore.can('goods_return.view')
})

const canCreate = computed(() => {
  return permissionStore.can('goods_return.create')
})

const canUpdate = computed(() => {
  return permissionStore.can('goods_return.update')
})

const canDelete = computed(() => {
  return permissionStore.can('goods_return.delete')
})

const canPost = computed(() => {
  return permissionStore.can('goods_return.post')
})

const canCancel = computed(() => {
  return permissionStore.can('goods_return.cancel')
})

/*
|--------------------------------------------------------------------------
| Route
|--------------------------------------------------------------------------
*/
const route = useRoute()
const router = useRouter()

/*
|--------------------------------------------------------------------------
| Index state
|--------------------------------------------------------------------------
*/
const loading = ref(false)
const loadError = ref(false)

const rows = ref<GoodsReturnRow[]>([])

const totalData = ref(0)
const currentPage = ref(1)
const rowPerPage = ref(10)

const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)
const tanggalMulai = ref<string | null>(null)
const tanggalSelesai = ref<string | null>(null)

const statusItems = [
  {
    title: 'Semua',
    value: '',
  },
  {
    title: 'Draft',
    value: 'DRAFT',
  },
  {
    title: 'Posted',
    value: 'POSTED',
  },
  {
    title: 'Cancelled',
    value: 'CANCELLED',
  },
]

/*
|--------------------------------------------------------------------------
| Detail dialog
|--------------------------------------------------------------------------
*/
const detailDialog = ref(false)
const selectedReturn = ref<GoodsReturnDetail | null>(null)

const detailItemPage = ref(1)
const detailItemPerPage = ref<number | 'ALL'>(5)

const detailItemPerPageItems = [
  {
    title: '5',
    value: 5,
  },
  {
    title: '10',
    value: 10,
  },
  {
    title: '20',
    value: 20,
  },
  {
    title: '50',
    value: 50,
  },
  {
    title: 'All',
    value: 'ALL',
  },
]

/*
|--------------------------------------------------------------------------
| Cancel dialog
|--------------------------------------------------------------------------
*/
const cancelDialog = ref(false)
const cancelTarget = ref<GoodsReturnRow | null>(null)
const cancelNotes = ref('')
const cancelSubmitting = ref(false)

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/
const totalPage = computed(() => {
  return Math.ceil(
    totalData.value / rowPerPage.value,
  ) || 1
})

const paginationData = computed(() => {
  const firstIndex = rows.value.length
    ? (
        (currentPage.value - 1)
        * rowPerPage.value
      ) + 1
    : 0

  const lastIndex = Math.min(
    currentPage.value * rowPerPage.value,
    totalData.value,
  )

  return `${firstIndex}-${lastIndex} of ${totalData.value}`
})

/*
|--------------------------------------------------------------------------
| Detail item pagination
|--------------------------------------------------------------------------
*/
const detailItems = computed<GoodsReturnDetailItem[]>(() => {
  return selectedReturn.value?.items ?? []
})

const detailItemTotalPage = computed(() => {
  if (detailItemPerPage.value === 'ALL')
    return 1

  return Math.ceil(
    detailItems.value.length
    / Number(detailItemPerPage.value),
  ) || 1
})

const paginatedDetailItems = computed(() => {
  if (detailItemPerPage.value === 'ALL')
    return detailItems.value

  const start = (
    Number(detailItemPage.value) - 1
  ) * Number(detailItemPerPage.value)

  const end = start
    + Number(detailItemPerPage.value)

  return detailItems.value.slice(
    start,
    end,
  )
})

const totalQtyReturnDetail = computed(() => {
  return detailItems.value.reduce(
    (
      total: number,
      item: GoodsReturnDetailItem,
    ) => {
      return total + Number(
        item.qty_return || 0,
      )
    },
    0,
  )
})

/*
|--------------------------------------------------------------------------
| Status helpers
|--------------------------------------------------------------------------
*/
const getStatusColor = (
  status?: string | null,
): string => {
  switch (
    String(status || '')
      .trim()
      .toUpperCase()
  ) {
    case 'POSTED':
      return 'success'

    case 'DRAFT':
      return 'warning'

    case 'CANCELLED':
      return 'error'

    default:
      return 'secondary'
  }
}

const formatFileSize = (
  size: number | string | null | undefined,
): string => {
  const bytes = Number(size || 0)

  if (!bytes)
    return '-'

  const kb = bytes / 1024

  if (kb < 1024)
    return `${kb.toFixed(2)} KB`

  return `${(kb / 1024).toFixed(2)} MB`
}

const safeFormatDate = (
  value?: string | null,
): string => {
  return formatDate(value ?? null)
}

const safeTitleCase = (
  value?: string | null,
): string => {
  return toTitleCase(value ?? '')
}

/*
|--------------------------------------------------------------------------
| Date filter validation
|--------------------------------------------------------------------------
*/
const datePickerKey = ref(0)
const isResettingDateFilter = ref(false)

const resetDatePickerValue = async (
  field: 'mulai' | 'selesai',
): Promise<void> => {
  isResettingDateFilter.value = true

  if (field === 'mulai')
    tanggalMulai.value = null
  else
    tanggalSelesai.value = null

  /*
  |--------------------------------------------------------------------------
  | Force rerender AppDateTimePicker
  |--------------------------------------------------------------------------
  */
  datePickerKey.value += 1

  await nextTick()

  setTimeout(() => {
    isResettingDateFilter.value = false
  }, 150)
}

const validateTanggalFilter = async (
  changedField: 'mulai' | 'selesai',
): Promise<void> => {
  if (isResettingDateFilter.value)
    return

  if (
    !tanggalMulai.value
    || !tanggalSelesai.value
  ) {
    return
  }

  const startDate = new Date(
    tanggalMulai.value,
  )

  const endDate = new Date(
    tanggalSelesai.value,
  )

  if (
    Number.isNaN(startDate.getTime())
    || Number.isNaN(endDate.getTime())
  ) {
    return
  }

  if (
    changedField === 'mulai'
    && startDate > endDate
  ) {
    await resetDatePickerValue('mulai')

    showErrorToast({
      title: 'Tanggal Tidak Valid',
      text: 'Tanggal awal tidak boleh lebih besar dari tanggal akhir.',
    })

    return
  }

  if (
    changedField === 'selesai'
    && endDate < startDate
  ) {
    await resetDatePickerValue('selesai')

    showErrorToast({
      title: 'Tanggal Tidak Valid',
      text: 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
    })
  }
}

/*
|--------------------------------------------------------------------------
| Fetch Goods Return
|--------------------------------------------------------------------------
*/
const fetchGoodsReturns = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get(
      '/transaction/goods-return',
      {
        headers: {
          Accept: 'application/json',
        },

        params: {
          search:
            searchQuery.value || undefined,

          status:
            selectedStatus.value || undefined,

          tanggal_mulai:
            tanggalMulai.value || undefined,

          tanggal_selesai:
            tanggalSelesai.value || undefined,

          page:
            currentPage.value,

          per_page:
            rowPerPage.value,
        },
      },
    )

    rows.value = Array.isArray(
      response.data?.data,
    )
      ? response.data.data
      : []

    /*
    |--------------------------------------------------------------------------
    | Response Goods Return menggunakan pagination/meta object
    |--------------------------------------------------------------------------
    */
    const pagination = (
      response.data?.pagination
      ?? response.data?.meta
      ?? {}
    )

    totalData.value = Number(
      pagination.total
      ?? rows.value.length,
    )

    currentPage.value = Number(
      pagination.current_page
      ?? currentPage.value,
    )

    rowPerPage.value = Number(
      pagination.per_page
      ?? rowPerPage.value,
    )
  }
  catch (error: unknown) {
    loadError.value = true

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal memuat data Goods Return.',
      ),
    })

    rows.value = []
    totalData.value = 0
  }
  finally {
    loading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Reset filter
|--------------------------------------------------------------------------
*/
const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = null
  tanggalMulai.value = null
  tanggalSelesai.value = null

  datePickerKey.value += 1
  currentPage.value = 1

  await fetchGoodsReturns()
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/
const goToCreate = (): void => {
  router.push(
    '/non_trade/goods_return/create',
  )
}

const goToEdit = (
  publicId: string,
): void => {
  router.push(
    `/non_trade/goods_return/edit?id=${encodeURIComponent(publicId)}`,
  )
}

/*
|--------------------------------------------------------------------------
| Detail
|--------------------------------------------------------------------------
*/
const openDetail = async (
  publicId: string,
): Promise<void> => {
  if (!publicId) {
    showErrorToast({
      title: 'Error',
      text: 'Public ID Goods Return tidak ditemukan.',
    })

    return
  }

  try {
    detailItemPage.value = 1
    detailItemPerPage.value = 5

    showLoadingAlert(
      'Memuat data Goods Return',
      'Mohon tunggu sebentar',
    )

    const response = await axios.get(
      `/transaction/goods-return/${encodeURIComponent(publicId)}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    selectedReturn.value = (
      response.data?.data
      ?? null
    )

    closeAlert()

    await nextTick()

    detailDialog.value = true
  }
  catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal memuat detail Goods Return.',
      ),
    })
  }
}

const closeDetail = (): void => {
  detailDialog.value = false
  selectedReturn.value = null
}

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/
const openDelete = async (
  row: GoodsReturnRow,
): Promise<void> => {
  if (
    String(row.status || '')
      .toUpperCase()
    !== 'DRAFT'
  ) {
    showErrorToast({
      title: 'Tidak dapat dihapus',
      text: 'Goods Return hanya dapat dihapus jika status masih DRAFT.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus Goods Return?',
    html:
      `Apakah Anda yakin ingin menghapus Goods Return `
      + `<strong>${row.nomor_return || '-'}</strong>?`,

    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(
      'Menghapus Goods Return...',
      'Mohon tunggu sebentar.',
    )

    const response = await axios.delete(
      `/transaction/goods-return/${encodeURIComponent(row.public_id)}`,
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
        text:
          response.data?.message
          || `Goods Return "${row.nomor_return}" berhasil dihapus.`,
      })

      if (
        rows.value.length === 1
        && currentPage.value > 1
      ) {
        currentPage.value -= 1
      }

      await fetchGoodsReturns()

      return
    }

    showErrorToast({
      title: 'Gagal',
      text:
        response.data?.message
        || 'Gagal menghapus Goods Return.',
    })
  }
  catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal menghapus Goods Return.',
      ),
    })
  }
}

/*
|--------------------------------------------------------------------------
| Post
|--------------------------------------------------------------------------
*/
const postGoodsReturn = async (
  row: GoodsReturnRow,
): Promise<void> => {
  if (!row.public_id) {
    showErrorToast({
      title: 'Error',
      text: 'Public ID Goods Return tidak ditemukan.',
    })

    return
  }

  if (
    String(row.status || '')
      .toUpperCase()
    !== 'DRAFT'
  ) {
    showErrorToast({
      title: 'Tidak dapat diposting',
      text: 'Goods Return hanya dapat diposting jika status masih DRAFT.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    title: 'Posting Goods Return?',

    text:
      `Apakah Anda yakin ingin posting Goods Return `
      + `"${row.nomor_return}"? Setelah diposting, `
      + `qty received PO akan dikurangi dan dokumen tidak dapat diedit.`,

    confirmButtonText: 'Ya, Posting',
    cancelButtonText: 'Batal',
    icon: 'question',
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(
      'Posting Goods Return...',
      'Mohon tunggu sebentar.',
    )

    await axios.patch(
      `/transaction/goods-return/${encodeURIComponent(row.public_id)}/post`,
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
      text:
        `Goods Return "${row.nomor_return}" berhasil diposting.`,
    })

    await fetchGoodsReturns()
  }
  catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal posting Goods Return.',
      ),
    })
  }
}

/*
|--------------------------------------------------------------------------
| Cancel
|--------------------------------------------------------------------------
*/
const openCancelDialog = (
  row: GoodsReturnRow,
): void => {
  if (
    String(row.status || '')
      .toUpperCase()
    !== 'POSTED'
  ) {
    showErrorToast({
      title: 'Tidak dapat dibatalkan',
      text: 'Hanya Goods Return berstatus POSTED yang dapat dibatalkan.',
    })

    return
  }

  if (row.has_replacement_gr) {
    showErrorToast({
      title: 'Tidak dapat dibatalkan',
      text: 'Goods Return sudah memiliki Goods Receipt replacement aktif.',
    })

    return
  }

  cancelTarget.value = row
  cancelNotes.value = ''
  cancelDialog.value = true
}

const closeCancelDialog = (): void => {
  if (cancelSubmitting.value)
    return

  cancelDialog.value = false
  cancelTarget.value = null
  cancelNotes.value = ''
}

const submitCancel = async (): Promise<void> => {
  const row = cancelTarget.value
  const notes = cancelNotes.value.trim()

  if (
    !row
    || cancelSubmitting.value
  ) {
    return
  }

  if (!notes) {
    showErrorToast({
      title: 'Alasan wajib diisi',
      text: 'Silakan isi alasan pembatalan Goods Return.',
    })

    return
  }

  /*
  |--------------------------------------------------------------------------
  | Tutup dialog alasan sebelum membuka SweetAlert
  |--------------------------------------------------------------------------
  | Jangan gunakan closeCancelDialog() karena function tersebut menghapus
  | cancelTarget dan cancelNotes.
  |--------------------------------------------------------------------------
  */
  cancelDialog.value = false

  await nextTick()

  /*
  |--------------------------------------------------------------------------
  | Tunggu animasi VDialog selesai
  |--------------------------------------------------------------------------
  */
  await new Promise<void>(resolve => {
    setTimeout(resolve, 200)
  })

  const confirmation = await showConfirmAlert({
    icon: 'warning',
    title: 'Batalkan Goods Return?',

    html:
      `Goods Return `
      + `<strong>${row.nomor_return || '-'}</strong> `
      + `akan dibatalkan.`,

    confirmButtonText: 'Ya, Batalkan',
    cancelButtonText: 'Kembali',
  })

  /*
  |--------------------------------------------------------------------------
  | Jika user batal dari SweetAlert, buka kembali dialog alasan
  |--------------------------------------------------------------------------
  */
  if (!confirmation.isConfirmed) {
    cancelDialog.value = true

    return
  }

  cancelSubmitting.value = true

  try {
    showLoadingAlert(
      'Membatalkan Goods Return...',
      'Mohon tunggu sebentar.',
    )

    const response = await axios.patch(
      `/transaction/goods-return/${encodeURIComponent(row.public_id)}/cancel`,
      {
        cancel_notes: notes,
      },
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Bersihkan state setelah cancel berhasil
    |--------------------------------------------------------------------------
    */
    cancelTarget.value = null
    cancelNotes.value = ''

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        || 'Goods Return berhasil dibatalkan.',
    })

    await fetchGoodsReturns()
  }
  catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal membatalkan Goods Return.',
      ),
    })

    /*
    |--------------------------------------------------------------------------
    | Buka kembali dialog agar user dapat mencoba lagi
    |--------------------------------------------------------------------------
    | Target dan alasan masih dipertahankan.
    |--------------------------------------------------------------------------
    */
    await nextTick()

    cancelDialog.value = true
  }
  finally {
    cancelSubmitting.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Watch filters and pagination
|--------------------------------------------------------------------------
*/
watch(
  [
    selectedStatus,
    tanggalMulai,
    tanggalSelesai,
  ],
  async () => {
    if (isResettingDateFilter.value)
      return

    currentPage.value = 1

    await fetchGoodsReturns()
  },
)

watch(currentPage, async () => {
  await fetchGoodsReturns()
})

watch(rowPerPage, async () => {
  currentPage.value = 1

  await fetchGoodsReturns()
})

/*
|--------------------------------------------------------------------------
| Page initialization and permission check
|--------------------------------------------------------------------------
*/
onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')

    return
  }

  await fetchGoodsReturns()

  const success = route.query.success

  if (success) {
    await router.replace({
      path: '/non_trade/goods_return',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Goods Return berhasil disimpan.',
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Goods Return berhasil diperbarui.',
        })
      }
    }, 300)
  }
})
</script>

<template>
  <section>
    <!--
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    -->
    <VCard
      title="Filters"
      class="mb-6"
    >
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            sm="3"
          >
            <VTextField
              v-model="searchQuery"
              label="Cari kode Return / GR / PO"
              placeholder="Cari goods return..."
              density="compact"
              clearable
              @keyup.enter="fetchGoodsReturns"
              @click:clear="fetchGoodsReturns"
            />
          </VCol>

          <VCol
            cols="12"
            sm="3"
          >
            <AppDateTimePicker
              :key="`tanggal-mulai-${datePickerKey}`"
              v-model="tanggalMulai"
              label="Tanggal Awal"
              density="compact"
              clearable
              :config="{
                dateFormat: 'Y-m-d',
              }"
              @update:model-value="
                validateTanggalFilter('mulai')
              "
            />
          </VCol>

          <VCol
            cols="12"
            sm="3"
          >
            <AppDateTimePicker
              :key="`tanggal-selesai-${datePickerKey}`"
              v-model="tanggalSelesai"
              label="Tanggal Akhir"
              density="compact"
              clearable
              :config="{
                dateFormat: 'Y-m-d',
              }"
              @update:model-value="
                validateTanggalFilter('selesai')
              "
            />
          </VCol>

          <VCol
            cols="12"
            sm="3"
          >
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

        <VRow class="mt-1">
          <VCol
            cols="12"
            class="d-flex justify-end"
          >
            <VBtn
              block
              color="secondary"
              prepend-icon="tabler-refresh"
              class="text-none"
              @click="resetFilters"
            >
              Reset Filter
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!--
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn
          v-if="canCreate"
          color="primary"
          prepend-icon="tabler-plus"
          class="text-none"
          @click="goToCreate"
        >
          Tambah Goods Return
        </VBtn>

        <VSpacer />

        <div class="d-flex align-center gap-2">
          <VChip
            v-if="loading"
            size="small"
            variant="tonal"
          >
            Loading...
          </VChip>

          <VBtn
            v-else-if="loadError"
            size="small"
            color="error"
            variant="tonal"
            prepend-icon="tabler-refresh"
            @click="fetchGoodsReturns"
          >
            Reload Data
          </VBtn>
        </div>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th
              scope="col"
              class="text-center"
            >
              No
            </th>

            <th
              scope="col"
              class="text-center"
            >
              Nomor Return
            </th>

            <th
              scope="col"
              class="text-center"
            >
              Tanggal
            </th>

            <th
              scope="col"
              class="text-center"
            >
              Nomor GR
            </th>

            <th
              scope="col"
              class="text-center"
            >
              Vendor
            </th>

            <th
              scope="col"
              class="text-center"
            >
              Status
            </th>

            <th
              scope="col"
              class="text-center"
              style="width: 5rem;"
            >
              Actions
            </th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(row, index) in rows"
            :key="row.id"
          >
            <td class="text-medium-emphasis text-center">
              {{
                (
                  (currentPage - 1)
                  * rowPerPage
                ) + Number(index) + 1
              }}
            </td>

            <td class="text-medium-emphasis text-center">
              <div>
                {{ row.nomor_return || '-' }}
              </div>

              <VChip
                v-if="row.has_replacement_gr"
                size="x-small"
                color="info"
                variant="tonal"
                class="mt-1"
              >
                {{
                  row.active_replacement_gr_count
                  || 0
                }}
                Replacement
              </VChip>
            </td>

            <td class="text-medium-emphasis text-center">
              {{ formatDate(row.tanggal_return) }}
            </td>

            <td class="text-medium-emphasis text-center">
              {{ row.nomor_gr || '-' }}
            </td>

            <td class="text-medium-emphasis text-center">
              {{ row.vendor || '-' }}
            </td>

            <td class="text-center">
              <VChip
                :color="getStatusColor(row.status)"
                size="small"
                class="text-capitalize"
              >
                {{ toTitleCase(row.status) }}
              </VChip>
            </td>

            <td
              class="text-center"
              style="width: 5rem;"
            >
              <VBtn
                size="x-small"
                color="default"
                variant="plain"
                icon
              >
                <VIcon
                  size="24"
                  icon="mdi-dots-vertical"
                />

                <VMenu activator="parent">
                  <VList>
                    <VListItem
                      href="javascript:void(0)"
                      @click="openDetail(row.public_id)"
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
                      v-if="
                        String(row.status || '')
                          .toUpperCase() === 'DRAFT'
                        && canUpdate
                        && row.can_update
                      "
                      href="javascript:void(0)"
                      @click="goToEdit(row.public_id)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-pencil-outline"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        Edit
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="
                        String(row.status || '')
                          .toUpperCase() === 'DRAFT'
                        && canDelete
                        && row.can_delete
                      "
                      href="javascript:void(0)"
                      @click="openDelete(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-trash"
                          :size="20"
                          class="me-3 text-error"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        Hapus
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="
                        String(row.status || '')
                          .toUpperCase() === 'DRAFT'
                        && canPost
                        && row.can_post
                      "
                      href="javascript:void(0)"
                      @click="postGoodsReturn(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-circle-check"
                          :size="20"
                          color="success"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-success">
                        Post Return
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="
                        String(row.status || '')
                          .toUpperCase() === 'POSTED'
                        && canCancel
                        && row.can_cancel
                      "
                      href="javascript:void(0)"
                      @click="openCancelDialog(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-circle-x"
                          :size="20"
                          color="error"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        Batalkan Return
                      </VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td
              colspan="8"
              class="text-center"
            >
              No data available
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div
          class="d-flex align-center me-3"
          style="width: 220px;"
        >
          <span class="text-no-wrap me-3">
            Rows per page:
          </span>

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

    <!--
    |--------------------------------------------------------------------------
    | Detail Goods Return
    |--------------------------------------------------------------------------
    -->
    <VDialog
      v-model="detailDialog"
      max-width="1100"
      persistent
      scrollable
    >
      <VCard
        v-if="selectedReturn"
        class="rounded-lg overflow-hidden"
      >
        <VCardText class="pa-0">
          <!-- Header -->
          <div class="pa-6 bg-primary text-white">
            <div class="d-flex flex-wrap align-start justify-space-between gap-4">
              <div>
                <div class="text-caption text-uppercase mb-1 opacity-80">
                  Goods Return Detail
                </div>

                <h2 class="text-h5 font-weight-bold mb-2">
                  {{ selectedReturn.nomor_return || '-' }}
                </h2>

                <VChip
                  :color="getStatusColor(selectedReturn.status)"
                  variant="flat"
                  size="small"
                >
                  {{ toTitleCase(selectedReturn.status) || '-' }}
                </VChip>
              </div>

              <VBtn
                icon
                variant="text"
                color="white"
                @click="closeDetail"
              >
                <VIcon icon="tabler-x" />
              </VBtn>
            </div>
          </div>

          <div class="pa-6">
            <!-- Summary -->
            <VRow>
              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="tonal"
                  color="primary"
                  class="h-100"
                >
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Goods Receipt Sumber
                    </div>

                    <div class="text-h6 font-weight-bold">
                      {{ selectedReturn.nomor_gr || '-' }}
                    </div>

                    <div class="text-body-2 mt-1">
                      {{ safeFormatDate(selectedReturn.tanggal_gr) }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="tonal"
                  color="success"
                  class="h-100"
                >
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Vendor
                    </div>

                    <div class="text-h6 font-weight-bold">
                      {{ selectedReturn.vendor || '-' }}
                    </div>

                    <div class="text-body-2 mt-1">
                      PO: {{ selectedReturn.nomor_po || '-' }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="tonal"
                  color="info"
                  class="h-100"
                >
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Total Qty Return
                    </div>

                    <div class="text-h6 font-weight-bold">
                      {{ formatDecimalQty(totalQtyReturnDetail) }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <!-- Document info -->
            <VRow class="mt-2">
              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  Tanggal Return
                </div>

                <div class="font-weight-medium">
                  {{ safeFormatDate(selectedReturn.tanggal_return) }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Dibuat Oleh
                </div>

                <div class="font-weight-medium">
                  {{ selectedReturn.created_by_name || '-' }}
                </div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  Tanggal Posted
                </div>

                <div class="font-weight-medium">
                  {{ safeFormatDate(selectedReturn.posted_at) }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Dipost Oleh
                </div>

                <div class="font-weight-medium">
                  {{ selectedReturn.posted_by_name || '-' }}
                </div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  Cabang
                </div>

                <div class="font-weight-medium">
                  {{ selectedReturn.cabang || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Department
                </div>

                <div class="font-weight-medium">
                  {{
                    selectedReturn.department_name
                    || selectedReturn.department
                    || '-'
                  }}
                </div>
              </VCol>

              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">
                  Catatan
                </div>

                <div class="font-weight-medium">
                  {{ selectedReturn.notes || '-' }}
                </div>
              </VCol>
            </VRow>

            <!-- Attachments -->
            <VDivider class="my-6" />

            <div class="d-flex align-center justify-space-between mb-4">
              <div>
                <h3 class="text-h6 font-weight-bold mb-1">
                  Lampiran
                </h3>

                <div class="text-body-2 text-medium-emphasis">
                  Dokumen pendukung Goods Return.
                </div>
              </div>

              <VChip
                color="primary"
                variant="tonal"
                prepend-icon="tabler-paperclip"
              >
                {{
                  selectedReturn.attachments?.length
                  || 0
                }}
                File
              </VChip>
            </div>

            <VAlert
              v-if="!selectedReturn.attachments?.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              Tidak ada Lampiran.
            </VAlert>

            <VTable
              v-else
              class="text-no-wrap rounded border"
            >
              <thead>
                <tr>
                  <th width="60">
                    No
                  </th>

                  <th>
                    Nama File
                  </th>

                  <th width="160">
                    Ukuran
                  </th>

                  <th width="180">
                    Tipe
                  </th>

                  <th
                    width="120"
                    class="text-center"
                  >
                    Aksi
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(attachment, index) in selectedReturn.attachments"
                  :key="attachment.id || index"
                >
                  <td>
                    {{ Number(index) + 1 }}
                  </td>

                  <td>
                    <div class="d-flex align-center">
                      <VIcon
                        icon="tabler-file"
                        size="18"
                        class="me-2"
                      />

                      <div>
                        <div class="font-weight-medium">
                          {{
                            attachment.file_original_name
                            || attachment.file_name
                            || '-'
                          }}
                        </div>

                        <div class="text-caption text-medium-emphasis">
                          {{ attachment.file_name || '-' }}
                        </div>
                      </div>
                    </div>
                  </td>

                  <td>
                    {{ formatFileSize(attachment.file_size) }}
                  </td>

                  <td>
                    {{ attachment.file_mime_type || '-' }}
                  </td>

                  <td class="text-center">
                    <VBtn
                      v-if="attachment.file_url"
                      icon
                      size="small"
                      variant="text"
                      color="primary"
                      :href="attachment.file_url"
                      target="_blank"
                    >
                      <VIcon icon="tabler-eye" />

                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        Lihat File
                      </VTooltip>
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>

            <!-- Items -->
            <VDivider class="my-6" />

            <div class="d-flex align-center justify-space-between mb-4">
              <div>
                <h3 class="text-h6 font-weight-bold mb-1">
                  Item Goods Return
                </h3>

                <div class="text-body-2 text-medium-emphasis">
                  Detail quantity barang yang dikembalikan.
                </div>
              </div>

              <VChip
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="tabler-list-details"
              >
                {{ detailItems.length }} Item
              </VChip>
            </div>

            <VTable class="text-no-wrap rounded border">
              <thead>
                <tr>
                  <th width="50">
                    No
                  </th>

                  <th>
                    Item
                  </th>

                  <th class="text-end">
                    Qty Received
                  </th>

                  <th class="text-end">
                    Sudah Return
                  </th>

                  <th class="text-end">
                    Qty Return
                  </th>

                  <th class="text-end">
                    Sisa Returnable
                  </th>

                  <th>
                    Alasan
                  </th>

                  <th>
                    Catatan
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(item, index) in paginatedDetailItems"
                  :key="item.id || item.public_id"
                >
                  <td>
                    {{
                      detailItemPerPage === 'ALL'
                        ? Number(index) + 1
                        : (
                            (
                              Number(detailItemPage) - 1
                            )
                            * Number(detailItemPerPage)
                          )
                          + Number(index)
                          + 1
                    }}
                  </td>

                  <td>
                    <div class="font-weight-medium">
                      {{ toTitleCase(item.nama_item) }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ item.unit_name || item.unit || '-' }}
                    </div>
                  </td>

                  <td class="text-end">
                    {{ formatDecimalQty(item.qty_received) }}
                  </td>

                  <td class="text-end">
                    {{ formatDecimalQty(item.qty_returned_before) }}
                  </td>

                  <td class="text-end">
                    <VChip
                      color="primary"
                      variant="tonal"
                      size="small"
                    >
                      {{ formatDecimalQty(item.qty_return) }}
                    </VChip>
                  </td>

                  <td class="text-end">
                    <VChip
                      :color="
                        Number(
                          item.qty_returnable_after
                          || 0
                        ) > 0
                          ? 'warning'
                          : 'success'
                      "
                      variant="tonal"
                      size="small"
                    >
                      {{
                        formatDecimalQty(
                          item.qty_returnable_after,
                        )
                      }}
                    </VChip>
                  </td>

                  <td>
                    <div class="font-weight-medium">
                      {{ item.reason_name || '-' }}
                    </div>
                  </td>

                  <td>
                    {{ item.reason_notes || '-' }}
                  </td>
                </tr>

                <tr v-if="!detailItems.length">
                  <td
                    colspan="8"
                    class="text-center py-8 text-medium-emphasis"
                  >
                    Item Goods Return belum tersedia.
                  </td>
                </tr>
              </tbody>
            </VTable>

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
              <div class="text-caption text-medium-emphasis">
                Total Item Return:
                {{ detailItems.length }}
              </div>

              <div class="d-flex align-center gap-3">
                <VSelect
                  v-model="detailItemPerPage"
                  :items="detailItemPerPageItems"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  hide-details
                  style="width: 110px;"
                  @update:model-value="
                    detailItemPage = 1
                  "
                />

                <VPagination
                  v-if="
                    detailItemPerPage !== 'ALL'
                    && detailItems.length
                      > Number(detailItemPerPage)
                  "
                  v-model="detailItemPage"
                  :length="detailItemTotalPage"
                  size="small"
                  :total-visible="3"
                />
              </div>
            </div>

            <!-- Cancellation information -->
            <template v-if="selectedReturn.cancelled_at">
              <VDivider class="my-6" />

              <VAlert
                type="error"
                variant="tonal"
              >
                <div class="font-weight-bold mb-1">
                  Goods Return Dibatalkan
                </div>

                <div>
                  Oleh:
                  {{
                    selectedReturn.cancelled_by_name
                    || '-'
                  }}
                </div>

                <div>
                  Tanggal:
                  {{
                    formatDate(
                      selectedReturn.cancelled_at,
                    )
                  }}
                </div>

                <div class="mt-2">
                  Alasan:
                  {{
                    selectedReturn.cancel_notes
                    || '-'
                  }}
                </div>
              </VAlert>
            </template>
          </div>
        </VCardText>

        <VCardActions class="justify-end pa-6 pt-0">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="closeDetail"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
    |--------------------------------------------------------------------------
    | Cancel dialog
    |--------------------------------------------------------------------------
    -->
    <VDialog
      v-model="cancelDialog"
      max-width="600"
      persistent
    >
      <VCard>
        <VCardItem>
          <VCardTitle>
            Batalkan Goods Return
          </VCardTitle>

          <VCardSubtitle>
            {{ cancelTarget?.nomor_return || '-' }}
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VAlert
            type="warning"
            variant="tonal"
            class="mb-5"
          >
            Pembatalan Goods Return akan mengembalikan
            qty barang ke qty received Purchase Order.
          </VAlert>

          <VTextarea
            v-model="cancelNotes"
            label="Alasan Pembatalan"
            placeholder="Tuliskan alasan pembatalan Goods Return"
            rows="4"
            auto-grow
            :disabled="cancelSubmitting"
          />
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end pa-4">
          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="cancelSubmitting"
            @click="closeCancelDialog"
          >
            Batal
          </VBtn>

          <VBtn
            color="error"
            :loading="cancelSubmitting"
            @click="submitCancel"
          >
            Batalkan Goods Return
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>