<script setup lang="ts">
import { computed, onMounted, ref, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from '@axios'
import SignaturePad from 'signature_pad'

import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { useDeleteConfirm } from '@core/composable/useDeleteConfirm'
import { formatDate, formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'
import { usePolling } from '@core/composable/usePolling'
import ApprovalHistoryDialog from '@core/components/ApprovalHistoryPODialog.vue'
import {
  defaultModuleAbilities,
  normalizeModuleAbilities,
  type ModuleAbilities,
} from '@/types/abilities'
import { usePermissionStore } from '@/stores/permission'

interface ApprovalHistoryItem {
  id?: number
  step_order: number | string
  label?: string | null
  approver_type?: string | null
  approver_id?: number | string | null
  approver_name_snapshot?: string | null
  status?: string | null
  approved_at?: string | null
  rejected_at?: string | null
  signed_at?: string | null
  notes?: string | null
}

interface PurchaseOrderItem {
  id: number
  public_id: string
  nomor_po: string | null
  tanggal_po: string | null
  vendor: string | null
  cabang: string | null
  department: string | null
  jenis_pembayaran: string | null
  top: number | null
  total_nilai: number | null
  status: string | null
  can_approve?: boolean
  can_update?: boolean
  can_delete?: boolean
  can_submit?: boolean
  is_owner?: boolean
  status_receive: string | null
}

interface PurchaseOrderApiResponse {
  success?: boolean
  status?: boolean
  data?: PurchaseOrderItem[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  abilities?: ModuleAbilities
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

const permissionStore = usePermissionStore()

const canView = computed(() => {
  return permissionStore.can('purchase_order.view')
})

const canCreate = computed(() => {
  return permissionStore.can('purchase_order.create')
})

const canUpdate = computed(() => {
  return permissionStore.can('purchase_order.update')
})

const canDelete = computed(() => {
  return permissionStore.can('purchase_order.delete')
})

const isCheckingPermission = ref(true)

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const rows = ref<PurchaseOrderItem[]>([])

// Signature Pad
const signatureDialog = ref(false)
const signatureCanvasRef = ref<HTMLCanvasElement | null>(null)
const signaturePad = ref<any>(null)
const signatureAgree = ref(false)
const signatureError = ref('')
const signatureLoading = ref(false)
const submitLoading = ref(false)
const approveLoading = ref(false)
const approveNotes = ref('')

// Reject
const rejectDialog = ref(false)
const rejectTarget = ref<any>(null)
const rejectNotes = ref('')
const rejectLoading = ref(false)

const pendingAction = ref<'submit' | 'approve' | null>(null)
const selectedPo = ref<any>(null)

const printLoadingId = ref<string | null>(null)

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
const detailError = ref('')
const detailPurchaseOrder = ref<any | null>(null)
const detailPurchaseOrderPublicId = ref<string | null>(null)
const visiblePrCount = ref(5)
const detailItemPage = ref(1)
const detailItemPerPage = ref<number | 'ALL'>(10)

const currentUser = ref<any | null>(null)

const isApprovalHistoryDialogOpen = ref(false)
const selectedApprovalHistory = ref<ApprovalHistoryItem[]>([])
const selectedPONomor = ref('-')

const abilities = ref<ModuleAbilities>(
  defaultModuleAbilities(),
)

const canApprovePO = (row: PurchaseOrderItem): boolean => {
  const status = String(row.status || '').toUpperCase()

  return status === 'IN PROGRESS'
    && row.can_approve === true
}

const openApprovalHistory = async (item: any): Promise<void> => {
  try {
    showLoadingAlert('Memuat history approval...', 'Mohon tunggu sebentar')

    const res = await axios.get(`/transaction/purchase-order/${encodeURIComponent(item.public_id)}`, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    const data = res.data?.data ?? {}

    selectedPONomor.value = data.nomor_po ?? item.nomor_po ?? '-'
    selectedApprovalHistory.value = Array.isArray(data.approvals)
      ? data.approvals
      : []

    isApprovalHistoryDialogOpen.value = true
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat history approval'),
    })
  }
}

const visibleRelatedPurchaseRequests = computed(() => {
  const list = detailPurchaseOrder.value?.purchase_requests || []

  return list.slice(0, visiblePrCount.value)
})

const hasMoreRelatedPurchaseRequests = computed(() => {
  const list = detailPurchaseOrder.value?.purchase_requests || []

  return visiblePrCount.value < list.length
})

const showMoreRelatedPurchaseRequests = (): void => {
  visiblePrCount.value += 5
}

const detailItemPerPageItems = [
  { title: '10', value: 10 },
  { title: '20', value: 20 },
  { title: '50', value: 50 },
  { title: 'All', value: 'ALL' },
]

const paginatedDetailItems = computed(() => {
  const items = detailItems.value || []

  if (detailItemPerPage.value === 'ALL') return items

  const start = (detailItemPage.value - 1) * Number(detailItemPerPage.value)
  const end = start + Number(detailItemPerPage.value)

  return items.slice(start, end)
})

const detailItemTotalPage = computed(() => {
  const items = detailItems.value || []

  if (detailItemPerPage.value === 'ALL') return 1

  return Math.ceil(items.length / Number(detailItemPerPage.value)) || 1
})

const detailItems = computed(() => detailPurchaseOrder.value?.items || [])

const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Draft', value: 'Draft' },
  { title: 'In Progress', value: 'In Progress' },
  { title: 'Approved', value: 'Approved' },
  { title: 'Rejected', value: 'Rejected' },
]

const paginationData = computed(() => {
  if (!totalData.value) return '0-0 of 0'

  const firstIndex = (currentPage.value - 1) * rowPerPage.value + 1
  const lastIndex = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${firstIndex}-${lastIndex} of ${totalData.value}`
})

const formatStatus = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'draft') return 'Draft'
  if (normalized === 'in progress') return 'In Progress'
  if (normalized === 'approved') return 'Approved'
  if (normalized === 'rejected') return 'Rejected'

  return status
}

const formatStatusReceive = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'open') return 'Open'
  if (normalized === 'partial') return 'Partial'
  if (normalized === 'completed') return 'Completed'

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

const getStatusReceiveColor = (status: string | null): string => {
  const normalized = String(status || '').toLowerCase()

  if (normalized === 'open') return 'info'
  if (normalized === 'partial') return 'warning'
  if (normalized === 'completed') return 'success'

  return 'secondary'
}

const formatCurrency = (value: number | null): string => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(Number(value || 0))
}

const loadCurrentUser = async (): Promise<void> => {
  try {
    const res = await axios.get('/auth/me', {
      headers: { Accept: 'application/json' },
    })

    currentUser.value = res.data?.data || null

    console.log('CURRENT USER', currentUser.value)
  } catch (error) {
    console.error('[AUTH] Failed load current user', error)
    currentUser.value = null
  }
}

const handlePurchaseOrderRefresh = async (): Promise<void> => {
  await fetchPurchaseOrders()
}

const fetchPurchaseOrders = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get<PurchaseOrderApiResponse>(
      '/transaction/purchase-order',
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

    abilities.value = normalizeModuleAbilities(
      responseData?.abilities,
    )

    const meta = responseData?.meta

    totalData.value = Number(meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(meta?.last_page ?? 1)
    currentPage.value = Number(meta?.current_page ?? 1)
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    const status = err.response?.status

    /*
    * 401 berarti token tidak ada atau sudah kedaluwarsa.
    * Jangan tampilkan toast Unauthenticated karena user
    * sudah diarahkan kembali ke halaman login.
    */
    if (status === 401) {
      rows.value = []
      totalData.value = 0
      totalPage.value = 1

      return
    }

    loadError.value = true

    console.error(
      '[Purchase Order] FETCH ERROR:',
      err,
    )

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal memuat data purchase order',
      ),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

// UsePolling
usePolling(fetchPurchaseOrders, {
  interval: 30000,
})

const calcPOTotal = (items: any[] = []) => {
  return items.reduce((total, item) => total + Number(item.subtotal || 0), 0)
}

const checkUserSignature = async (): Promise<boolean> => {
  const response = await axios.get('/master/user/check-signature', {
    headers: { Accept: 'application/json' },
  })

  return response.data?.has_signature === true
}

const openRejectPO = (po: any): void => {
  rejectTarget.value = po
  rejectNotes.value = ''
  rejectDialog.value = true
}

const rejectPurchaseOrder = async (): Promise<void> => {
  if (!rejectTarget.value || rejectLoading.value) return

  const target = { ...rejectTarget.value }
  const notes = rejectNotes.value || null

  // tutup modal notes dulu supaya SweetAlert tidak ketutup
  rejectDialog.value = false

  await nextTick()

  const confirm = await showConfirmAlert({
    title: 'Reject Purchase Order?',
    text: `Purchase Order "${target.nomor_po}" akan ditolak.`,
    confirmButtonText: 'Ya, reject',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    // kalau batal, buka lagi modal notes agar catatan tidak hilang
    rejectDialog.value = true
    return
  }

  rejectLoading.value = true

  try {
    showLoadingAlert('Reject Purchase Order...', 'Mohon tunggu sebentar')

    const response = await axios.patch(`/transaction/purchase-order/${target.public_id}/reject`, {
      notes,
    }, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    rejectNotes.value = ''
    rejectTarget.value = null

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || `Purchase Order "${target.nomor_po}" berhasil direject`,
    })

    await fetchPurchaseOrders()
  } catch (error: unknown) {
    closeAlert()

    // kalau gagal, modal notes dibuka lagi supaya user bisa koreksi/ulang
    rejectDialog.value = true

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal reject Purchase Order'),
    })
  } finally {
    rejectLoading.value = false
  }
}

const openSubmitPO = async (po: any): Promise<void> => {
  selectedPo.value = po
  pendingAction.value = 'submit'

  const hasSignature = await checkUserSignature()

  if (!hasSignature) {
    openSignatureDialog()
    return
  }

  await submitPurchaseOrder()
}

const openApprovePO = async (po: any): Promise<void> => {
  selectedPo.value = po
  pendingAction.value = 'approve'

  const hasSignature = await checkUserSignature()

  if (!hasSignature) {
    openSignatureDialog()
    return
  }

  await approvePurchaseOrder()
}

const openSignatureDialog = async (): Promise<void> => {
  signatureError.value = ''
  signatureAgree.value = false
  signatureDialog.value = true

  await nextTick()

  setTimeout(() => {
    initSignaturePad()
  }, 300)
}

const resizeSignatureCanvas = (): void => {
  const canvas = signatureCanvasRef.value
  if (!canvas) return

  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  const rect = canvas.getBoundingClientRect()

  canvas.width = rect.width * ratio
  canvas.height = rect.height * ratio

  const context = canvas.getContext('2d')
  if (!context) return

  context.setTransform(ratio, 0, 0, ratio, 0, 0)

  signaturePad.value?.clear()
}

const initSignaturePad = (): void => {
  const canvas = signatureCanvasRef.value
  if (!canvas) return

  const rect = canvas.getBoundingClientRect()

  if (!rect.width || !rect.height) {
    setTimeout(initSignaturePad, 200)
    return
  }

  signaturePad.value = new SignaturePad(canvas, {
    minWidth: 0.8,
    maxWidth: 2.4,
    throttle: 16,
    penColor: 'black',
    backgroundColor: 'rgba(255,255,255,0)',
  })

  resizeSignatureCanvas()
}

const saveSignatureAndContinue = async (): Promise<void> => {
  if (!signaturePad.value || signaturePad.value.isEmpty()) {
    signatureError.value = 'Tanda tangan wajib diisi.'
    return
  }

  if (!signatureAgree.value) {
    signatureError.value = 'Anda wajib menyetujui penggunaan tanda tangan digital.'
    return
  }

  try {
    signatureLoading.value = true

    const signature = signaturePad.value.toDataURL('image/png')

    await axios.post('/master/user/store-signature', {
      signature,
    }, {
      headers: { Accept: 'application/json' },
    })

    signatureDialog.value = false

    if (pendingAction.value === 'submit') {
      await submitPurchaseOrder()
    }

    if (pendingAction.value === 'approve') {
      await approvePurchaseOrder()
    }
  } catch (error) {
    console.error(error)
    signatureError.value = 'Gagal menyimpan tanda tangan digital.'
  } finally {
    signatureLoading.value = false
  }
}

const printPurchaseOrder = async (publicId: string): Promise<void> => {
  if (printLoadingId.value) return

  printLoadingId.value = publicId

  try {
    showLoadingAlert('Membuka cetakan PO...', 'Mohon tunggu sebentar')

    const response = await axios.get(
      `/transaction/purchase-order/${publicId}/print`,
      {
        responseType: 'blob',
        headers: {
          Accept: 'application/pdf',
        },
      },
    )

    const file = new Blob([response.data], { type: 'application/pdf' })
    const fileURL = URL.createObjectURL(file)

    closeAlert()

    window.open(fileURL, '_blank')
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal mencetak Purchase Order.'),
    })
  } finally {
    printLoadingId.value = null
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  detailError.value = ''
  detailPurchaseOrder.value = null
  detailPurchaseOrderPublicId.value = publicId
  visiblePrCount.value = 5
  detailItemPage.value = 1
  detailItemPerPage.value = 10

  try {
    showLoadingAlert(
      'Memuat data Purchase Order',
      'Mohon tunggu sebentar',
    )

    const response = await axios.get(`/transaction/purchase-order/${publicId}`, {
      headers: { Accept: 'application/json' },
    })

    detailPurchaseOrder.value = response.data?.data || null

    closeAlert()
    detailDialog.value = true
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat data Purchase Order.'),
    })
  }
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = ''
  tanggalMulai.value = null
  tanggalSelesai.value = null
  currentPage.value = 1

  await fetchPurchaseOrders()
}

const goToCreate = (): void => {
  router.push('/non_trade/purchase_order/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/non_trade/purchase_order/edit?id=${publicId}`)
}

const { openDeleteConfirm } = useDeleteConfirm()

const openDelete = async (row: any): Promise<void> => {
  if (String(row.status || '').toUpperCase() !== 'DRAFT') {
    showErrorToast({
      title: 'Tidak dapat dihapus',
      text: 'Purchase Order hanya dapat dihapus jika status masih DRAFT.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus Purchase Order?',
    html: `Apakah Anda yakin ingin menghapus Purchase Order <strong>${row.nomor_po}</strong>?`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Menghapus Purchase Order...', 'Mohon tunggu sebentar.')

    const response = await axios.delete(
      `/transaction/purchase-order/${encodeURIComponent(row.public_id)}`,
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
        text: `Purchase Order "${row.nomor_po}" berhasil dihapus`,
      })

      await fetchPurchaseOrders()

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: response.data?.message || 'Gagal menghapus Purchase Order',
    })
  } catch (error: any) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message || 'Gagal menghapus Purchase Order',
    })
  }
}

