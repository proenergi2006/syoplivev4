<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue'
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
import { useDeleteConfirm } from '@core/composable/useDeleteConfirm'
import { formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { formatDate } from '@/utils/textFormatter'
import SignaturePad from 'signature_pad'
import ApprovalHistoryDialog from '@core/components/ApprovalHistoryPRDialog.vue'
import { usePolling } from '@core/composable/usePolling'
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

interface PurchaseRequestItem {
  id: number
  public_id: string
  nomor_pr: string | null
  tanggal_pr: string | null
  cabang: string | null
  department: string | null
  kategori: string | null
  pr_type: string | null
  status: string | null
  status_po: string | null

  can_approve?: boolean | number | string | null
  can_submit?: boolean | number | string | null
  approval_id?: number | null
  approval_step_order?: number | null
  approval_label?: string | null
  approval_mode?: 'ANY' | 'ALL' | string | null

  created_at?: string | null
  created_by?: number | null
  created_by_name?: string | null

  submitted_at?: string | null
  submitted_by?: number | null
  submitted_by_name?: string | null
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
  return permissionStore.can('purchase_request.view')
})

const canCreate = computed(() => {
  return permissionStore.can('purchase_request.create')
})

const canUpdate = computed(() => {
  return permissionStore.can('purchase_request.update')
})

const canDelete = computed(() => {
  return permissionStore.can('purchase_request.delete')
})

const isCheckingPermission = ref(true)

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const rows = ref<PurchaseRequestItem[]>([])

/*
|--------------------------------------------------------------------------
| Approval
|--------------------------------------------------------------------------
*/
const pendingAction = ref<'submit' | 'approve' | null>(null)
const selectedPr = ref<PurchaseRequestItem | null>(null)
const approveLoading = ref(false)
const approveNotes = ref('')
const approveDialog = ref(false)

/*
|--------------------------------------------------------------------------
| Reject
|--------------------------------------------------------------------------
*/
const rejectDialog = ref(false)
const rejectTarget = ref<PurchaseRequestItem | null>(null)
const rejectNotes = ref('')
const rejectLoading = ref(false)

/*
|--------------------------------------------------------------------------
| Print Purchase Requisition
|--------------------------------------------------------------------------
*/
const printLoadingId = ref<string | null>(null)

/*
|--------------------------------------------------------------------------
| Signature
|--------------------------------------------------------------------------
*/
const signatureDialog = ref(false)
const signatureCanvasRef = ref<HTMLCanvasElement | null>(null)
const signaturePad = ref<SignaturePad | null>(null)
const signatureAgree = ref(false)
const signatureError = ref('')
const signatureLoading = ref(false)

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

const isApprovalHistoryDialogOpen = ref(false)
const selectedApprovalHistory = ref<ApprovalHistoryItem[]>([])
const selectedPRNomor = ref('-')

const openedVendorPanels = ref<number[]>([])

const selectedStatusPO = ref('')

const abilities = ref<ModuleAbilities>(
  defaultModuleAbilities(),
)

const canApprovePurchaseRequest = (
  row: PurchaseRequestItem,
): boolean => {
  const status = String(row.status || '')
    .trim()
    .toUpperCase()

  const canApprove = row.can_approve === true
    || row.can_approve === 1
    || row.can_approve === '1'
    || String(row.can_approve).toLowerCase() === 'true'

  return status === 'IN PROGRESS' && canApprove
}

const checkUserSignature = async (): Promise<boolean> => {
  const response = await axios.get(
    '/master/user/check-signature',
    {
      headers: {
        Accept: 'application/json',
      },
    },
  )

  return response.data?.has_signature === true
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

  if (!canvas)
    return

  const ratio = Math.max(
    window.devicePixelRatio || 1,
    1,
  )

  const rect = canvas.getBoundingClientRect()

  canvas.width = rect.width * ratio
  canvas.height = rect.height * ratio

  const context = canvas.getContext('2d')

  if (!context)
    return

  context.setTransform(
    ratio,
    0,
    0,
    ratio,
    0,
    0,
  )

  signaturePad.value?.clear()
}

