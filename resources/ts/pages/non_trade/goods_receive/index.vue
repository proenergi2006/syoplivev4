<script setup lang="ts">
import axios from '@axios'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'
import { formatDate, formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { useDeleteConfirm } from '@core/composable/useDeleteConfirm'
import { nextTick } from 'vue'
import {
  defaultModuleAbilities,
  normalizeModuleAbilities,
  type ModuleAbilities,
} from '@/types/abilities'
import { usePermissionStore } from '@/stores/permission'

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

interface GoodsReceiveDetailItem {
  id: number | string
  nama_item: string
  unit: string
  qty_ordered: number
  qty_received_before: number
  qty_receive: number
  qty_received_after: number
  qty_outstanding: number
  notes: string | null
}

const permissionStore = usePermissionStore()

const canView = computed(() => {
  return permissionStore.can('goods_receive.view')
})

const canCreate = computed(() => {
  return permissionStore.can('goods_receive.create')
})

const canUpdate = computed(() => {
  return permissionStore.can('goods_receive.update')
})

const canDelete = computed(() => {
  return permissionStore.can('goods_receive.delete')
})

const isCheckingPermission = ref(true)

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const loadError = ref(false)

const rows = ref<any[]>([])
const totalData = ref(0)
const currentPage = ref(1)
const rowPerPage = ref(10)

const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)
const tanggalMulai = ref<string | null>(null)
const tanggalSelesai = ref<string | null>(null)

const abilities = ref<ModuleAbilities>(
  defaultModuleAbilities(),
)

const detailDialog = ref(false)
const selectedGr = ref<any>(null)

const detailGrItemPage = ref(1)
const detailGrItemPerPage = ref<number | 'ALL'>(5)

const detailGrItemPerPageItems = [
{ title: '5', value: 5 },
{ title: '10', value: 10 },
{ title: '20', value: 20 },
{ title: '50', value: 50 },
{ title: 'All', value: 'ALL' },
]

const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Draft', value: 'Draft' },
  { title: 'Posted', value: 'Posted' },
]

const totalPage = computed(() => {
  return Math.ceil(totalData.value / rowPerPage.value) || 1
})

