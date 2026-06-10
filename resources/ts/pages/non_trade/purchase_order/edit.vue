<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from '@axios'
import {
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showWarningToast,
  closeAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import {
  formatNumberWithoutRp,
  formatDate,
  sanitizeDecimalInput,
  parseDecimalInput,
  formatDecimalQty,
  toTitleCase,
} from '@/utils/textFormatter'

interface PurchaseOrderForm {
  tanggal_po: string
  vendor_id: number | null
  vendor_name: string
  cabang: number | null
  id_department: number | null
  jenis_pembayaran: string
  top: number | null
  notes: string
  purchase_request_ids: number[]
}

interface VendorOption {
  id: number
  id_department?: number | null
  nama_vendor: string
  jenis_pembayaran?: string | null
  top?: number | null
  status_pkp?: string | null
}

interface PurchaseRequestOption {
  id: number
  public_id: string
  nomor_pr: string
  tanggal_pr: string
  cabang: string
  department: string
  total_amount: number
  items?: PurchaseOrderItem[]
  recommended_vendor_id?: number | null
  attachments?: Array<{
    id: number
    filename?: string
    original_filename?: string
    filepath?: string
    file_size?: number
    mime_type?: string
  }>
  recommended_vendor?: {
    id: number
    nama_vendor: string
    status_pkp?: string | null
    jenis_pembayaran?: string | null
    top?: number | null
  } | null
}

interface PurchaseOrderItem {
  purchase_request_id: number
  purchase_request_item_id: number
  nomor_pr: string
  nama_item: string
  qty_pr: number
  qty_po_existing: number
  qty_outstanding: number
  qty: number
  satuan_id: number
  satuan: string
  keterangan: string
  harga_unit: number
  subtotal: number
  is_selected: boolean
}

interface POItemState {
  is_selected: boolean
  qty: number
}

const route = useRoute()
const router = useRouter()

const publicId = computed(() => String(route.query.id || ''))

const isSubmitted = ref(false)
const isSaving = ref(false)
const isLoadingDetail = ref(true)
const isInitialLoaded = ref(false)
const loadError = ref('')

const prPage = ref(1)
const prPerPage = ref<number | 'ALL'>(5)

const vendorList = ref<VendorOption[]>([])
const cabangList = ref<any[]>([])
const departmentList = ref<any[]>([])
const purchaseRequestList = ref<PurchaseRequestOption[]>([])
const poItems = ref<PurchaseOrderItem[]>([])
const visibleAttachmentMap = ref<Record<number, number>>({})

const existingPOItemMap = ref<Record<number, PurchaseOrderItem>>({})
const existingPOItemCompositeMap = ref<Record<string, PurchaseOrderItem>>({})
const poItemStateMap = ref<Record<number, POItemState>>({})
const initialPurchaseRequestIds = ref<number[]>([])
const previousSelectedPurchaseRequestIds = ref<number[]>([])

const isLoadingVendor = ref(false)
const isLoadingCabang = ref(false)
const isLoadingDepartment = ref(false)
const isLoadingPR = ref(false)

const form = reactive<PurchaseOrderForm>({
  tanggal_po: '',
  vendor_id: null,
  vendor_name: '',
  cabang: null,
  id_department: null,
  jenis_pembayaran: '',
  top: null,
  notes: '',
  purchase_request_ids: [],
})

const tanggalPO = useNativeDatePicker(toRef(form, 'tanggal_po'))

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
}