const submitPurchaseOrder = async (): Promise<void> => {
  if (!selectedPo.value || submitLoading.value) return

  const confirm = await showConfirmAlert({
    title: 'Submit Purchase Order?',
    text: `Purchase Order "${selectedPo.value.nomor_po}" akan masuk proses approval.`,
    confirmButtonText: 'Ya, submit',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  submitLoading.value = true

  try {
    showLoadingAlert('Submit Purchase Order...', 'Mohon tunggu sebentar')

    await axios.patch(`/transaction/purchase-order/${selectedPo.value.public_id}/submit`, {}, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: `Purchase Order "${selectedPo.value.nomor_po}" berhasil disubmit`,
    })

    await fetchPurchaseOrders()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal submit Purchase Order'),
    })
  } finally {
    submitLoading.value = false
  }
}

const approvePurchaseOrder = async (): Promise<void> => {
  if (!selectedPo.value || approveLoading.value) return

  const target = { ...selectedPo.value }

  const confirm = await showConfirmAlert({
    title: 'Approve Purchase Order?',
    text: `Purchase Order "${target.nomor_po}" akan disetujui.`,
    confirmButtonText: 'Ya, approve',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  approveLoading.value = true

  try {
    showLoadingAlert('Approve Purchase Order...', 'Mohon tunggu sebentar')

    const response = await axios.patch(`/transaction/purchase-order/${target.public_id}/approve`, {
      notes: approveNotes.value || null,
    }, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    const responseData = response.data?.data || {}
    const newStatus = responseData.status || 'APPROVED'
    const newNomorPO = responseData.nomor_po || target.nomor_po

    rows.value = rows.value.map(item => {
      if (item.public_id !== target.public_id) return item

      return {
        ...item,
        nomor_po: newNomorPO,
        status: newStatus,
        approved_at: new Date().toISOString(),
      }
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || `Purchase Order "${target.nomor_po}" berhasil diapprove`,
    })

    await fetchPurchaseOrders()

    approveNotes.value = ''
    selectedPo.value = null
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal approve Purchase Order'),
    })
  } finally {
    approveLoading.value = false
  }
}

watch(currentPage, async () => {
  await fetchPurchaseOrders()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchPurchaseOrders()
})

watch([searchQuery, selectedStatus, tanggalMulai, tanggalSelesai], async () => {
  currentPage.value = 1
  await fetchPurchaseOrders()
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
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false

  await fetchPurchaseOrders()
  await loadCurrentUser()

  window.addEventListener('purchase-order:refresh', handlePurchaseOrderRefresh)

  fetchPurchaseOrders()

  window.addEventListener('resize', resizeSignatureCanvas)

  const success = route.query.success

  if (success) {
    await router.replace({
      path: '/non_trade/purchase_order',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Purchase Order berhasil disimpan.',
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Purchase Order berhasil diperbarui.',
        })
      }
    }, 300)
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeSignatureCanvas)
  window.removeEventListener('purchase-order:refresh', handlePurchaseOrderRefresh)
})
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="3">
            <VTextField
              v-model="searchQuery"
              label="Cari kode PO"
              placeholder="Cari purchase order..."
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="3">
            <AppDateTimePicker
              v-model="tanggalMulai"
              label="Tanggal Awal"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d' }"
            />
          </VCol>

          <VCol cols="12" sm="3">
            <AppDateTimePicker
              v-model="tanggalSelesai"
              label="Tanggal Akhir"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d' }"
            />
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

        <VRow class="mt-1">
          <VCol cols="12" class="d-flex justify-end">
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
        <VBtn color="primary" @click="goToCreate" class="text-none" v-if="canCreate" prepend-icon="tabler-plus"> Tambah Purchase Order
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
            @click="fetchPurchaseOrders"
          >
            Reload Data
          </VBtn>
        </div>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col" class="text-center">No</th>
            <th scope="col" class="text-center">Nomor PO</th>
            <th scope="col" class="text-center">Tanggal</th>
            <th scope="col" class="text-center">Cabang</th>
            <th scope="col" class="text-center">Department</th>
            <th scope="col" class="text-right">Total</th>
            <th scope="col" class="text-center">Status Pengajuan</th>
            <th scope="col" class="text-center">Status GR</th>
            <th scope="col" class="text-center" style="width: 5rem;">Actions</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id" :class="{
            'po-row-need-approval': canApprovePO(v),
          }">
            <td class="text-medium-emphasis text-center">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>

            <td>
              <div class="d-flex flex-column gap-1 text-center">
                <div class="font-weight-medium">
                  {{ v.nomor_po || '-' }}
                </div>

                <VChip
                  v-if="canApprovePO(v)"
                  size="x-small"
                  color="warning"
                  variant="tonal"
                  class="po-approval-chip"
                >
                  <VIcon
                    icon="tabler-alert-circle"
                    size="14"
                    start
                  />
                  Menunggu Approval Anda
                </VChip>
              </div>
            </td>

            <td class="text-medium-emphasis text-center">
              {{ formatDate(v.tanggal_po) }}
            </td>

            <td class="text-medium-emphasis text-center">{{ v.cabang || '-' }}</td>
            <td class="text-medium-emphasis text-center">{{ v.department || '-' }}</td>

            <td class="text-end text-medium-emphasis">
              {{ formatCurrency(v.total_nilai) }}
            </td>

            <td class="text-center">
              <VChip
                :color="getStatusColor(v.status)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatus(v.status) }}
              </VChip>
            </td>
            
            <td class="text-center">
              <VChip
                v-if="String(v.status || '').toUpperCase() === 'APPROVED'"
                :color="getStatusReceiveColor(v.status_receive)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatusReceive(v.status_receive) }}
              </VChip>

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
                        <VIcon icon="tabler-eye" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>
                        Lihat Detail
                      </VListItemTitle>
                    </VListItem>

                    <VListItem @click="openApprovalHistory(v)">
                      <template #prepend>
                        <VIcon icon="tabler-history" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>History Approval</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() == 'approved' && String(v.status).toLowerCase() !== 'rejected'"
                      href="javascript:void(0)"
                      :disabled="printLoadingId === v.public_id"
                      @click="printPurchaseOrder(v.public_id)"
                    >
                      <template #prepend>
                        <VProgressCircular
                          v-if="printLoadingId === v.public_id"
                          indeterminate
                          size="18"
                          width="2"
                          class="me-3"
                        />

                        <VIcon
                          v-else
                          icon="tabler-printer"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        {{ printLoadingId === v.public_id ? 'Membuka...' : 'Cetak' }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePO(v)"
                      @click="openApprovePO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-circle-check"
                          :size="20"
                          class="me-3 text-success"
                        />
                      </template>

                      <VListItemTitle class="text-success">
                        Approve
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePO(v)"
                      @click="openRejectPO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-close-circle-outline"
                          :size="20"
                          color="error"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        Reject
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="v.can_submit"
                      href="javascript:void(0)"
                      @click="openSubmitPO(v)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-send-outline" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>Submit</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft' && canUpdate"
                      href="javascript:void(0)"
                      @click="goToEdit(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft' && canDelete"
                      href="javascript:void(0)"
                      @click="openDelete(v)"
                    >
                      <template #prepend>
                        <VIcon icon="tabler-trash" :size="20" class="me-3 text-error" />
                      </template>
                      <VListItemTitle class="text-error">Delete</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="10" class="text-center">
              No data available
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

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
      <VCard class="po-detail-card">
        <VCardTitle class="po-detail-header px-6 py-5">
          <div class="d-flex align-center gap-3">
            <VAvatar color="primary" variant="tonal" size="42">
              <VIcon icon="tabler-file-invoice" />
            </VAvatar>

            <div>
              <div class="text-h6 font-weight-bold">
                Detail Purchase Order
              </div>
            </div>

            <VChip
              v-if="detailPurchaseOrder"
              size="small"
              variant="tonal"
              :color="getStatusColor(detailPurchaseOrder.status)"
              class="text-capitalize ms-2"
            >
              {{ formatStatus(detailPurchaseOrder.status) || '-' }}
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
          <div v-if="detailPurchaseOrder">
            <VRow class="mb-5">
              <VCol cols="12" md="8">
                <VCard class="h-100 rounded-lg po-info-card">
                  <VCardText>
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                      <div>
                        <div class="text-caption text-medium-emphasis">
                          Purchase Order
                        </div>
                        <div class="text-h6 font-weight-bold">
                          {{ detailPurchaseOrder.nomor_po || '-' }}
                        </div>
                      </div>

                      <VChip
                        size="small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="tabler-calendar"
                      >
                        {{ formatDate(detailPurchaseOrder.tanggal_po) || '-' }}
                      </VChip>
                    </div>

                    <VRow>
                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Vendor</div>
                          <div class="info-value">{{ detailPurchaseOrder.vendor_data?.nama_vendor || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Status PKP</div>
                          <div class="info-value">{{ formatStatusPKP(detailPurchaseOrder.vendor_data?.status_pkp) }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Cabang</div>
                          <div class="info-value">{{ detailPurchaseOrder.cabang || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Department</div>
                          <div class="info-value">{{ detailPurchaseOrder.department || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12">
                        <div class="info-box">
                          <div class="info-label">Notes</div>
                          <div class="info-value">{{ detailPurchaseOrder.notes || '-' }}</div>
                        </div>
                      </VCol>
                    </VRow>
                    <VRow class="mt-4">
                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Dibuat Oleh
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.created_by_name || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Dibuat Pada
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.created_at ? formatDate(detailPurchaseOrder.created_at) : '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Disubmit Oleh
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.submitted_by_name || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Disubmit Pada
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.submitted_at ? formatDate(detailPurchaseOrder.submitted_at) : '-' }}
                          </div>
                        </div>
                      </VCol>
                    </VRow>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="4">
                <VCard class="h-100 rounded-lg total-card">
                  <VCardText>
                    <div class="d-flex align-center justify-space-between mb-3">
                      <div class="text-caption text-medium-emphasis">
                        Purchase Request Terkait
                      </div>

                      <VChip
                        size="x-small"
                        color="primary"
                        variant="tonal"
                      >
                        {{ detailPurchaseOrder.purchase_requests?.length || 0 }} PR
                      </VChip>
                    </div>

                    <div
                      v-if="detailPurchaseOrder.purchase_requests?.length"
                      class="related-pr-scroll"
                    >
                      <div class="d-flex flex-column gap-2">
                        <TransitionGroup
                          name="pr-slide"
                          tag="div"
                          class="d-flex flex-column gap-2"
                        >
                          <div
                            v-for="pr in visibleRelatedPurchaseRequests"
                            :key="pr.id"
                            class="related-pr-item"
                          >
                            <div class="font-weight-bold text-primary related-pr-number">
                              {{ pr.nomor_pr }}
                            </div>

                            <div class="related-pr-meta">
                              <span>{{ formatDate(pr.tanggal_pr) }}</span>
                              <!-- <span>Rp {{ formatNumberWithoutRp(pr.total_amount || 0) }}</span> -->
                            </div>
                          </div>
                        </TransitionGroup>

                        <VBtn
                          v-if="hasMoreRelatedPurchaseRequests"
                          size="small"
                          variant="tonal"
                          color="primary"
                          block
                          prepend-icon="tabler-chevron-down"
                          @click="showMoreRelatedPurchaseRequests"
                        >
                          Tampilkan lainnya
                        </VBtn>
                      </div>
                    </div>

                    <VAlert
                      v-else
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      Tidak ada Purchase Request terkait.
                    </VAlert>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <VCard flat class="rounded-lg">
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                  <div class="text-subtitle-1 font-weight-bold">
                    Daftar Item Purchase Order
                  </div>

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                    prepend-icon="tabler-list-details"
                  >
                    {{ detailItems.length || 0 }} Item
                  </VChip>
                </div>

                <div class="detail-po-table-wrapper">
                  <VTable class="detail-po-table">
                    <thead>
                      <tr>
                        <th class="text-center col-no">No</th>
                        <th class="col-item">Nama Item</th>
                        <th class="col-note">Keterangan</th>
                        <th class="text-center col-qty">Qty PO</th>
                        <th class="text-center col-qty">Sudah GR</th>
                        <th class="text-center col-qty">Sisa GR</th>
                        <th class="text-center col-unit">Satuan</th>
                        <th class="text-end col-money">Harga Unit</th>
                        <th class="text-end col-money">Subtotal</th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="(item, index) in paginatedDetailItems"
                        :key="item.id || index"
                      >
                        <td class="text-center">
                          <div class="table-number">
                            {{ detailItemPerPage === 'ALL'
                              ? Number(index) + 1
                              : ((Number(detailItemPage) - 1) * Number(detailItemPerPage)) + Number(index) + 1
                            }}
                          </div>
                        </td>

                        <td>
                          <div class="item-main">
                            {{ toTitleCase(item.nama_item) || '-' }}
                          </div>
                        </td>

                        <td>
                          <div class="note-text">
                            {{ item.keterangan || '-' }}
                          </div>
                        </td>

                        <td class="text-center">
                          <div class="qty-wrapper">
                            <VChip
                              size="default"
                              color="warning"
                              variant="tonal"
                              class="qty-chip"
                            >
                              {{ formatDecimalQty(item.qty) }}
                            </VChip>
                          </div>
                        </td>

                        <td class="text-center">
                          <div class="qty-wrapper">
                            <VChip
                              size="default"
                              color="info"
                              variant="tonal"
                              class="qty-chip"
                            >
                              {{ formatDecimalQty(item.qty_received || 0) }}
                            </VChip>
                          </div>
                        </td>

                        <td class="text-center">
                          <div class="qty-wrapper">
                            <VChip
                              size="default"
                              :color="Number(item.qty_outstanding_receive || 0) <= 0 ? 'success' : 'warning'"
                              variant="tonal"
                              class="qty-chip"
                            >
                              {{ formatDecimalQty(item.qty_outstanding_receive || 0) }}
                            </VChip>
                          </div>
                        </td>

                        <td class="text-center">
                          <VChip
                            size="small"
                            color="secondary"
                            variant="tonal"
                          >
                            {{ item.satuan || '-' }}
                          </VChip>
                        </td>

                        <td class="text-end">
                          <div class="money-text">
                            Rp {{ formatNumberWithoutRp(item.harga_unit || 0) }}
                          </div>
                        </td>

                        <td class="text-end">
                          <div class="subtotal-text">
                            Rp {{ formatNumberWithoutRp(item.subtotal || 0) }}
                          </div>
                        </td>
                      </tr>

                      <tr v-if="!detailItems.length">
                        <td
                          colspan="9"
                          class="text-center text-medium-emphasis py-8"
                        >
                          Item belum tersedia.
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </div>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
                  <div class="text-caption text-medium-emphasis">
                    Total Item PO: {{ detailItems.length }}
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
                      @update:model-value="detailItemPage = 1"
                    />

                    <VPagination
                      v-if="detailItemPerPage !== 'ALL' && detailItems.length > Number(detailItemPerPage)"
                      v-model="detailItemPage"
                      :length="detailItemTotalPage"
                      size="small"
                      :total-visible="3"
                    />
                  </div>
                </div>
                <div class="d-flex justify-end mt-4">
                  <VCard
                    variant="tonal"
                    class="summary-total-box"
                  >
                    <VCardText class="py-3 px-4">
                      <template v-if="String(detailPurchaseOrder.vendor_data?.status_pkp).toUpperCase() === 'PKP'">
                        <div class="summary-row">
                          <span>Subtotal</span>
                          <strong>Rp {{ formatNumberWithoutRp(calcPOTotal(detailPurchaseOrder.items)) }}</strong>
                        </div>

                        <div class="summary-row">
                          <span>DPP</span>
                          <strong>Rp {{ formatNumberWithoutRp(detailPurchaseOrder.dpp || 0) }}</strong>
                        </div>

                        <div class="summary-row">
                          <span>PPN</span>
                          <strong>Rp {{ formatNumberWithoutRp(detailPurchaseOrder.ppn || 0) }}</strong>
                        </div>

                        <VDivider class="my-2" />
                      </template>

                      <div class="summary-row grand-total">
                        <span>Grand Total PO</span>
                        <strong>Rp {{ formatNumberWithoutRp(detailPurchaseOrder.total_nilai || calcPOTotal(detailPurchaseOrder.items)) }}</strong>
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
            class="text-none"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="signatureDialog"
      max-width="720"
      persistent
      scrollable
      class="signature-register-dialog"
    >
      <VCard class="signature-card">
        <VCardText class="pa-0">
          <div class="signature-header">
            <div class="signature-icon">
              ✍️
            </div>

            <div>
              <h3 class="signature-title">
                Registrasi Tanda Tangan Digital
              </h3>
              <p class="signature-subtitle">
                Tanda tangan ini cukup dibuat satu kali dan akan digunakan kembali pada proses transaksi berikutnya.
              </p>
            </div>
          </div>

          <div class="signature-alert">
            <strong>Mengapa diperlukan?</strong>
            <p>
              Sistem memerlukan tanda tangan digital Anda sebelum melakukan submit atau approval.
              Tanda tangan ini akan digunakan pada seluruh cetakan dokumen yang membutuhkan persetujuan,
              seperti proses submit ke approval maupun approval transaksi.
            </p>
          </div>

          <div class="signature-section-title">
            Silakan tanda tangan pada area berikut
          </div>

          <div class="signature-box">
            <canvas
              ref="signatureCanvasRef"
              class="signature-canvas"
            />
          </div>

          <div class="signature-action-row">
            <span class="signature-hint">
              Gunakan mouse, touchpad, atau layar sentuh.
            </span>

            <VBtn
              variant="text"
              color="error"
              size="small"
              :disabled="signatureLoading"
              @click="signaturePad?.clear()"
            >
              Clear
            </VBtn>
          </div>

          <div class="signature-agreement">
            <VCheckbox
              v-model="signatureAgree"
              density="compact"
              hide-details
              :disabled="signatureLoading"
            />

            <span>
              Saya menyetujui penggunaan tanda tangan digital ini sebagai identitas persetujuan saya
              pada dokumen dan transaksi yang memerlukan proses submit atau approval di sistem.
            </span>
          </div>

          <div
            v-if="signatureError"
            class="signature-error"
          >
            {{ signatureError }}
          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="signature-footer">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="signatureLoading"
            @click="signatureDialog = false"
            class="text-none"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            :loading="signatureLoading"
            @click="saveSignatureAndContinue"
            class="text-none"
          >
            Simpan & Lanjutkan
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="rejectDialog"
      max-width="560"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center gap-2">
          <VIcon
            icon="mdi-close-circle-outline"
            color="error"
          />
          Reject Purchase Order
        </VCardTitle>

        <VDivider />

        <VCardText>
          <p class="text-body-2 mb-4">
            Anda akan menolak Purchase Order:
            <strong>{{ rejectTarget?.nomor_po || '-' }}</strong>
          </p>

          <VTextarea
            v-model="rejectNotes"
            label="Catatan reject"
            placeholder="Masukkan alasan reject jika diperlukan"
            rows="4"
            auto-grow
            clearable
            :disabled="rejectLoading"
          />

          <div class="text-caption text-medium-emphasis mt-2">
            Catatan bersifat optional, namun disarankan diisi agar requester mengetahui alasan penolakan.
          </div>
        </VCardText>

        <VDivider />

        <VCardActions>
          <VSpacer />

          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="rejectLoading"
            @click="rejectDialog = false"
          >
            Batal
          </VBtn>

          <VBtn
            color="error"
            :loading="rejectLoading"
            @click="rejectPurchaseOrder"
          >
            Reject
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <ApprovalHistoryDialog
      v-model="isApprovalHistoryDialogOpen"
      :nomor-po="selectedPONomor"
      :approvals="selectedApprovalHistory"
    />
  </section>
</template>

<style lang="scss">
.text-capitalize { text-transform: capitalize; }
</style>

<style lang="scss" scoped>

.po-detail-card {
  border-radius: 10px !important;
}

.po-detail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.10),
    rgba(var(--v-theme-primary), 0.02)
  );
}

.po-info-card,
.total-card {
  border: 1px solid rgba(var(--v-theme-primary), 0.14);
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.07),
    rgba(var(--v-theme-surface), 1)
  );
}

.info-box {
  min-height: 68px;
  padding: 14px 16px;
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.76);
  border: 1px solid rgba(var(--v-theme-primary), 0.10);
}