const initSignaturePad = (): void => {
  const canvas = signatureCanvasRef.value

  if (!canvas)
    return

  const rect = canvas.getBoundingClientRect()

  if (!rect.width || !rect.height) {
    setTimeout(initSignaturePad, 200)

    return
  }

  signaturePad.value?.off()

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
  if (
    !signaturePad.value
    || signaturePad.value.isEmpty()
  ) {
    signatureError.value = 'Tanda tangan wajib diisi.'

    return
  }

  if (!signatureAgree.value) {
    signatureError.value
      = 'Anda wajib menyetujui penggunaan tanda tangan digital.'

    return
  }

  try {
    signatureLoading.value = true
    signatureError.value = ''

    const signature = signaturePad.value.toDataURL(
      'image/png',
    )

    await axios.post(
      '/master/user/store-signature',
      {
        signature,
      },
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    signatureDialog.value = false

    await nextTick()

    if (
      pendingAction.value === 'submit'
      && selectedPr.value
    ) {
      await submitPurchaseRequest(selectedPr.value)
    }

    if (pendingAction.value === 'approve') {
      showApprovePurchaseRequestDialog()
      return
    }
  }
  catch (error: unknown) {
    signatureError.value = getApiErrorMessage(
      error,
      'Gagal menyimpan tanda tangan digital.',
    )
  }
  finally {
    signatureLoading.value = false
  }
}

const openApprovalHistory = async (
  item: PurchaseRequestItem,
): Promise<void> => {
  if (!item?.public_id) {
    showErrorToast({
      title: 'Error',
      text: 'Public ID Purchase Requisition tidak ditemukan.',
    })

    return
  }

  try {
    showLoadingAlert(
      'Memuat history approval...',
      'Mohon tunggu sebentar',
    )

    const response = await axios.get(
      `/transaction/purchase-request/${encodeURIComponent(item.public_id)}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    const data = response.data?.data ?? {}

    selectedPRNomor.value = data.nomor_pr
      ?? item.nomor_pr
      ?? '-'

    selectedApprovalHistory.value = Array.isArray(data.approvals)
      ? data.approvals
      : []

    isApprovalHistoryDialogOpen.value = true
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal memuat history approval Purchase Requisition.',
      ),
    })
  }
}

const printPurchaseRequisition = async (
  publicId: string,
): Promise<void> => {
  if (!publicId || printLoadingId.value)
    return

  printLoadingId.value = publicId

  try {
    showLoadingAlert(
      'Membuka cetakan Purchase Requisition...',
      'Mohon tunggu sebentar',
    )

    const response = await axios.get(
      `/transaction/purchase-request/${encodeURIComponent(publicId)}/print`,
      {
        responseType: 'blob',
        headers: {
          Accept: 'application/pdf',
        },
      },
    )

    const file = new Blob(
      [response.data],
      {
        type: 'application/pdf',
      },
    )

    const fileURL = URL.createObjectURL(file)

    closeAlert()

    window.open(
      fileURL,
      '_blank',
      'noopener,noreferrer',
    )

    /*
    |--------------------------------------------------------------------------
    | URL blob jangan langsung dicabut
    |--------------------------------------------------------------------------
    | Beri waktu browser membuka/membaca file.
    |--------------------------------------------------------------------------
    */
    setTimeout(() => {
      URL.revokeObjectURL(fileURL)
    }, 60_000)
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal mencetak Purchase Requisition.',
      ),
    })
  }
  finally {
    printLoadingId.value = null
  }
}

const openApprovePurchaseRequest = async (
  row: PurchaseRequestItem,
): Promise<void> => {
  if (!canApprovePurchaseRequest(row))
    return

  selectedPr.value = row
  pendingAction.value = 'approve'

  try {
    const hasSignature = await checkUserSignature()

    if (!hasSignature) {
      await openSignatureDialog()

      return
    }

    /*
    |--------------------------------------------------------------------------
    | Tanda tangan sudah tersedia
    |--------------------------------------------------------------------------
    | Tampilkan dialog approve + catatan opsional.
    | Tidak langsung menjalankan API approve.
    |--------------------------------------------------------------------------
    */
    showApprovePurchaseRequestDialog()
  }
  catch (error: unknown) {
    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal memeriksa tanda tangan digital.',
      ),
    })
  }
}

const approvePurchaseRequest = async (): Promise<void> => {
  if (
    !selectedPr.value
    || approveLoading.value
  ) {
    return
  }

  const target = {
    ...selectedPr.value,
  }

  /*
  |--------------------------------------------------------------------------
  | Catatan approval bersifat opsional
  |--------------------------------------------------------------------------
  */
  const notes = approveNotes.value.trim() || null

  /*
  |--------------------------------------------------------------------------
  | Dialog ini sekaligus menjadi konfirmasi
  |--------------------------------------------------------------------------
  | Tidak ada SweetAlert konfirmasi kedua.
  |--------------------------------------------------------------------------
  */
  approveDialog.value = false

  await nextTick()

  approveLoading.value = true

  try {
    showLoadingAlert(
      'Approve Purchase Requisition...',
      'Mohon tunggu sebentar',
    )

    const response = await axios.patch(
      `/transaction/purchase-request/${encodeURIComponent(target.public_id)}/approve`,
      {
        notes,
      },
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        || `Purchase Requisition "${target.nomor_pr || '-'}" berhasil diapprove.`,
    })

    approveNotes.value = ''
    selectedPr.value = null
    pendingAction.value = null

    await fetchPurchaseRequests()
  }
  catch (error: unknown) {
    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Jika API gagal
    |--------------------------------------------------------------------------
    | Dialog dibuka kembali dan catatan tidak dihapus.
    |--------------------------------------------------------------------------
    */
    approveDialog.value = true

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal approve Purchase Requisition.',
      ),
    })
  }
  finally {
    approveLoading.value = false
  }
}

const showApprovePurchaseRequestDialog = (): void => {
  if (!selectedPr.value)
    return

  approveNotes.value = ''
  approveDialog.value = true
}

const openRejectPurchaseRequest = (
  row: PurchaseRequestItem,
): void => {
  if (!canApprovePurchaseRequest(row))
    return

  rejectTarget.value = row
  rejectNotes.value = ''
  rejectDialog.value = true
}

const rejectPurchaseRequisition = async (): Promise<void> => {
  if (
    !rejectTarget.value
    || rejectLoading.value
  ) {
    return
  }

  const target = {
    ...rejectTarget.value,
  }

  const notes = rejectNotes.value.trim() || null

  /*
  |--------------------------------------------------------------------------
  | Tutup dialog agar SweetAlert tampil di atas
  |--------------------------------------------------------------------------
  */
  rejectDialog.value = false

  await nextTick()

  const confirm = await showConfirmAlert({
    title: 'Reject Purchase Requisition?',
    text: `Purchase Requisition "${target.nomor_pr || '-'}" akan ditolak.`,
    confirmButtonText: 'Ya, reject',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    /*
    |--------------------------------------------------------------------------
    | Buka kembali agar catatan tidak hilang
    |--------------------------------------------------------------------------
    */
    rejectDialog.value = true

    return
  }

  rejectLoading.value = true

  try {
    showLoadingAlert(
      'Reject Purchase Requisition...',
      'Mohon tunggu sebentar',
    )

    const response = await axios.patch(
      `/transaction/purchase-request/${encodeURIComponent(target.public_id)}/reject`,
      {
        notes,
      },
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    rejectNotes.value = ''
    rejectTarget.value = null

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message
        || `Purchase Requisition "${target.nomor_pr || '-'}" berhasil direject.`,
    })

    await fetchPurchaseRequests()
  }
  catch (error: unknown) {
    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Kalau gagal, tampilkan kembali dialog catatan
    |--------------------------------------------------------------------------
    */
    rejectDialog.value = true

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal reject Purchase Requisition.',
      ),
    })
  }
  finally {
    rejectLoading.value = false
  }
}

const canCreatePurchaseRequest = computed(() => {
  return abilities.value.can_create
})

const canUpdatePurchaseRequest = computed(() => {
  return abilities.value.can_update
})

const canDeletePurchaseRequest = computed(() => {
  return abilities.value.can_delete
})

const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Draft', value: 'Draft' },
  { title: 'In Progress', value: 'In Progress' },
  { title: 'Approved', value: 'Approved' },
  { title: 'Rejected', value: 'Rejected' },
]

const statusPOItems = [
  { title: 'Semua', value: '' },
  { title: 'Open', value: 'Open' },
  { title: 'Partial PO', value: 'Partial' },
  { title: 'Completed', value: 'Completed' },
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

const formatStatusPO = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toUpperCase()

  if (normalized === 'OPEN') return 'Open'
  if (normalized === 'PARTIAL') return 'Partial PO'
  if (normalized === 'COMPLETED') return 'Completed'

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
      '[Purchase Requisition] FETCH ERROR:',
      err,
    )

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal memuat data purchase requisition',
      ),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

const handlePurchaseRequestRefresh = async (): Promise<void> => {
  await fetchPurchaseRequests()
}

usePolling(fetchPurchaseRequests, {
  interval: 30000,
})

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = ''
  selectedStatusPO.value = ''
  tanggalMulai.value = null
  tanggalSelesai.value = null
  currentPage.value = 1

  await fetchPurchaseRequests()
}

const openSubmitPurchaseRequest = async (
  row: PurchaseRequestItem,
): Promise<void> => {
  if (!row?.public_id)
    return

  selectedPr.value = row
  pendingAction.value = 'submit'

  try {
    const hasSignature = await checkUserSignature()

    if (!hasSignature) {
      await openSignatureDialog()

      return
    }

    await submitPurchaseRequest(row)
  }
  catch (error: unknown) {
    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal memeriksa tanda tangan digital.',
      ),
    })
  }
}

const submitPurchaseRequest = async (row: any): Promise<void> => {
  if (!row?.public_id) return

  const confirm = await showConfirmAlert({
    title: 'Submit Purchase Requisition?',
    text: `${row.nomor_pr} akan dikirim untuk proses approval`,
    confirmButtonText: 'Ya, submit',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Submit Purchase Requisition...', 'Mohon tunggu sebentar')

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
      text: response.data?.message || 'Purchase Requisition berhasil disubmit.',
    })

    await fetchPurchaseRequests()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal submit Purchase Requisition.'),
    })
  }
}

const goToCreate = (): void => {
  router.push('/non_trade/purchase_request/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/non_trade/purchase_request/edit?id=${publicId}`)
}

const { openDeleteConfirm } = useDeleteConfirm()

const openDelete = async (row: any): Promise<void> => {
  if (String(row.status || '').toUpperCase() !== 'DRAFT') {
    showErrorToast({
      title: 'Tidak dapat dihapus',
      text: 'Purchase Requisition hanya dapat dihapus jika status masih DRAFT.',
    })

    return
  }

  const nomorPr = row.nomor_pr || row.nomor_po || '-'

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus Purchase Requisition?',
    html: `Apakah Anda yakin ingin menghapus Purchase Requisition <strong>${nomorPr}</strong>?`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Menghapus Purchase Requisition...', 'Mohon tunggu sebentar.')

    const response = await axios.delete(
      `/transaction/purchase-request/${encodeURIComponent(row.public_id)}`,
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
        text: `Purchase Requisition "${nomorPr}" berhasil dihapus`,
      })

      await fetchPurchaseRequests()

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: response.data?.message || 'Gagal menghapus Purchase Requisition',
    })
  } catch (error: any) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message || 'Gagal menghapus Purchase Requisition',
    })
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
      throw new Error('Data purchase requisition tidak ditemukan')
    }

    detailPurchaseRequest.value = detail

    await nextTick()

    const selectedIndex = detailVendors.value.findIndex(v => v.is_selected)
    openedVendorPanels.value = selectedIndex >= 0 ? [selectedIndex] : []
  } catch (error: unknown) {
    const err = error as AxiosErrorShape

    detailError.value = getApiErrorMessage(
      err,
      'Gagal memuat detail purchase requisition',
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
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false


  await fetchPurchaseRequests()

  const success = route.query.success

  window.addEventListener('purchase-order:refresh', handlePurchaseRequestRefresh)

  window.addEventListener(
    'resize',
    resizeSignatureCanvas,
  )

  if (success) {
    await router.replace({
      path: '/non_trade/purchase_request',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Purchase Requisition berhasil disimpan.',
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: 'Berhasil',
          text: 'Purchase Requisition berhasil diperbarui.',
        })
      }
    }, 300)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('purchase-request:refresh', handlePurchaseRequestRefresh)
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
              placeholder="Cari purchase requisition..."
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="4">
            <AppDateTimePicker
              v-model="tanggalMulai"
              label="Tanggal Awal"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d' }"
            />
          </VCol>

          <VCol cols="12" md="4">
            <AppDateTimePicker
              v-model="tanggalSelesai"
              label="Tanggal Akhir"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d' }"
            />
          </VCol>

          <!-- <VCol cols="12" md="4">
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
          </VCol> -->
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
              color="secondary"
              prepend-icon="tabler-refresh"
              @click="resetFilters"
              block
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
        <VBtn
          v-if="canCreatePurchaseRequest"
          color="primary"
          prepend-icon="tabler-plus"
          class="text-none"
          @click="goToCreate"
        >
          Tambah Purchase Requisition
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
            <th scope="col">No</th>
            <th scope="col">Nomor PR</th>
            <th scope="col">Tanggal</th>
            <th scope="col">Cabang</th>
            <th scope="col">Department</th>
            <th scope="col">Status Pengajuan</th>
            <th scope="col">Status PO</th>
            <th scope="col" class="text-center" style="width: 5rem;">Actions</th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(v, index) in rows"
            :key="v.id"
            :class="{
              'pr-row-need-approval':
                canApprovePurchaseRequest(v),
            }"
          >
            <td class="text-medium-emphasis">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>
            <td>
              <div class="d-flex flex-column gap-1">
                <div class="font-weight-medium">
                  {{ v.nomor_pr || '-' }}
                </div>

                <VChip
                  v-if="canApprovePurchaseRequest(v)"
                  size="x-small"
                  color="warning"
                  variant="tonal"
                  class="pr-approval-chip"
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
                      href="javascript:void(0)"
                      @click="openApprovalHistory(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-history"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        History Approval
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() == 'approved' && String(v.status).toLowerCase() !== 'rejected'"
                      href="javascript:void(0)"
                      :disabled="printLoadingId === v.public_id"
                      @click="printPurchaseRequisition(v.public_id)"
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
                        {{
                          printLoadingId === v.public_id
                            ? 'Membuka...'
                            : 'Cetak'
                        }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePurchaseRequest(v)"
                      href="javascript:void(0)"
                      :disabled="approveLoading"
                      @click="openApprovePurchaseRequest(v)"
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
                      v-if="canApprovePurchaseRequest(v)"
                      href="javascript:void(0)"
                      :disabled="rejectLoading"
                      @click="openRejectPurchaseRequest(v)"
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
                      @click="openSubmitPurchaseRequest(v)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-send-outline" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>Submit</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="
                        String(v.status).toLowerCase() === 'draft'
                          && canUpdatePurchaseRequest
                      "
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
                      v-if="
                        String(v.status).toLowerCase() === 'draft'
                          && canDeletePurchaseRequest
                      "
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
                Detail Purchase Requisition
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
              Memuat detail purchase requisition...
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
                          Purchase Requisition
                        </div>
                        <div class="text-h6 font-weight-bold">
                          {{ detailPurchaseRequest.nomor_pr || '-' }}
                        </div>
                      </div>

                      <!-- <VChip
                        size="small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="tabler-calendar"
                      >
                        {{ formatDate(detailPurchaseRequest.tanggal_pr) || '-' }}
                      </VChip> -->
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
                          <div class="info-label">Tipe</div>
                          <div class="info-value">{{ detailPurchaseRequest.pr_type || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Dibuat Oleh</div>
                          <div class="info-value">
                            {{ detailPurchaseRequest.created_by_name || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Dibuat Pada</div>
                          <div class="info-value">
                            {{ formatDate(detailPurchaseRequest.created_at) }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Disubmit Oleh</div>
                          <div class="info-value">
                            {{ detailPurchaseRequest.submitted_by_name || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Disubmit Pada</div>
                          <div class="info-value">
                            {{ formatDate(detailPurchaseRequest.submitted_at) }}
                          </div>
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
                      Lampiran Purchase Requisition
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
                  Tidak ada lampiran purchase requisition.
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
                      Daftar Item Purchase Requisition
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
                          <div class="text-wrap-cell text-pre-line">
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
    >
      <VCard class="signature-dialog-card">
        <VCardTitle class="d-flex align-center gap-3 px-6 py-5">
          <VAvatar
            color="primary"
            variant="tonal"
            size="42"
          >
            <VIcon icon="tabler-signature" />
          </VAvatar>

          <div>
            <div class="text-h6 font-weight-bold">
              Registrasi Tanda Tangan Digital
            </div>

            <div class="text-caption text-medium-emphasis">
              Tanda tangan dibutuhkan untuk proses approval.
            </div>
          </div>
        </VCardTitle>

        <VDivider />

        <VCardText class="pa-6">
          <VAlert
            type="info"
            variant="tonal"
            class="mb-5"
          >
            Anda belum memiliki tanda tangan digital.
            Silakan buat tanda tangan terlebih dahulu untuk melanjutkan approval.
          </VAlert>

          <div class="signature-wrapper">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-subtitle-2 font-weight-medium">
                Tanda tangan
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

            <canvas
              ref="signatureCanvasRef"
              class="signature-canvas"
            />
          </div>

          <div class="signature-agreement">
            <VCheckbox
              v-model="signatureAgree"
              density="compact"
              hide-details
              :disabled="signatureLoading"
            />

            <span>
              Saya menyetujui penggunaan tanda tangan digital ini sebagai identitas persetujuan saya pada dokumen dan transaksi yang memerlukan proses approval di sistem.
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

          Reject Purchase Requisition
        </VCardTitle>

        <VDivider />

        <VCardText>
          <p class="text-body-2 mb-4">
            Anda akan menolak Purchase Requisition:

            <strong>
              {{ rejectTarget?.nomor_pr || '-' }}
            </strong>
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
            Catatan bersifat opsional, namun disarankan diisi agar requester mengetahui alasan penolakan.
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
            @click="rejectPurchaseRequisition"
          >
            Reject
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="approveDialog"
      max-width="560"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="success"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-circle-check" />
            </VAvatar>
          </template>

          <VCardTitle>
            Approve Purchase Requisition?
          </VCardTitle>

          <VCardSubtitle>
            Konfirmasi persetujuan Purchase Requisition
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText class="pt-5">
          <VAlert
            color="success"
            variant="tonal"
            icon="tabler-info-circle"
            class="mb-5"
          >
            Purchase Requisition
            <strong>
              "{{ selectedPr?.nomor_pr || '-' }}"
            </strong>
            akan disetujui.
          </VAlert>

          <VTextarea
            v-model="approveNotes"
            label="Catatan Approval"
            placeholder="Tambahkan catatan bila diperlukan..."
            variant="outlined"
            rows="4"
            auto-grow
            counter="2000"
            maxlength="2000"
            :disabled="approveLoading"
            hint="Catatan bersifat opsional."
            persistent-hint
          />
        </VCardText>

        <VDivider />

        <VCardActions class="px-6 py-4">
          <VSpacer />

          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="approveLoading"
            @click="approveDialog = false"
          >
            Batal
          </VBtn>

          <VBtn
            color="success"
            prepend-icon="tabler-circle-check"
            :loading="approveLoading"
            @click="approvePurchaseRequest"
          >
            Ya, Approve
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ApprovalHistoryDialog
      v-model="isApprovalHistoryDialogOpen"
      :nomor-pr="selectedPRNomor"
      :approvals="selectedApprovalHistory"
    />

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
  width: 250px;
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

.pr-row-need-approval {
  background: rgba(var(--v-theme-warning), 0.055);

  &:hover {
    background: rgba(var(--v-theme-warning), 0.09);
  }
}

.pr-approval-chip {
  width: fit-content;
}

.signature-dialog-card {
  border-radius: 12px !important;
}

.signature-wrapper {
  padding: 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.18);
  border-radius: 12px;
  background: rgba(var(--v-theme-surface), 1);
}

.signature-canvas {
  display: block;
  width: 100%;
  height: 220px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.28);
  border-radius: 10px;
  background: #fff;
  cursor: crosshair;
  touch-action: none;
}

.signature-agreement {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-block-start: 18px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  font-size: 13px;
  line-height: 1.5;
}

.signature-error {
  margin-block-start: 12px;
  color: rgb(var(--v-theme-error));
  font-size: 13px;
}

.signature-footer {
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 24px;
}
</style>