const normalizeText = (value: unknown): string => {
  return String(value || '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, ' ')
}

const getCompositeItemKey = (
  purchaseRequestId: number,
  namaItem: string,
): string => {
  return `${Number(purchaseRequestId || 0)}::${normalizeText(namaItem)}`
}

const getPRItemMergeKey = (item: any): string => {
  const id = Number(
    item.purchase_request_item_id
    ?? item.id
    ?? item.purchase_request_item?.id
    ?? item.purchaseRequestItem?.id
    ?? 0,
  )

  if (id) return `ID:${id}`

  return `NAME:${normalizeText(item.nama_item)}`
}

const mergePRItems = (
  existingItems: any[] = [],
  incomingItems: any[] = [],
): any[] => {
  const map = new Map<string, any>()

  existingItems.forEach(item => {
    map.set(getPRItemMergeKey(item), item)
  })

  incomingItems.forEach(item => {
    const key = getPRItemMergeKey(item)
    const existing = map.get(key)

    map.set(key, {
      ...existing,
      ...item,
    })
  })

  return Array.from(map.values())
}

const isCreditPayment = computed(() => {
  return String(form.jenis_pembayaran || '').toUpperCase() === 'TOP'
})

const selectedVendorStatusPKP = computed(() => {
  const vendor = vendorList.value.find(item => Number(item.id) === Number(form.vendor_id))

  return vendor?.status_pkp || 'NON_PKP'
})

const isVendorPKP = computed(() => {
  return String(selectedVendorStatusPKP.value).toUpperCase() === 'PKP'
})

const selectedPOItems = computed(() => {
  return poItems.value.filter(item => item.is_selected !== false)
})

const subtotal = computed(() => {
  return selectedPOItems.value.reduce((total, item) => {
    return total + (Number(item.qty || 0) * Number(item.harga_unit || 0))
  }, 0)
})

const dpp = computed(() => {
  return isVendorPKP.value ? (subtotal.value * 11) / 12 : 0
})

const ppn = computed(() => {
  return isVendorPKP.value ? Math.round(dpp.value * 0.12) : 0
})

const grandTotal = computed(() => {
  return isVendorPKP.value ? subtotal.value + ppn.value : subtotal.value
})

const prPerPageItems = [
  { title: '5', value: 5 },
  { title: '10', value: 10 },
  { title: '25', value: 25 },
  { title: '50', value: 50 },
  { title: 'All', value: 'ALL' },
]

const paginatedPurchaseRequests = computed(() => {
  if (prPerPage.value === 'ALL') return purchaseRequestList.value

  const start = (prPage.value - 1) * Number(prPerPage.value)
  const end = start + Number(prPerPage.value)

  return purchaseRequestList.value.slice(start, end)
})

const prTotalPage = computed(() => {
  if (prPerPage.value === 'ALL') return 1

  return Math.ceil(purchaseRequestList.value.length / Number(prPerPage.value)) || 1
})

const isAllSelected = computed(() => {
  if (!purchaseRequestList.value.length) return false

  return purchaseRequestList.value.every(pr =>
    form.purchase_request_ids.includes(pr.id),
  )
})

const groupedPOItems = computed(() => {
  const groups = new Map<string, PurchaseOrderItem[]>()

  poItems.value.forEach(item => {
    const key = item.nomor_pr || '-'

    if (!groups.has(key)) {
      groups.set(key, [])
    }

    groups.get(key)?.push(item)
  })

  return Array.from(groups.entries()).map(([nomor_pr, items]) => ({
    nomor_pr,
    items,
  }))
})

const selectedRecommendedVendors = computed(() => {
  const selectedPRs = purchaseRequestList.value.filter(pr =>
    form.purchase_request_ids.includes(pr.id),
  )

  const vendors = selectedPRs
    .map(pr => pr.recommended_vendor)
    .filter(Boolean) as any[]

  const unique = new Map<number, any>()

  vendors.forEach(vendor => {
    unique.set(Number(vendor.id), vendor)
  })

  return Array.from(unique.values())
})

const mergePurchaseRequests = (incoming: PurchaseRequestOption[]): void => {
  const map = new Map<number, PurchaseRequestOption>()

  purchaseRequestList.value.forEach(pr => {
    map.set(Number(pr.id), pr)
  })

  incoming.forEach(pr => {
    const prId = Number(pr.id)
    const existing = map.get(prId)

    const existingItems = Array.isArray(existing?.items)
      ? existing.items
      : []

    const incomingItems = Array.isArray(pr.items)
      ? pr.items
      : []

    const mergedItems = mergePRItems(existingItems, incomingItems)

    map.set(prId, {
      ...existing,
      ...pr,

      /*
      |--------------------------------------------------------------------------
      | PENTING
      |--------------------------------------------------------------------------
      | Jangan replace items mentah-mentah.
      | Karena dropdown-approved bisa tidak membawa item yang sudah fully masuk PO.
      |--------------------------------------------------------------------------
      */
      items: mergedItems,
    })
  })

  purchaseRequestList.value = Array.from(map.values())
}

const captureCurrentPOItemState = (): void => {
  poItems.value.forEach(item => {
    const key = Number(item.purchase_request_item_id)

    if (!key) return

    poItemStateMap.value[key] = {
      is_selected: item.is_selected !== false,
      qty: Number(item.qty || 0),
    }
  })
}

const getSavedPOItemState = (purchaseRequestItemId: number): POItemState | null => {
  return poItemStateMap.value[Number(purchaseRequestItemId)] || null
}

const getVisibleAttachmentCount = (prId: number): number => {
  return visibleAttachmentMap.value[prId] || 1
}

const visibleAttachments = (pr: PurchaseRequestOption) => {
  const attachments = pr.attachments || []
  const count = getVisibleAttachmentCount(pr.id)

  return attachments.slice(0, count)
}

const hasMoreAttachments = (pr: PurchaseRequestOption): boolean => {
  const attachments = pr.attachments || []

  return getVisibleAttachmentCount(pr.id) < attachments.length
}

const showMoreAttachments = (pr: PurchaseRequestOption): void => {
  visibleAttachmentMap.value[pr.id] = getVisibleAttachmentCount(pr.id) + 5
}

const showLessAttachments = (pr: PurchaseRequestOption): void => {
  visibleAttachmentMap.value[pr.id] = 1
}

const loadVendors = async (showAlert = true): Promise<void> => {
  isLoadingVendor.value = true

  try {
    const res = await axios.get('/master/vendor/dropdown-select', {
      headers: { Accept: 'application/json' },
      params: {
        id_department: form.id_department,
      },
    })

    const data = Array.isArray(res.data?.data) ? res.data.data : []

    vendorList.value = data.map((item: any) => ({
      id: Number(item.id),
      id_department: item.id_department ? Number(item.id_department) : null,
      nama_vendor: item.nama_vendor || item.title || '-',
      jenis_pembayaran: item.jenis_pembayaran || null,
      top: item.top ? Number(item.top) : null,
      status_pkp: item.status_pkp || 'NON_PKP',
    }))
  } catch (error: unknown) {
    vendorList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data vendor'),
      })
    }
  } finally {
    isLoadingVendor.value = false
  }
}

const fetchCabangList = async (showAlert = true): Promise<void> => {
  isLoadingCabang.value = true

  try {
    const response = await axios.get('/master/cabang/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    cabangList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          title: `${item.inisial_cabang || '-'} - ${item.nama_cabang || item.title || '-'}`,
          nama: item.nama_cabang || item.title || '-',
          inisial_cabang: item.inisial_cabang || '',
        }))
      : []
  } catch (error: unknown) {
    cabangList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data cabang'),
      })
    }
  } finally {
    isLoadingCabang.value = false
  }
}

const fetchDepartmentList = async (showAlert = true): Promise<void> => {
  isLoadingDepartment.value = true

  try {
    const response = await axios.get('/master/department/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    departmentList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          kode: item.kode || '',
          nama: item.nama || item.title || '-',
          label: `${item.kode || '-'} - ${item.nama || item.title || '-'}`,
        }))
      : []
  } catch (error: unknown) {
    departmentList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data department'),
      })
    }
  } finally {
    isLoadingDepartment.value = false
  }
}

const ensureSelectedVendorExists = (detail: any): void => {
  const vendorId = Number(
    detail?.vendor_data?.vendor_id
    ?? detail?.vendor_data?.id
    ?? detail?.vendor_id
    ?? 0,
  )

  if (!vendorId) return

  const exists = vendorList.value.some(item => Number(item.id) === vendorId)

  if (exists) return

  vendorList.value.unshift({
    id: vendorId,
    id_department: detail?.department_id ? Number(detail.department_id) : null,
    nama_vendor: detail?.vendor_data?.nama_vendor ?? detail?.vendor ?? `Vendor #${vendorId}`,
    jenis_pembayaran: detail?.vendor_data?.jenis_pembayaran ?? detail?.jenis_pembayaran ?? null,
    top: detail?.vendor_data?.top ? Number(detail.vendor_data.top) : null,
    status_pkp: detail?.vendor_data?.status_pkp ?? detail?.status_pkp ?? 'NON_PKP',
  })
}

const handleSelectVendor = (): void => {
  const vendor = vendorList.value.find(item => Number(item.id) === Number(form.vendor_id))

  if (!vendor) {
    form.vendor_name = ''
    form.jenis_pembayaran = ''
    form.top = null

    return
  }

  form.vendor_id = Number(vendor.id)
  form.vendor_name = vendor.nama_vendor || ''
  form.jenis_pembayaran = vendor.jenis_pembayaran || ''
  form.top = vendor.top ?? null
}