.info-label {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.56);
  margin-bottom: 4px;
}

.info-value {
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.86);
  word-break: break-word;
}

.related-pr-item {
  padding: 10px 12px;
  border-radius: 14px;
  background: rgba(var(--v-theme-primary), 0.08);
}

.related-pr-number {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.3;
}

.related-pr-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 4px;
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-size: 12px;
  white-space: nowrap;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  gap: 50px;
  margin-block: 6px;
  font-size: medium;
  color: rgba(var(--v-theme-on-surface), 0.72);
}

.detail-po-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-radius: 18px;
  border: 1px solid rgba(var(--v-theme-primary), 0.08);
  background: white;
}

.detail-po-table {
  min-width: 980px;
}

.detail-po-table :deep(table) {
  border-collapse: separate;
  border-spacing: 0;
}

.detail-po-table th {
  background: rgba(var(--v-theme-primary), 0.05);
  color: rgba(var(--v-theme-on-surface), 0.78);
  font-size: 13px;
  font-weight: 700;
  padding: 16px 14px !important;
  white-space: nowrap;
  border-bottom: 1px solid rgba(var(--v-theme-primary), 0.08);
}

.detail-po-table td {
  padding: 14px !important;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.05);
  vertical-align: middle;
  background: white;
}

.detail-po-table tbody tr {
  transition: all 0.2s ease;
}