const paginationData = computed(() => {
  const firstIndex = rows.value.length ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${firstIndex}-${lastIndex} of ${totalData.value}`
})

const detailGrItems = computed(() => {
  return selectedGr.value?.items ?? []
})

const detailGrItemTotalPage = computed(() => {
  if (detailGrItemPerPage.value === 'ALL') return 1

  return Math.ceil(detailGrItems.value.length / Number(detailGrItemPerPage.value)) || 1
})

const paginatedDetailGrItems = computed(() => {
  if (detailGrItemPerPage.value === 'ALL') return detailGrItems.value

  const start = (Number(detailGrItemPage.value) - 1) * Number(detailGrItemPerPage.value)
  const end = start + Number(detailGrItemPerPage.value)

  return detailGrItems.value.slice(start, end)
})

const getStatusColor = (status?: string | null): string => {
  switch (String(status || '').toUpperCase()) {
    case 'POSTED':
      return 'success'
    case 'DRAFT':
      return 'warning'
    default:
      return 'secondary'
  }
}

const { openDeleteConfirm } = useDeleteConfirm()

const datePickerKey = ref(0)
const isResettingDateFilter = ref(false)

const resetDatePickerValue = async (field: 'mulai' | 'selesai') => {
  isResettingDateFilter.value = true

  if (field === 'mulai') {
    tanggalMulai.value = null
  } else {
    tanggalSelesai.value = null
  }

  // Force re-render AppDateTimePicker supaya display value ikut kosong
  datePickerKey.value += 1

  await nextTick()

  setTimeout(() => {
    isResettingDateFilter.value = false
  }, 150)
}

const validateTanggalFilter = async (changedField: 'mulai' | 'selesai') => {
  if (isResettingDateFilter.value) return
  if (!tanggalMulai.value || !tanggalSelesai.value) return

  const startDate = new Date(tanggalMulai.value)
  const endDate = new Date(tanggalSelesai.value)

  if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return

  if (changedField === 'mulai' && startDate > endDate) {
    await resetDatePickerValue('mulai')

    showErrorToast({
        title: 'Tanggal Tidak Valid',
        text: 'Tanggal awal tidak boleh lebih besar dari tanggal akhir.',
    })

    return
  }

  if (changedField === 'selesai' && endDate < startDate) {
    await resetDatePickerValue('selesai')

    showErrorToast({
        title: 'Tanggal Tidak Valid',
        text: 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
    })
  }
}

const openDelete = async (row: any): Promise<void> => {
  if (String(row.status || '').toUpperCase() !== 'DRAFT') {
    showErrorToast({
      title: 'Tidak dapat dihapus',
      text: 'Goods Receipt hanya dapat dihapus jika status masih DRAFT.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus Goods Receive?',
    html: `Apakah Anda yakin ingin menghapus Goods Receive <strong>${row.nomor_gr}</strong>?`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Menghapus Goods Receive...', 'Mohon tunggu sebentar.')

    const response = await axios.delete(
      `/transaction/goods-receive/${encodeURIComponent(row.public_id)}`,
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
        text: `Goods Receive "${row.nomor_gr}" berhasil dihapus`,
      })

      await fetchGoodsReceives()

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: response.data?.message || 'Gagal menghapus Goods Receive',
    })
  } catch (error: any) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message || 'Gagal menghapus Goods Receive',
    })
  }
}

const formatFileSize = (size: number | string | null | undefined): string => {
  const bytes = Number(size || 0)

  if (!bytes) return '-'

  const kb = bytes / 1024

  if (kb < 1024) return `${kb.toFixed(2)} KB`

  return `${(kb / 1024).toFixed(2)} MB`
}

const openDetail = async (publicId: string): Promise<void> => {
  if (!publicId) {
    showErrorToast({
      title: 'Error',
      text: 'Public ID Goods Receive tidak ditemukan.',
    })

    return
  }

  try {
    detailGrItemPage.value = 1
    detailGrItemPerPage.value = 5

    showLoadingAlert('Memuat data Goods Receive', 'Mohon tunggu sebentar')

    const response = await axios.get(
      `/transaction/goods-receive/${encodeURIComponent(publicId)}`,
      {
        headers: { Accept: 'application/json' },
      },
    )

    selectedGr.value = response.data?.data ?? null

    closeAlert()

    await nextTick()

    detailDialog.value = true
  } catch (error) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat detail Goods Receive.'),
    })
  }
}

const closeDetail = (): void => {
  detailDialog.value = false
  selectedGr.value = null
}

const statusGRColor = (status?: string): string => {
  const value = String(status || '').toUpperCase()

  if (value === 'POSTED') return 'success'
  if (value === 'DRAFT') return 'warning'

  return 'secondary'
}

const totalQtyReceiveDetail = computed(() => {
  const rows = selectedGr.value?.items ?? []

  return rows.reduce((sum: number, item: any) => {
    return sum + Number(item.qty_receive || 0)
  }, 0)
})

const fetchGoodsReceives = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get('/transaction/goods-receive', {
      headers: { Accept: 'application/json' },
      params: {
        search: searchQuery.value || undefined,
        status: selectedStatus.value || undefined,
        tanggal_mulai: tanggalMulai.value || undefined,
        tanggal_selesai: tanggalSelesai.value || undefined,
        page: currentPage.value,
        per_page: rowPerPage.value,
      },
    })

    rows.value = response.data?.data || []
    totalData.value = Number(response.data?.total || 0)
    currentPage.value = Number(response.data?.current_page || 1)
    rowPerPage.value = Number(response.data?.per_page || 10)

    abilities.value = normalizeModuleAbilities(
      response.data?.abilities,
    )
  } catch (error) {
    loadError.value = true

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat data purchase order'),
    })

    rows.value = []
    totalData.value = 0
  } finally {
    loading.value = false
  }
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = null
  tanggalMulai.value = null
  tanggalSelesai.value = null
  currentPage.value = 1

  await fetchGoodsReceives()
}

const goToCreate = (): void => {
  router.push('/non_trade/goods_receive/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/non_trade/goods_receive/edit?id=${publicId}`)
}