const loadPurchaseRequestsByFilter = async (): Promise<void> => {
  if (!form.cabang || !form.id_department) return

  visibleAttachmentMap.value = {}
  isLoadingPR.value = true

  try {
    const response = await axios.get('/transaction/purchase-request/dropdown-approved', {
      headers: { Accept: 'application/json' },
      params: {
        cabang: form.cabang,
        id_department: form.id_department,
      },
    })

    const rows: PurchaseRequestOption[] = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          public_id: item.public_id,
          nomor_pr: item.nomor_pr,
          tanggal_pr: item.tanggal_pr,
          cabang: item.cabang,
          department: item.department,
          total_amount: Number(item.total_amount || 0),
          recommended_vendor_id: item.recommended_vendor_id
            ? Number(item.recommended_vendor_id)
            : null,
          recommended_vendor: item.recommended_vendor || null,
          items: Array.isArray(item.items) ? item.items : [],
          attachments: Array.isArray(item.attachments) ? item.attachments : [],
        }))
      : []

    mergePurchaseRequests(rows)
  } catch (error: unknown) {
    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat Purchase Request'),
    })
  } finally {
    isLoadingPR.value = false
  }
}

const handleSelectPRFilter = async (): Promise<void> => {
  form.purchase_request_ids = []
  form.vendor_id = null
  form.vendor_name = ''
  form.jenis_pembayaran = ''
  form.top = null
  poItems.value = []
  purchaseRequestList.value = []
  vendorList.value = []
  existingPOItemMap.value = {}
  existingPOItemCompositeMap.value = {}
  poItemStateMap.value = {}
  initialPurchaseRequestIds.value = []
  previousSelectedPurchaseRequestIds.value = []
  prPage.value = 1

  if (form.id_department) {
    await loadVendors(false)
  }

  if (form.cabang && form.id_department) {
    await loadPurchaseRequestsByFilter()
  }
}

const getItemSatuanId = (item: any): number => {
  return Number(
    item.unit?.id
    ?? item.satuan_id
    ?? item.purchase_request_item?.unit?.id
    ?? item.purchase_request_item?.satuan_id
    ?? item.purchaseRequestItem?.unit?.id
    ?? item.purchaseRequestItem?.satuan_id
    ?? item.satuan?.id
    ?? 0,
  )
}

const getItemSatuanName = (item: any): string => {
  return (
    item.unit?.nama
    ?? item.unit?.kode
    ?? item.purchase_request_item?.unit?.nama
    ?? item.purchase_request_item?.unit?.kode
    ?? item.purchaseRequestItem?.unit?.nama
    ?? item.purchaseRequestItem?.unit?.kode
    ?? item.satuan?.nama
    ?? item.satuan?.kode
    ?? item.satuan
    ?? '-'
  )
}

const getPurchaseRequestItemId = (item: any): number => {
  return Number(
    item.purchase_request_item_id
    ?? item.purchase_request_item?.id
    ?? item.purchaseRequestItem?.id
    ?? item.id
    ?? 0,
  )
}

const getPOPurchaseRequestItemId = (item: any): number => {
  return Number(
    item.purchase_request_item?.id
    ?? item.purchaseRequestItem?.id
    ?? item.purchase_request_item_id
    ?? 0,
  )
}

const findExistingPOItem = (
  prItemId: number,
  purchaseRequestId: number,
  namaItem: string,
): PurchaseOrderItem | null => {
  if (prItemId && existingPOItemMap.value[prItemId]) {
    return existingPOItemMap.value[prItemId]
  }

  const compositeKey = getCompositeItemKey(purchaseRequestId, namaItem)

  return existingPOItemCompositeMap.value[compositeKey] || null
}

const togglePOItemSelection = (item: PurchaseOrderItem): void => {
  const isSelected = item.is_selected !== false

  if (!isSelected) {
    item.subtotal = 0
  } else {
    item.subtotal = Number(item.qty || 0) * Number(item.harga_unit || 0)
  }

  poItemStateMap.value[Number(item.purchase_request_item_id)] = {
    is_selected: isSelected,
    qty: Number(item.qty || 0),
  }
}

const handleSelectPurchaseRequest = (): void => {
  captureCurrentPOItemState()

  const previousSelectedSet = new Set(
    previousSelectedPurchaseRequestIds.value.map(id => Number(id)),
  )

  const currentSelectedSet = new Set(
    form.purchase_request_ids.map(id => Number(id)),
  )

  const selectedPRs = purchaseRequestList.value.filter(pr =>
    currentSelectedSet.has(Number(pr.id)),
  )

  const nextItems: PurchaseOrderItem[] = []

  selectedPRs.forEach(pr => {
    const prId = Number(pr.id)
    const prItems = pr.items || []
    const isInitialPR = initialPurchaseRequestIds.value.includes(prId)
    const isNewlySelectedPR = !previousSelectedSet.has(prId)
    const shouldAutoSelectAllItems = isNewlySelectedPR && isInitialLoaded.value

    if (isNewlySelectedPR) {
      prItems.forEach((item: any) => {
        const prItemId = getPurchaseRequestItemId(item)

        if (prItemId) {
          delete poItemStateMap.value[prItemId]
        }
      })

      Object.values(existingPOItemMap.value)
        .filter(item => Number(item.purchase_request_id) === prId)
        .forEach(item => {
          delete poItemStateMap.value[Number(item.purchase_request_item_id)]
        })
    }

    prItems.forEach((item: any) => {
      const prItemId = getPurchaseRequestItemId(item)
      const namaItem = item.nama_item || '-'
      const existingItem = findExistingPOItem(prItemId, prId, namaItem)
      const effectivePrItemId = prItemId || Number(existingItem?.purchase_request_item_id || 0)

      if (!effectivePrItemId) return

      const savedState = getSavedPOItemState(effectivePrItemId)

      const qtyOutstandingRaw = Number(item.qty_outstanding ?? item.qty ?? 0)
      const hargaUnit = Number(item.harga_unit || existingItem?.harga_unit || 0)

      if (existingItem) {
        const qty = savedState
          ? Number(savedState.qty || 0)
          : Number(existingItem.qty || 0)

        const isSelected = shouldAutoSelectAllItems
        ? true
        : savedState
          ? savedState.is_selected !== false
          : true

        nextItems.push({
          ...existingItem,
          purchase_request_id: prId,
          purchase_request_item_id: effectivePrItemId,
          nomor_pr: pr.nomor_pr || existingItem.nomor_pr,
          nama_item: existingItem.nama_item || namaItem,
          is_selected: isSelected,
          qty,
          satuan_id: Number(existingItem.satuan_id || item.satuan_id || item.satuan?.id || item.unit?.id || 0),
          satuan: existingItem.satuan || item.satuan?.nama || item.satuan?.kode || item.unit?.nama || item.unit?.kode || item.satuan || '-',
          subtotal: isSelected
            ? Number(qty || 0) * Number(existingItem.harga_unit || hargaUnit || 0)
            : 0,
        })

        return
      }

      if (qtyOutstandingRaw <= 0) return

      const savedQty = savedState
        ? Number(savedState.qty || 0)
        : qtyOutstandingRaw

      const defaultSelected = shouldAutoSelectAllItems
        ? true
        : isInitialPR
          ? false
          : true

      const isSelected = shouldAutoSelectAllItems
        ? true
        : savedState
          ? savedState.is_selected !== false
          : defaultSelected

      nextItems.push({
        purchase_request_id: prId,
        purchase_request_item_id: effectivePrItemId,
        nomor_pr: pr.nomor_pr,
        nama_item: namaItem,
        qty_pr: Number(item.qty || 0),
        qty_po_existing: Number(item.qty_po || 0),
        qty_outstanding: qtyOutstandingRaw,
        qty: savedQty,
        satuan_id: Number(item.satuan_id ?? item.satuan?.id ?? item.unit?.id ?? 0),
        satuan: item.satuan?.nama || item.satuan?.kode || item.unit?.nama || item.unit?.kode || item.satuan || '-',
        keterangan: item.keterangan || '-',
        harga_unit: hargaUnit,
        subtotal: isSelected ? Number(savedQty || 0) * hargaUnit : 0,
        is_selected: isSelected,
      })
    })

    if (!prItems.length) {
      Object.values(existingPOItemMap.value)
        .filter(item => Number(item.purchase_request_id) === prId)
        .forEach(existingItem => {
          const savedState = getSavedPOItemState(existingItem.purchase_request_item_id)

          const qty = savedState
            ? Number(savedState.qty || 0)
            : Number(existingItem.qty || 0)

          const isSelected = shouldAutoSelectAllItems
          ? true
          : savedState
            ? savedState.is_selected !== false
            : true

          nextItems.push({
            ...existingItem,
            is_selected: isSelected,
            qty,
            satuan_id: Number(existingItem.satuan_id || 0),
            satuan: existingItem.satuan || '-',
            subtotal: isSelected
              ? Number(qty || 0) * Number(existingItem.harga_unit || 0)
              : 0,
          })
        })
    }
  })

  poItems.value = nextItems

  previousSelectedPurchaseRequestIds.value = form.purchase_request_ids.map(id => Number(id))
}