.detail-po-table tbody tr:hover td {
  background: rgba(var(--v-theme-primary), 0.025);
}

.col-no {
  width: 70px;
}

.col-item {
  width: 280px;
}

.col-qty {
  width: 130px;
}

.col-unit {
  width: 120px;
}

.col-money {
  width: 180px;
}

.col-note {
  width: 240px;
}

.table-number {
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.65);
}

.item-main {
  font-weight: 700;
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), 0.86);
  line-height: 1.4;
  white-space: normal;
  word-break: break-word;
}

.qty-wrapper {
  display: flex;
  justify-content: center;
}

.qty-chip {
  min-width: 44px;
  justify-content: center;
  font-weight: 700;
}

.money-text {
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.72);
  white-space: nowrap;
}

.subtotal-text {
  font-weight: 800;
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), 0.86);
  white-space: nowrap;
}

.note-text {
  color: rgba(var(--v-theme-on-surface), 0.64);
  line-height: 1.5;
  white-space: normal;
  word-break: break-word;
}

@media (max-width: 768px) {
  .detail-po-table {
    min-width: 900px;
  }
}

.item-title {
  font-weight: 700;
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.35;
}

.text-wrap-cell {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  color: rgba(var(--v-theme-on-surface), 0.68);
}

@media (max-width: 768px) {
  .detail-po-table,
  .detail-po-table :deep(table) {
    min-width: 860px;
    width: 860px;
  }
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

// SCROLL LIST PR TERKAIT
.related-pr-scroll {
  max-height: 320px;
  overflow-y: auto;
  padding-right: 4px;
}

.related-pr-scroll::-webkit-scrollbar {
  width: 6px;
}

.related-pr-scroll::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(var(--v-theme-primary), 0.25);
}