const postGoodsReceive = async (gr: any): Promise<void> => {
  if (!gr?.public_id) {
    showErrorToast({
      title: 'Error',
      text: 'Public ID Goods Receipt tidak ditemukan.',
    })

    return
  }

  if (String(gr.status || '').toUpperCase() !== 'DRAFT') {
    showErrorToast({
      title: 'Tidak dapat diposting',
      text: 'Goods Receipt hanya dapat diposting jika status masih DRAFT.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    title: 'Posting Goods Receipt?',
    text: `Apakah Anda yakin ingin posting Goods Receipt "${gr.nomor_gr}"? Setelah diposting, qty PO akan ter-update dan dokumen tidak dapat diedit.`,
    confirmButtonText: 'Ya, Posting',
    cancelButtonText: 'Batal',
    icon: 'question',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Posting Goods Receipt...', 'Mohon tunggu sebentar')

    await axios.patch(
      `/transaction/goods-receive/${encodeURIComponent(gr.public_id)}/post`,
      {},
      {
        headers: { Accept: 'application/json' },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: `Goods Receipt "${gr.nomor_gr}" berhasil diposting.`,
    })

    await fetchGoodsReceives()
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal posting Goods Receipt.'),
    })
  }
}

const cancelGR = (item: any): void => {
  console.log('CANCEL GR:', item)
}

watch([selectedStatus, tanggalMulai, tanggalSelesai], async () => {
  currentPage.value = 1
  await fetchGoodsReceives()
})

watch(currentPage, async () => {
  await fetchGoodsReceives()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchGoodsReceives()
})

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false

  fetchGoodsReceives()

  const success = route.query.success

  if (success) {
    await router.replace({
      path: '/non_trade/goods_receive',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Goods Receive berhasil disimpan.',
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Goods Receive berhasil diperbarui.',
        })
      }
    }, 300)
  }
})
</script>