const toggleSelectAllPR = async (value: boolean | null): Promise<void> => {
  captureCurrentPOItemState()

  if (Boolean(value)) {
    form.purchase_request_ids = purchaseRequestList.value.map(pr => pr.id)
  } else {
    form.purchase_request_ids = []
  }

  handleSelectPurchaseRequest()
}

const handlePOQtyInput = (value: string | number, index: number): void => {
  const item = poItems.value[index]
  if (!item) return

  const sanitized = sanitizeDecimalInput(value, {
    maxIntegerLength: 12,
    maxDecimalLength: 2,
  })

  const qty = parseDecimalInput(sanitized)
  const maxQty = Number(item.qty_outstanding || 0)

  if (qty > maxQty) {
    item.qty = maxQty

    showWarningToast({
      title: 'Qty melebihi outstanding',
      text: `Qty PO untuk item "${item.nama_item}" maksimal ${formatDecimalQty(maxQty)}.`,
    })
  } else {
    item.qty = qty
  }

  item.subtotal = item.is_selected !== false
    ? Number(item.qty || 0) * Number(item.harga_unit || 0)
    : 0

  poItemStateMap.value[Number(item.purchase_request_item_id)] = {
    is_selected: item.is_selected !== false,
    qty: Number(item.qty || 0),
  }
}

const mapEditDetailToForm = async (detail: any): Promise<void> => {
  form.tanggal_po = detail.tanggal_po || ''
  form.cabang = detail.cabang_id ? Number(detail.cabang_id) : null
  form.id_department = detail.department_id ? Number(detail.department_id) : null
  form.notes = detail.notes || ''

  form.vendor_id = Number(
    detail?.vendor_data?.vendor_id
    ?? detail?.vendor_data?.id
    ?? detail?.vendor_id
    ?? 0,
  ) || null

  form.vendor_name = detail?.vendor_data?.nama_vendor ?? detail?.vendor ?? ''
  form.jenis_pembayaran = detail?.vendor_data?.jenis_pembayaran ?? detail?.jenis_pembayaran ?? ''
  form.top = detail?.vendor_data?.top ? Number(detail.vendor_data.top) : null

  const purchaseRequests = Array.isArray(detail.purchase_requests)
    ? detail.purchase_requests
    : []

  const prMap = new Map<number, any>()

  purchaseRequests.forEach((pr: any) => {
    prMap.set(Number(pr.id), pr)
  })

  const selectedPrIds = purchaseRequests.map((pr: any) => Number(pr.id))

  form.purchase_request_ids = selectedPrIds
  initialPurchaseRequestIds.value = selectedPrIds
  previousSelectedPurchaseRequestIds.value = []

  const existingPRRows: PurchaseRequestOption[] = purchaseRequests.map((pr: any) => ({
    id: Number(pr.id),
    public_id: pr.public_id || '',
    nomor_pr: pr.nomor_pr || '-',
    tanggal_pr: pr.tanggal_pr || detail.tanggal_po || '',
    cabang: detail.cabang || '-',
    department: detail.department || '-',
    total_amount: Number(pr.total_amount || 0),
    recommended_vendor_id: pr.recommended_vendor_id ? Number(pr.recommended_vendor_id) : null,
    recommended_vendor: pr.recommended_vendor || null,
    attachments: Array.isArray(pr.attachments) ? pr.attachments : [],
    items: Array.isArray(pr.items) ? pr.items : [],
  }))

  mergePurchaseRequests(existingPRRows)

  poItems.value = Array.isArray(detail.items)
    ? detail.items.map((item: any) => {
        const prItem = item.purchase_request_item || item.purchaseRequestItem || null

        const purchaseRequestId = Number(
          item.purchase_request_id
          || prItem?.purchase_request_id
          || item.purchase_request?.id
          || selectedPrIds[0]
          || 0,
        )

        const pr = prMap.get(purchaseRequestId)

        const currentQty = Number(item.qty || 0)
        const qtyPr = Number(prItem?.qty_pr || prItem?.qty || item.qty_pr || currentQty)
        const qtyPoFromPR = Number(prItem?.qty_po || item.qty_po || 0)
        const rawOutstanding = Number(prItem?.qty_outstanding || item.qty_outstanding || 0)

        const qtyPoExisting = Math.max(qtyPoFromPR - currentQty, 0)
        const editableOutstanding = rawOutstanding + currentQty
        const hargaUnit = Number(item.harga_unit || 0)
        const satuanId = getItemSatuanId(item)
        const purchaseRequestItemId = getPOPurchaseRequestItemId(item)

        return {
          purchase_request_id: purchaseRequestId,
          purchase_request_item_id: purchaseRequestItemId,
          nomor_pr: item.nomor_pr || pr?.nomor_pr || '-',
          nama_item: item.nama_item || prItem?.nama_item || '-',
          qty_pr: qtyPr,
          qty_po_existing: qtyPoExisting,
          qty_outstanding: editableOutstanding,
          qty: currentQty,
          satuan_id: satuanId,
          satuan: getItemSatuanName(item),
          keterangan: item.keterangan || '-',
          harga_unit: hargaUnit,
          subtotal: currentQty * hargaUnit,
          is_selected: true,
        }
      })
    : []

  existingPOItemMap.value = {}
  existingPOItemCompositeMap.value = {}
  poItemStateMap.value = {}

  poItems.value.forEach(item => {
    const prItemId = Number(item.purchase_request_item_id)
    const compositeKey = getCompositeItemKey(item.purchase_request_id, item.nama_item)

    if (prItemId) {
      existingPOItemMap.value[prItemId] = {
        ...item,
        is_selected: true,
      }

      poItemStateMap.value[prItemId] = {
        is_selected: true,
        qty: Number(item.qty || 0),
      }
    }

    existingPOItemCompositeMap.value[compositeKey] = {
      ...item,
      is_selected: true,
    }
  })

  if (form.id_department) {
    await loadVendors(false)
    ensureSelectedVendorExists(detail)
  }

  if (form.cabang && form.id_department) {
    await loadPurchaseRequestsByFilter()
    handleSelectPurchaseRequest()
  }

  if (form.vendor_id) {
    handleSelectVendor()
  }
}