.pr-slide-enter-active {
  transition: all 0.26s ease;
}

.pr-slide-enter-from {
  opacity: 0;
  transform: translateY(-8px);
}

.pr-slide-enter-to {
  opacity: 1;
  transform: translateY(0);
}

// Signature Pad

.signature-box {
  width: 100%;
  height: 220px;
  border: 2px dashed rgb(var(--v-theme-primary));
  border-radius: 14px;
  background: #fff;
  overflow: hidden;
}

.signature-canvas {
  width: 100%;
  height: 220px;
  display: block;
  cursor: crosshair;
}

.signature-register-dialog {
  .v-overlay__content {
    width: calc(100% - 32px);
    margin: 16px;
  }
}

.signature-card {
  border-radius: 22px;
  overflow: hidden;
}

.signature-header {
  display: flex;
  gap: 16px;
  padding: 24px 28px 16px;
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.12), rgba(var(--v-theme-primary), 0.03));
}

.signature-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 48px;
  width: 48px;
  height: 48px;
  border-radius: 16px;
  background: rgb(var(--v-theme-primary));
  color: white;
  font-size: 24px;
}

.signature-title {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  color: rgba(var(--v-theme-on-surface), 0.92);
}

.signature-subtitle {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 1.6;
  color: rgba(var(--v-theme-on-surface), 0.68);
}