<template>
  <section>
    <!-- Filters -->
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
              label="Cari kode GR / PO"
              placeholder="Cari goods receive..."
              density="compact"
              clearable
              @keyup.enter="fetchGoodsReceives"
              @click:clear="fetchGoodsReceives"
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
              :config="{ dateFormat: 'Y-m-d' }"
              @update:model-value="validateTanggalFilter('mulai')"
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
              :config="{ dateFormat: 'Y-m-d' }"
              @update:model-value="validateTanggalFilter('selesai')"
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
              color="secondary"
              prepend-icon="tabler-refresh"
              @click="resetFilters"
              class="text-none"
              block
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
        <VBtn
          v-if="canCreate"
          color="primary"
          @click="goToCreate"
          class="text-none"
        >
          + Tambah Goods Receipt
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
            @click="fetchGoodsReceives"
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
              Nomor GR
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
              Nomor PO
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
            v-for="(v, index) in rows"
            :key="v.id"
          >
            <td class="text-medium-emphasis text-center">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>

            <td class="text-medium-emphasis text-center">
              {{ v.nomor_gr || '-' }}
            </td>

            <td class="text-medium-emphasis text-center">
              {{ formatDate(v.tanggal_gr) }}
            </td>

            <td class="text-medium-emphasis text-center">
              {{ v.nomor_po || v.purchase_order?.nomor_po || '-' }}
            </td>

            <td class="text-medium-emphasis text-center">
              {{ v.vendor || v.vendor_name || v.vendor?.nama_vendor || '-' }}
            </td>

            <td class="text-center">
              <VChip
                :color="getStatusColor(v.status)"
                size="small"
                class="text-capitalize"
              >
                {{ toTitleCase(v.status) }}
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
                      v-if="String(v.status).toUpperCase() === 'DRAFT' && canUpdate"
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

                      <VListItemTitle>
                        Edit
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status || '').toUpperCase() === 'DRAFT' && canDelete"
                      href="javascript:void(0)"
                      @click="openDelete(v)"
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
                      v-if="String(v.status).toUpperCase() === 'DRAFT'"
                      href="javascript:void(0)"
                      @click="postGoodsReceive(v)"
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
                        Post GR
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
      max-width="1100"
      persistent
      scrollable
    >
      <VCard
        v-if="selectedGr"
        class="rounded-lg overflow-hidden"
      >
        <VCardText class="pa-0">
          <div class="pa-6 bg-primary text-white">
            <div class="d-flex flex-wrap align-start justify-space-between gap-4">
              <div>
                <div class="text-caption text-uppercase mb-1 opacity-80">
                  Goods Receipt Detail
                </div>

                <h2 class="text-h5 font-weight-bold mb-2">
                  {{ selectedGr.nomor_gr || '-' }}
                </h2>

                <div class="d-flex flex-wrap gap-2">
                  <VChip
                    :color="statusGRColor(selectedGr.status)"
                    variant="flat"
                    size="small"
                  >
                    {{ toTitleCase(selectedGr.status) || '-' }}
                  </VChip>
                </div>
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
            <VRow>
              <VCol cols="12" md="4">
                <VCard variant="tonal" color="primary" class="h-100">
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Purchase Order
                    </div>
                    <div class="text-h6 font-weight-bold">
                      {{ selectedGr.nomor_po ?? '-' }}
                    </div>
                    <div class="text-body-2 mt-1">
                      {{ formatDate(selectedGr.tanggal_po) ?? '-' }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="4">
                <VCard variant="tonal" color="success" class="h-100">
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Vendor
                    </div>
                    <div class="text-h6 font-weight-bold">
                      {{ selectedGr.vendor ?? '-' }}
                    </div>
                    <div class="text-body-2 mt-1">
                      {{ formatStatusPKP(selectedGr.status_pkp) ?? '-' }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="4">
                <VCard variant="tonal" color="info" class="h-100">
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      Total Qty Receive
                    </div>
                    <div class="text-h6 font-weight-bold">
                      {{ formatDecimalQty(totalQtyReceiveDetail) }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <VRow class="mt-2">
              <VCol cols="12" md="4">
                <div class="text-caption text-medium-emphasis">
                  Tanggal GR
                </div>
                <div class="font-weight-medium">
                  {{ formatDate(selectedGr.tanggal_gr) || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Dibuat Oleh
                </div>
                <div class="font-weight-medium">
                  {{ selectedGr.created_by || '-' }}
                </div>
              </VCol>

              <VCol cols="12" md="4">
                <div class="text-caption text-medium-emphasis">
                  Tanggal Posted
                </div>
                <div class="font-weight-medium">
                  {{ formatDate(selectedGr.posted_at) || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Dipost Oleh
                </div>
                <div class="font-weight-medium">
                  {{ selectedGr.posted_by || '-' }}
                </div>
              </VCol>
            </VRow>

            <VDivider class="my-6" />

            <VRow class="mt-2">
              <VCol cols="12" md="4">
                <div class="text-caption text-medium-emphasis">
                  Cabang
                </div>
                <div class="font-weight-medium">
                  {{ selectedGr.cabang ?? '-' }}
                </div>
              </VCol>

              <VCol cols="12" md="4">
                <div class="text-caption text-medium-emphasis">
                  Department
                </div>
                <div class="font-weight-medium">
                  {{ selectedGr.department ?? '-' }}
                </div>
              </VCol>

              <VCol cols="12" md="4">
                <div class="text-caption text-medium-emphasis">
                  Catatan
                </div>
                <div class="font-weight-medium">
                  {{ selectedGr.notes || '-' }}
                </div>
              </VCol>
            </VRow>

            <VDivider class="my-6" />

              <div class="d-flex align-center justify-space-between mb-4">
                <div>
                  <h3 class="text-h6 font-weight-bold mb-1">
                    Lampiran
                  </h3>
                  <div class="text-body-2 text-medium-emphasis">
                    Dokumen pendukung Goods Receipt seperti Surat Jalan, DO, atau foto barang.
                  </div>
                </div>

                <VChip
                  color="primary"
                  variant="tonal"
                  prepend-icon="tabler-paperclip"
                >
                  {{ selectedGr.attachments?.length || 0 }} File
                </VChip>
              </div>

              <VAlert
                v-if="!selectedGr.attachments?.length"
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
                    <th width="60">No</th>
                    <th>Nama File</th>
                    <th width="160">Ukuran</th>
                    <th width="180">Tipe</th>
                    <th width="120" class="text-center">Aksi</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="(attachment, index) in selectedGr.attachments"
                    :key="attachment.id || index"
                  >
                    <td>{{ Number(index) + 1 }}</td>

                    <td>
                      <div class="d-flex align-center">
                        <VIcon
                          icon="tabler-file"
                          size="18"
                          class="me-2"
                        />

                        <div>
                          <div class="font-weight-medium">
                            {{ attachment.file_original_name || attachment.file_name || '-' }}
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
                        <VTooltip activator="parent" location="top">
                          Lihat File
                        </VTooltip>
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>

            <VDivider class="my-6" />

            <div class="d-flex align-center justify-space-between mb-4">
              <div>
                <h3 class="text-h6 font-weight-bold mb-1">
                  Item Goods Receive
                </h3>
                <div class="text-body-2 text-medium-emphasis">
                  Detail quantity penerimaan berdasarkan item PO.
                </div>
              </div>

              <VChip
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="tabler-list-details"
              >
                {{ detailGrItems.length }} Item
              </VChip>
            </div>

            <VTable class="text-no-wrap rounded border">
              <thead>
                <tr>
                  <th width="50">No</th>
                  <th>Item</th>
                  <th class="text-end">Qty PO</th>
                  <th class="text-end">Sudah GR</th>
                  <th class="text-end">Qty Receive</th>
                  <th class="text-end">Total Setelah GR</th>
                  <th class="text-end">Sisa</th>
                  <th>Catatan</th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(item, index) in paginatedDetailGrItems"
                  :key="item.id"
                >
                  <td>
                    {{ detailGrItemPerPage === 'ALL'
                      ? Number(index) + 1
                      : ((Number(detailGrItemPage) - 1) * Number(detailGrItemPerPage)) + Number(index) + 1
                    }}
                  </td>

                  <td>
                    <div class="font-weight-medium">
                      {{ toTitleCase(item.nama_item) }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ item.unit }}
                    </div>
                  </td>

                  <td class="text-end">
                    {{ formatDecimalQty(item.qty_ordered) }}
                  </td>

                  <td class="text-end">
                    {{ formatDecimalQty(item.qty_received_before) }}
                  </td>

                  <td class="text-end">
                    <VChip color="primary" variant="tonal" size="small">
                      {{ formatDecimalQty(item.qty_receive) }}
                    </VChip>
                  </td>

                  <td class="text-end">
                    {{ formatDecimalQty(item.qty_received_after) }}
                  </td>

                  <td class="text-end">
                    <VChip
                      :color="Number(item.qty_outstanding || 0) > 0 ? 'warning' : 'success'"
                      variant="tonal"
                      size="small"
                    >
                      {{ formatDecimalQty(item.qty_outstanding) }}
                    </VChip>
                  </td>

                  <td>
                    {{ item.notes || '-' }}
                  </td>
                </tr>

                <tr v-if="!detailGrItems.length">
                  <td colspan="8" class="text-center py-8 text-medium-emphasis">
                    Item Goods Receive belum tersedia.
                  </td>
                </tr>
              </tbody>
            </VTable>

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
              <div class="text-caption text-medium-emphasis">
                Total Item GR: {{ detailGrItems.length }}
              </div>

              <div class="d-flex align-center gap-3">
                <VSelect
                  v-model="detailGrItemPerPage"
                  :items="detailGrItemPerPageItems"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  hide-details
                  style="width: 110px;"
                  @update:model-value="detailGrItemPage = 1"
                />

                <VPagination
                  v-if="detailGrItemPerPage !== 'ALL' && detailGrItems.length > Number(detailGrItemPerPage)"
                  v-model="detailGrItemPage"
                  :length="detailGrItemTotalPage"
                  size="small"
                  :total-visible="3"
                />
              </div>
            </div>
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
  </section>
</template>