const loadPurchaseOrderDetail = async (): Promise<void> => {
  if (!publicId.value) {
    loadError.value = 'ID Purchase Order tidak ditemukan.'
    isLoadingDetail.value = false
    return
  }

  isLoadingDetail.value = true
  isInitialLoaded.value = false
  loadError.value = ''

  try {
    const response = await axios.get(`/transaction/purchase-order/${publicId.value}/edit`, {
      headers: { Accept: 'application/json' },
    })

    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data Purchase Order tidak ditemukan.')
    }

    await mapEditDetailToForm(detail)

    isInitialLoaded.value = true
  } catch (error: unknown) {
    loadError.value = getApiErrorMessage(error, 'Gagal memuat detail Purchase Order.')
  } finally {
    isLoadingDetail.value = false
  }
}

const validateForm = async (): Promise<boolean> => {
  if (
    !required(form.vendor_id)
    || !required(form.tanggal_po)
    || !required(form.cabang)
    || !required(form.id_department)
    || !required(form.jenis_pembayaran)
  ) {
    showWarningToast({
      title: 'Warning',
      text: 'Lengkapi data wajib.',
    })

    return false
  }

  if (!form.purchase_request_ids.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Pilih minimal satu Purchase Request.',
    })

    return false
  }

  if (!poItems.value.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Item Purchase Order belum tersedia.',
    })

    return false
  }

  if (!selectedPOItems.value.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Pilih minimal satu item Purchase Order.',
    })

    return false
  }

  const selectedPurchaseRequestsWithoutItem = purchaseRequestList.value.filter(pr => {
    const isPrSelected = form.purchase_request_ids.includes(Number(pr.id))

    if (!isPrSelected) return false

    const hasSelectedItem = poItems.value.some(item => {
      return Number(item.purchase_request_id) === Number(pr.id)
        && item.is_selected !== false
    })

    return !hasSelectedItem
  })

  if (selectedPurchaseRequestsWithoutItem.length > 0) {
    const nomorPrList = selectedPurchaseRequestsWithoutItem
      .map(pr => pr.nomor_pr || '-')
      .join(', ')

    showWarningToast({
      title: 'Warning',
      text: `Setiap PR yang dipilih wajib memiliki minimal 1 item PO. PR tanpa item: ${nomorPrList}`,
    })

    return false
  }

  for (const item of selectedPOItems.value) {
    if (!Number(item.satuan_id || 0)) {
      showErrorToast({
        title: 'Satuan tidak valid',
        text: `Satuan untuk item ${item.nama_item} belum memiliki ID satuan.`,
      })

      return false
    }
  }

  const invalidItemIndex = selectedPOItems.value.findIndex(item =>
    !item.purchase_request_id
    || !item.purchase_request_item_id
    || !item.qty
    || Number(item.qty) <= 0
    || Number(item.qty) > Number(item.qty_outstanding)
    || !item.nama_item
    || !item.satuan
    || Number(item.harga_unit) < 0,
  )

  if (invalidItemIndex !== -1) {
    const item = selectedPOItems.value[invalidItemIndex]

    showWarningToast({
      title: 'Warning',
      text: `Qty PO item "${item.nama_item || '-'}" wajib lebih dari 0 dan tidak boleh melebihi outstanding.`,
    })

    return false
  }

  const itemIds = selectedPOItems.value.map(item => Number(item.purchase_request_item_id))
  const uniqueItemIds = new Set(itemIds)

  if (itemIds.length !== uniqueItemIds.size) {
    showWarningToast({
      title: 'Warning',
      text: 'Terdapat item PR yang duplikat pada Purchase Order.',
    })

    return false
  }

  return true
}

const buildPayload = () => {
  const items = selectedPOItems.value.map(item => {
    const qty = Number(item.qty || 0)
    const hargaUnit = Number(item.harga_unit || 0)

    return {
      purchase_request_id: Number(item.purchase_request_id),
      purchase_request_item_id: Number(item.purchase_request_item_id),
      nama_item: item.nama_item,
      qty,
      satuan: Number(item.satuan_id || 0),
      keterangan: item.keterangan,
      harga_unit: hargaUnit,
      subtotal: qty * hargaUnit,
      qty_pr: Number(item.qty_pr || 0),
      qty_po_existing: Number(item.qty_po_existing || 0),
      qty_outstanding: Number(item.qty_outstanding || 0),
    }
  })

  const purchaseRequestIds = Array.from(
    new Set(items.map(item => Number(item.purchase_request_id))),
  )

  return {
    tanggal_po: form.tanggal_po,
    vendor_id: Number(form.vendor_id),
    cabang: Number(form.cabang),
    id_department: Number(form.id_department),
    jenis_pembayaran: form.jenis_pembayaran,
    top: isCreditPayment.value ? Number(form.top || 0) : null,
    notes: form.notes || '',
    purchase_request_ids: purchaseRequestIds,
    subtotal: Number(subtotal.value || 0),
    dpp: Number(dpp.value || 0),
    ppn: Number(ppn.value || 0),
    total_nilai: Number(grandTotal.value || 0),
    items,
  }
}