.signature-alert {
  margin: 20px 28px 0;
  padding: 14px 16px;
  border-radius: 16px;
  background: rgba(var(--v-theme-warning), 0.12);
  border: 1px solid rgba(var(--v-theme-warning), 0.35);
  color: rgba(var(--v-theme-on-surface), 0.82);
}

.signature-alert strong {
  display: block;
  margin-bottom: 4px;
  font-size: 14px;
}

.signature-alert p {
  margin: 0;
  font-size: 13px;
  line-height: 1.65;
}

.signature-section-title {
  margin: 20px 28px 10px;
  font-size: 13px;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.78);
}

.signature-box {
  margin: 0 28px;
  width: auto;
  height: 240px;
  border: 2px dashed rgb(var(--v-theme-primary));
  border-radius: 18px;
  background: #fff;
  overflow: hidden;
}

.signature-canvas {
  width: 100%;
  height: 240px;
  display: block;
  cursor: crosshair;
  touch-action: none;
}

.signature-action-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin: 8px 28px 0;
}

.signature-hint {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.55);
}

.signature-agreement {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 18px 28px 0;
  font-size: 13px;
  line-height: 1.6;
  color: rgba(var(--v-theme-on-surface), 0.78);
  word-break: normal;
  overflow-wrap: anywhere;
}

.signature-error {
  margin: 10px 28px 0;
  font-size: 13px;
  color: rgb(var(--v-theme-error));
}

.signature-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 28px;
}

@media (max-width: 600px) {
  .signature-header {
    padding: 20px 18px 14px;
  }

  .signature-title {
    font-size: 18px;
  }

  .signature-subtitle {
    font-size: 13px;
  }

  .signature-alert,
  .signature-section-title,
  .signature-box,
  .signature-action-row,
  .signature-agreement,
  .signature-error {
    margin-left: 18px;
    margin-right: 18px;
  }

  .signature-box {
    height: 190px;
  }

  .signature-canvas {
    height: 190px;
  }

  .signature-footer {
    padding: 14px 18px;
    flex-direction: column-reverse;
  }

  .signature-footer .v-btn {
    width: 100%;
  }
}

.po-row-need-approval {
  background: rgba(var(--v-theme-warning), 0.055);

  &:hover {
    background: rgba(var(--v-theme-warning), 0.09);
  }
}
</style>