const updatePurchaseOrder = async (): Promise<void> => {
  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Update Purchase Order?',
    text: 'Pastikan perubahan purchase order sudah benar.',
    confirmButtonText: 'Ya, update',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Mengupdate data...', 'Mohon tunggu sebentar')

    await axios.put(`/transaction/purchase-order/${publicId.value}`, buildPayload(), {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_trade/purchase_order',
      query: { success: 'updated' },
    })
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memperbarui Purchase Order'),
    })
  } finally {
    isSaving.value = false
  }
}

const goBack = async (): Promise<void> => {
  await router.replace('/non_trade/purchase_order')
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Batalkan?',
    text: 'Data yang sudah diubah tidak akan tersimpan. Apakah Anda yakin?',
    confirmButtonText: 'Ya, batal',
    cancelButtonText: 'Batal',
  })

  if (confirm.isConfirmed) {
    await router.replace('/non_trade/purchase_order')
  }
}

onMounted(async () => {
  isLoadingDetail.value = true
  isInitialLoaded.value = false

  try {
    await Promise.all([
      fetchCabangList(false),
      fetchDepartmentList(false),
    ])

    await loadPurchaseOrderDetail()
  } catch (error: unknown) {
    loadError.value = getApiErrorMessage(error, 'Gagal memuat data Purchase Order.')
    isLoadingDetail.value = false
  }
})
</script>

<template>
  <section>
    <VCard
      v-if="isLoadingDetail"
      class="mb-6 rounded-lg"
      elevation="2"
    >
      <VCardText class="pa-6">
        <div class="d-flex align-center">
          <VProgressCircular
            indeterminate
            color="primary"
            size="28"
            width="3"
            class="me-4"
          />

          <div>
            <div class="text-h6 font-weight-medium">
              Memuat data Purchase Order...
            </div>
            <div class="text-body-2 text-medium-emphasis">
              Mohon tunggu sebentar
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard
      v-else-if="loadError"
      class="mb-6 rounded-lg"
      elevation="3"
    >
      <VCardText class="pa-6">
        <div class="d-flex align-start justify-space-between flex-wrap gap-4">
          <div class="d-flex align-start">
            <VAvatar
              size="44"
              color="error"
              variant="tonal"
              class="me-4"
            >
              <VIcon icon="tabler-alert-circle" size="24" />
            </VAvatar>

            <div>
              <div class="text-h6 font-weight-bold text-error mb-1">
                {{ loadError }}
              </div>

              <div class="text-caption text-disabled mt-2">
                Silakan coba muat ulang data. Jika masalah masih berlanjut, hubungi tim IT.
              </div>
            </div>
          </div>

          <div class="d-flex ga-2 flex-wrap">
            <VBtn
              color="primary"
              :loading="isLoadingDetail"
              prepend-icon="tabler-refresh"
              @click="loadPurchaseOrderDetail"
            >
              Coba Lagi
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-arrow-left"
              @click="goBack"
              class="text-none"
            >
              Kembali
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard v-else-if="isInitialLoaded">
      <VCardTitle class="d-flex align-center justify-space-between">
        <div>
          <div class="text-h6 font-weight-bold">
            Edit Purchase Order
          </div>
          <div class="text-body-2 text-medium-emphasis">
            Perbarui data purchase order dengan benar
          </div>
        </div>

        <VBtn
          prepend-icon="mdi-arrow-left"
          variant="text"
          color="secondary"
          @click="goBack"
          class="text-none"
        >
          Kembali
        </VBtn>
      </VCardTitle>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <AppDateTimePicker
              v-model="form.tanggal_po"
              label="Tanggal PO *"
              placeholder="Pilih tanggal PO"
              :config="{ dateFormat: 'Y-m-d' }"
              :error="isSubmitted && !form.tanggal_po"
              :error-messages="isSubmitted && !form.tanggal_po ? ['Tanggal PO wajib diisi'] : []"
            />
          </VCol>
          <!-- <VCol cols="12" md="6">
            <div class="position-relative">
              <VTextField
                :model-value="tanggalPO.displayValue.value"
                label="Tanggal PO *"
                placeholder="DD/MM/YYYY"
                readonly
                append-inner-icon="tabler-calendar"
                :error="isSubmitted && !form.tanggal_po"
                :error-messages="isSubmitted && !form.tanggal_po ? ['Tanggal PO wajib diisi'] : []"
                @click="tanggalPO.openPicker"
                @click:append-inner="tanggalPO.openPicker"
              />

              <input
                :ref="(el) => {
                  tanggalPO.nativeDateRef.value = el as HTMLInputElement | null
                }"
                type="date"
                :value="form.tanggal_po"
                class="native-date-hidden"
                tabindex="-1"
                aria-hidden="true"
                @change="tanggalPO.onDateChange"
              >
            </div>
          </VCol> -->

          <VCol cols="12" md="6" />

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.cabang"
              label="Cabang *"
              :items="cabangList"
              item-title="title"
              item-value="id"
              clearable
              density="comfortable"
              :loading="isLoadingCabang"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              :error="isSubmitted && !form.cabang"
              :error-messages="isSubmitted && !form.cabang ? ['Cabang wajib dipilih'] : []"
              placeholder="Pilih Cabang"
              @update:model-value="handleSelectPRFilter"
            />
          </VCol>

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.id_department"
              label="Department *"
              :items="departmentList"
              item-title="label"
              item-value="id"
              clearable
              density="comfortable"
              :loading="isLoadingDepartment"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              :error="isSubmitted && !form.id_department"
              :error-messages="isSubmitted && !form.id_department ? ['Department wajib dipilih'] : []"
              placeholder="Pilih Department"
              @update:model-value="handleSelectPRFilter"
            />
          </VCol>

          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-bold mb-3">
              Pilih Purchase Request *
            </div>

            <VAlert
              v-if="!form.cabang || !form.id_department"
              type="info"
              variant="tonal"
            >
              Pilih cabang dan department terlebih dahulu untuk menampilkan Purchase Request.
            </VAlert>

            <div v-else>
              <div class="pr-select-table-wrapper">
                <VTable class="pr-select-table border rounded">
                  <thead>
                    <tr>
                      <th class="text-center col-check">
                        <VCheckbox
                          :model-value="isAllSelected"
                          hide-details
                          density="compact"
                          color="primary"
                          @update:model-value="toggleSelectAllPR"
                        />
                      </th>

                      <th class="col-pr">Nomor PR</th>
                      <th class="col-attachment">Lampiran</th>
                      <th class="text-center col-date">Tanggal</th>
                      <th class="col-cabang">Cabang</th>
                      <th class="col-department">Department</th>
                      <th class="text-end col-total">Total PR</th>
                    </tr>
                  </thead>

                  <tbody>
                    <tr v-if="isLoadingPR">
                      <td colspan="7" class="text-center py-6">
                        Memuat Purchase Request...
                      </td>
                    </tr>

                    <tr v-else-if="!purchaseRequestList.length">
                      <td colspan="7" class="text-center text-medium-emphasis py-6">
                        Tidak ada Purchase Request tersedia untuk cabang dan department ini.
                      </td>
                    </tr>

                    <tr
                      v-for="pr in paginatedPurchaseRequests"
                      v-else
                      :key="pr.id"
                    >
                      <td class="text-center col-check">
                        <VCheckbox
                          v-model="form.purchase_request_ids"
                          :value="pr.id"
                          hide-details
                          density="compact"
                          color="primary"
                          @update:model-value="handleSelectPurchaseRequest"
                        />
                      </td>

                      <td class="col-pr font-weight-medium">
                        {{ pr.nomor_pr || '-' }}
                      </td>

                      <td class="col-attachment pr-attachment-cell">
                        <div v-if="pr.attachments?.length">
                          <TransitionGroup
                            name="attachment-slide"
                            tag="div"
                            class="d-flex flex-column gap-1"
                          >
                            <a
                              v-for="file in visibleAttachments(pr)"
                              :key="file.id"
                              :href="file.filepath"
                              target="_blank"
                              class="pr-attachment-link"
                            >
                              <VIcon icon="tabler-paperclip" size="16" class="me-1" />
                              <span>{{ file.original_filename || file.filename || 'Lampiran PR' }}</span>
                            </a>
                          </TransitionGroup>

                          <div class="d-flex flex-wrap gap-1 mt-2">
                            <VBtn
                              v-if="hasMoreAttachments(pr)"
                              size="x-small"
                              variant="text"
                              color="primary"
                              prepend-icon="tabler-chevron-down"
                              @click.stop="showMoreAttachments(pr)"
                            >
                              Tampilkan lainnya
                            </VBtn>

                            <VBtn
                              v-if="getVisibleAttachmentCount(pr.id) > 1"
                              size="x-small"
                              variant="text"
                              color="secondary"
                              prepend-icon="tabler-chevron-up"
                              @click.stop="showLessAttachments(pr)"
                            >
                              Tampilkan lebih sedikit
                            </VBtn>
                          </div>
                        </div>

                        <span v-else class="text-medium-emphasis text-caption">
                          Tidak ada lampiran
                        </span>
                      </td>

                      <td class="text-center col-date">
                        {{ formatDate(pr.tanggal_pr) }}
                      </td>

                      <td class="col-cabang">
                        {{ pr.cabang || '-' }}
                      </td>

                      <td class="col-department">
                        {{ pr.department || '-' }}
                      </td>

                      <td class="text-end col-total">
                        Rp {{ formatNumberWithoutRp(pr.total_amount) }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>

              <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
                <div class="text-caption text-medium-emphasis">
                  Total Purchase Request: {{ purchaseRequestList.length }}
                </div>

                <div class="d-flex align-center gap-3">
                  <VSelect
                    v-model="prPerPage"
                    :items="prPerPageItems"
                    item-title="title"
                    item-value="value"
                    density="compact"
                    hide-details
                    style="width: 110px;"
                    @update:model-value="prPage = 1"
                  />

                  <VPagination
                    v-if="prPerPage !== 'ALL' && purchaseRequestList.length > Number(prPerPage)"
                    v-model="prPage"
                    :length="prTotalPage"
                    size="small"
                    :total-visible="3"
                  />
                </div>
              </div>

              <div
                v-if="isSubmitted && !form.purchase_request_ids.length"
                class="text-error text-caption mt-2"
              >
                Purchase Request wajib dipilih
              </div>
            </div>
          </VCol>

          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-bold mb-3">
              Item Purchase Order
            </div>

            <VAlert
              v-if="!poItems.length"
              type="info"
              variant="tonal"
              class="mb-0"
            >
              Item akan muncul setelah PR dipilih.
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-4"
            >
              <VCard
                v-for="group in groupedPOItems"
                :key="group.nomor_pr"
                class="po-item-group-card"
              >
                <VCardText>
                  <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                    <div>
                      <div class="text-caption text-medium-emphasis">
                        Nomor PR
                      </div>
                      <div class="text-subtitle-2 font-weight-bold">
                        {{ group.nomor_pr }}
                      </div>
                    </div>

                    <VChip
                      size="small"
                      color="primary"
                      variant="tonal"
                    >
                      {{ group.items.filter((item: any) => item.is_selected !== false).length }} / {{ group.items.length }} Item
                    </VChip>
                  </div>

                  <div class="po-item-table-wrapper">
                    <VTable class="po-item-table">
                      <thead>
                        <tr>
                          <th class="text-center col-check">Pilih</th>
                          <th class="col-item">Nama Item</th>
                          <th class="text-center col-qty">Qty PR</th>
                          <th class="text-center col-qty">Qty Sudah PO</th>
                          <th class="text-center col-qty">Outstanding</th>
                          <th class="text-center col-input">Qty PO</th>
                          <th class="text-center col-unit">Satuan</th>
                          <th class="text-end col-money">Harga</th>
                          <th class="text-end col-money">Total</th>
                        </tr>
                      </thead>

                      <tbody>
                        <tr
                          v-for="item in group.items"
                          :key="`${item.purchase_request_item_id}`"
                          :class="{ 'po-item-row-disabled': item.is_selected === false }"
                        >
                          <td class="text-center col-check">
                            <VCheckbox
                              v-model="item.is_selected"
                              density="compact"
                              hide-details
                              color="primary"
                              @update:model-value="togglePOItemSelection(item)"
                            />
                          </td>

                          <td class="col-item">
                            <div class="item-name">
                              {{ toTitleCase(item.nama_item) || '-' }}
                            </div>
                          </td>

                          <td class="text-center">
                            {{ formatDecimalQty(item.qty_pr) }}
                          </td>

                          <td class="text-center">
                            {{ formatDecimalQty(item.qty_po_existing) }}
                          </td>

                          <td class="text-center">
                            <VChip
                              size="default"
                              color="warning"
                              variant="tonal"
                            >
                              {{ formatDecimalQty(item.qty_outstanding) }}
                            </VChip>
                          </td>

                          <td class="text-center">
                            <VTextField
                              :model-value="item.qty"
                              type="text"
                              inputmode="decimal"
                              density="compact"
                              hide-details="auto"
                              variant="outlined"
                              class="qty-po-field"
                              :disabled="item.is_selected === false"
                              :error="item.is_selected !== false && isSubmitted && (!item.qty || Number(item.qty) <= 0 || Number(item.qty) > Number(item.qty_outstanding))"
                              :error-messages="item.is_selected !== false && isSubmitted && (!item.qty || Number(item.qty) <= 0 || Number(item.qty) > Number(item.qty_outstanding))
                                ? [`Max ${formatDecimalQty(item.qty_outstanding)}`]
                                : []"
                              @update:model-value="value => handlePOQtyInput(value, poItems.findIndex(row => row.purchase_request_item_id === item.purchase_request_item_id))"
                            />
                          </td>

                          <td class="text-center">
                            {{ item.satuan }}
                          </td>

                          <td class="text-end">
                            Rp {{ formatNumberWithoutRp(item.harga_unit) }}
                          </td>

                          <td class="text-end font-weight-bold">
                            <span v-if="item.is_selected !== false">
                              Rp {{ formatNumberWithoutRp(item.subtotal) }}
                            </span>

                            <span
                              v-else
                              class="text-disabled"
                            >
                              Tidak dipilih
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </VTable>
                  </div>
                </VCardText>
              </VCard>
            </div>
          </VCol>

          <VCol cols="12" md="4" offset-md="8">
            <VCard variant="tonal">
              <VCardText>
                <template v-if="isVendorPKP">
                  <div class="d-flex justify-space-between mb-2">
                    <span>Subtotal</span>
                    <strong>Rp {{ formatNumberWithoutRp(subtotal) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between mb-2">
                    <span>DPP</span>
                    <strong>Rp {{ formatNumberWithoutRp(dpp) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between mb-2">
                    <span>PPN</span>
                    <strong>Rp {{ formatNumberWithoutRp(ppn) }}</strong>
                  </div>

                  <VDivider class="my-3" />
                </template>

                <div class="d-flex justify-space-between text-h6">
                  <span>Grand Total</span>
                  <strong class="text-success">
                    Rp {{ formatNumberWithoutRp(grandTotal) }}
                  </strong>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12">
            <VCard variant="tonal" class="rounded-xl">
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Rekomendasi Vendor dari PR
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Rekomendasi berikut berasal dari PR yang dipilih
                    </div>
                  </div>

                  <VChip size="small" color="primary" variant="tonal">
                    {{ selectedRecommendedVendors.length }} Rekomendasi
                  </VChip>
                </div>

                <VAlert
                  v-if="!form.purchase_request_ids.length"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  Pilih Purchase Request terlebih dahulu untuk melihat vendor rekomendasi.
                </VAlert>

                <VAlert
                  v-else-if="!selectedRecommendedVendors.length"
                  type="warning"
                  variant="tonal"
                  density="compact"
                >
                  Tidak ada rekomendasi vendor
                </VAlert>

                <div v-else class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="vendor in selectedRecommendedVendors"
                    :key="vendor.id"
                    color="success"
                    variant="tonal"
                    prepend-icon="tabler-building-store"
                  >
                    {{ vendor.nama_vendor }}
                  </VChip>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.vendor_id"
              label="Vendor PO *"
              :items="vendorList"
              item-title="nama_vendor"
              item-value="id"
              clearable
              density="comfortable"
              :disabled="!form.id_department"
              :loading="isLoadingVendor"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              :error="isSubmitted && !form.vendor_id"
              :error-messages="isSubmitted && !form.vendor_id ? ['Vendor wajib dipilih'] : []"
              placeholder="Pilih vendor untuk PO"
              @update:model-value="handleSelectVendor"
            />
          </VCol>

          <VCol cols="12" md="6" />

          <VCol cols="12" md="6">
            <VTextField
              v-model="form.jenis_pembayaran"
              label="Jenis Pembayaran *"
              readonly
              density="comfortable"
              :error="isSubmitted && !form.jenis_pembayaran"
              :error-messages="isSubmitted && !form.jenis_pembayaran ? ['Jenis pembayaran wajib diisi'] : []"
            />
          </VCol>

          <VCol
            v-if="isCreditPayment"
            cols="12"
            md="6"
          >
            <VTextField
              v-model.number="form.top"
              label="TOP (Hari) *"
              readonly
              density="comfortable"
              placeholder="Contoh: 30"
              :error="isSubmitted && !form.top"
              :error-messages="isSubmitted && !form.top ? ['TOP wajib diisi'] : []"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.notes"
              label="Catatan"
              placeholder="Catatan tambahan..."
              rows="4"
              auto-grow
            />
          </VCol>
        </VRow>

        <VDivider class="mt-6 mb-4" />

        <div class="d-flex justify-end gap-3">
          <VBtn
            type="button"
            color="secondary"
            variant="outlined"
            @click.prevent.stop="confirmCancel"
            class="text-none"
          >
            Batal
          </VBtn>

          <VBtn
            type="button"
            color="primary"
            :loading="isSaving"
            @click="updatePurchaseOrder"
            class="text-none"
          >
            Update
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </section>
</template>

<style lang="scss" scoped>
.pr-attachment-cell {
  min-width: 220px;
  max-width: 280px;
  vertical-align: middle;
}

.pr-attachment-link {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  padding: 4px 8px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.08);
  color: rgb(var(--v-theme-primary));
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
}

.pr-attachment-link span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attachment-slide-enter-active {
  transition: all 0.22s ease;
}

.attachment-slide-enter-from {
  opacity: 0;
  transform: translateY(-6px);
}

.attachment-slide-enter-to {
  opacity: 1;
  transform: translateY(0);
}

.po-item-group-card {
  border-radius: 18px;
}

.po-item-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-radius: 14px;
}

.po-item-table {
  width: 100%;
  min-width: 950px;
  table-layout: fixed;
}

.po-item-table th,
.po-item-table td {
  padding: 10px 8px !important;
  vertical-align: middle;
}

.po-item-table th {
  white-space: nowrap;
  background: rgba(var(--v-theme-primary), 0.05);
  font-weight: 700;
}

.po-item-table .col-item {
  width: 200px;
}

.po-item-table .col-qty {
  width: 115px;
}

.po-item-table .col-input {
  width: 130px;
}

.po-item-table .col-unit {
  width: 90px;
}

.po-item-table .col-money {
  width: 150px;
}

.item-name {
  font-weight: 600;
  line-height: 1.35;
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
}

.qty-po-field :deep(.v-field__input) {
  min-height: 36px !important;
  padding-block: 4px !important;
  text-align: center;
}

@media (max-width: 1280px) {
  .po-item-table {
    min-width: 900px;
  }

  .po-item-table .col-item {
    width: 220px;
  }

  .po-item-table .col-money {
    width: 135px;
  }
}

.col-check {
  width: 72px;
  min-width: 72px;
}

.po-item-row-disabled {
  opacity: 0.55;
  background-color: rgba(var(--v-theme-surface-variant), 0.25);
}

.po-item-row-disabled .item-name {
  text-decoration: line-through;
}
</style>