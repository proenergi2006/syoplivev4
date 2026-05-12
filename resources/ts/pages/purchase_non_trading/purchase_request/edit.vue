<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import axios from '@axios'
import {
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  closeAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  onlyNumberKeypress,
  formatSanitizedNumberInput,
  getClipboardText,
} from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { formatCurrency, unformatMoney } from '@/utils/textFormatter'
import { useDisplay } from 'vuetify'

interface PurchaseRequestForm {
  tanggal_pr: string
  cabang: string | number | null
  id_department: string | number | null
  kategori: string | null
  notes: string
  lampiran_requests: File[]
}

interface CabangOption {
  id: number
  value: number
  title: string
  nama_cabang: string
  inisial_cabang: string
}

interface DepartmentOption {
  id: number
  nama: string
  kode?: string
}

interface PurchaseRequestErrors {
  lampiran_request: string
}

interface UnitApiItem {
  kode: string
  nama: string
}

interface UnitOption {
  value: string
  label: string
}

interface VendorItem {
  nama_item: string
  qty: number
  satuan: string | null
  keterangan: string
  harga_unit: number | null
  subtotal: number
}

interface VendorRow {
  vendor_id: number | null
  status_pkp?: string
  is_selected: boolean
  items: VendorItem[]
}

interface VendorOption {
  id: number
  nama_vendor: string
  status_pkp: string
}

interface ErrorResponse {
  message?: string
  errors?: Record<string, string[]>
}

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: ErrorResponse
  }
}

interface UnitItem {
  id: number
  kode: string
  nama: string
  kategori: string
}

interface ExistingPrAttachment {
  id: number
  filename: string
  original_filename: string
  filepath: string
  filesize: number
  filetype: string
}

const route = useRoute()
const router = useRouter()

const publicId = computed(() => String(route.query.id || ''))
const isLoadingDetail = ref(false)

const isSubmitted = ref(false)
const isSaving = ref(false)

const fileRef = ref<HTMLInputElement | null>(null)

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const vendorList = ref<VendorOption[]>([])
const isLoadingVendor = ref(false)

const units = ref<UnitItem[]>([])
const isLoadingUnits = ref(false)

const cabangList = ref<CabangOption[]>([])
const isLoadingCabang = ref(false)

const departmentList = ref<DepartmentOption[]>([])
const isLoadingDepartment = ref(false)

const itemDialog = ref(false)
const activeVendorIndex = ref<number | null>(null)
const tempItems = ref<any[]>([])

const { mobile } = useDisplay()
const confirmCloseItemDialog = ref(false)
const itemDialogSaved = ref(false)
const savedVendorItems = ref<number[]>([])

const existingLampiranRequests = ref<ExistingPrAttachment[]>([])

const form = reactive<PurchaseRequestForm>({
  tanggal_pr: '',
  cabang: null,
  id_department: null,
  kategori: null,
  notes: '',
  lampiran_requests: [],
})

const tanggalPR = useNativeDatePicker(toRef(form, 'tanggal_pr'))
const errors = reactive<PurchaseRequestErrors>({
  lampiran_request: '',
})

const fetchCabangList = async (showAlert = true): Promise<void> => {
  isLoadingCabang.value = true

  try {
    const response = await axios.get('/master/cabang/dropdown-select', {
      headers: {
        Accept: 'application/json',
      },
    })

    cabangList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          nama: item.nama_cabang || item.title || '-',
          inisial_cabang: item.inisial_cabang || '',
        }))
      : []
  } catch (error: unknown) {
    console.error('[Cabang] FETCH ERROR:', error)

    cabangList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data department.'),
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
      headers: {
        Accept: 'application/json',
      },
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
    console.error('[Department] FETCH ERROR:', error)

    departmentList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data department.'),
      })
    }
  } finally {
    isLoadingDepartment.value = false
  }
}

const loadPurchaseRequestDetail = async (): Promise<void> => {
  if (!publicId.value) {
    showErrorToast({
      title: 'Error',
      text: 'ID Purchase Request tidak ditemukan.',
    })

    await router.replace('/purchase_non_trading/purchase_request')
    return
  }

  if (isLoadingDetail.value) return

  isLoadingDetail.value = true

  try {
    const response = await axios.get(
      `/transaction/purchase-request/${publicId.value}/edit`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data purchase request tidak ditemukan')
    }

    existingLampiranRequests.value = Array.isArray(detail.attachments)
      ? detail.attachments.map((file: any) => ({
          id: Number(file.id),
          filename: file.filename || '-',
          original_filename: file.original_filename || '-',
          filepath: file.filepath || '#',
          filesize: Number(file.filesize || 0),
          filetype: file.filetype || '',
        }))
      : []

    form.lampiran_requests = []

    form.tanggal_pr = detail.tanggal_pr ?? ''
    form.cabang = detail.cabang_id !== null && detail.cabang_id !== undefined
      ? Number(detail.cabang_id)
      : null

    form.id_department = detail.department_id !== null && detail.department_id !== undefined
      ? Number(detail.department_id)
      : null

    form.kategori = detail.kategori ?? null
    form.notes = detail.notes ?? ''
    form.lampiran_requests = []

    vendors.value = Array.isArray(detail.vendors) && detail.vendors.length
      ? detail.vendors.map((vendor: any) => ({
          vendor_id: vendor.vendor_id !== null && vendor.vendor_id !== undefined
            ? Number(vendor.vendor_id)
            : null,

          status_pkp: vendor.status_pkp ?? 'NON_PKP',
          is_selected: Boolean(vendor.is_selected),

          items: Array.isArray(vendor.items) && vendor.items.length
            ? vendor.items.map((item: any) => ({
                nama_item: item.nama_item ?? '',
                qty: Number(item.qty || 1),

                satuan: item.satuan_id !== null && item.satuan_id !== undefined
                  ? Number(item.satuan_id)
                  : null,

                keterangan: item.keterangan ?? '',
                harga_unit: Number(item.harga_unit || 0),
                subtotal: Number(item.subtotal || 0),
              }))
            : [createEmptyItem()],
        }))
      : [
          {
            vendor_id: null,
            is_selected: false,
            items: [createEmptyItem()],
          },
        ]

    savedVendorItems.value = vendors.value.map((_, index) => index)
  } catch (error: unknown) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat detail Purchase Request.'),
    })

    await router.replace('/purchase_non_trading/purchase_request')
  } finally {
    isLoadingDetail.value = false
  }
}

const closeItemDialog = (): void => {
  if (itemDialogSaved.value) {
    tempItems.value = []
    activeVendorIndex.value = null
    itemDialog.value = false
    return
  }

  confirmCloseItemDialog.value = true
}

const confirmCloseFullscreenItem = (): void => {
  confirmCloseItemDialog.value = false
  tempItems.value = []
  activeVendorIndex.value = null
  itemDialog.value = false
}

const openItemFullscreen = (vendorIndex: number): void => {
  activeVendorIndex.value = vendorIndex

  tempItems.value = JSON.parse(
    JSON.stringify(vendors.value[vendorIndex].items || []),
  )

  itemDialogSaved.value = savedVendorItems.value.includes(vendorIndex)

  itemDialog.value = true
}

const saveItemsFromDialog = (): void => {
  if (activeVendorIndex.value === null) return

  const normalizedItems = tempItems.value.map((item: any) => {
    const qty = Number(item.qty || 0)
    const hargaUnit = Number(item.harga_unit || 0)

    return {
      ...item,
      qty,
      harga_unit: hargaUnit,
      subtotal: qty * hargaUnit,
    }
  })

  vendors.value[activeVendorIndex.value].items = JSON.parse(
    JSON.stringify(normalizedItems),
  )

  // tandai vendor sudah pernah save
  if (!savedVendorItems.value.includes(activeVendorIndex.value)) {
    savedVendorItems.value.push(activeVendorIndex.value)
  }

  itemDialog.value = false
}

const kategoriList = ['Baru', 'Perbaikan', 'Improvement', 'Regular', 'Lain-lain']

const today = (): string => new Date().toISOString().split('T')[0]

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
}

const getExtension = (fileName: string): string => {
  return fileName.split('.').pop()?.toLowerCase() || ''
}

const createEmptyItem = (): VendorItem => ({
  nama_item: '',
  qty: 1,
  satuan: null,
  keterangan: '',
  harga_unit: null,
  subtotal: 0,
})

const vendors = ref<VendorRow[]>([
  {
    vendor_id: null,
    is_selected: false,
    items: [createEmptyItem()],
  },
])

const unitFilter = (itemTitle: string, queryText: string, item: any) => {
  const search = String(queryText ?? '').toLowerCase()
  const kode = String(item?.raw?.kode ?? '').toLowerCase()
  const nama = String(item?.raw?.nama ?? '').toLowerCase()
  const kategori = String(item?.raw?.kategori ?? '').toLowerCase()

  return (
    kode.includes(search) ||
    nama.includes(search) ||
    kategori.includes(search)
  )
}

const loadUnits = async (showAlert = true): Promise<void> => {
  isLoadingUnits.value = true

  try {
    const response = await axios.get('/units/dropdown-select', {
      headers: {
        Accept: 'application/json',
      },
    })

    const payload = response?.data

    console.log('UNITS RESPONSE:', payload)

    const data = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : []

    units.value = data.map((item: any) => ({
      id: Number(item.id),
      kode: item.kode || '',
      nama: item.nama || '-',
      kategori: item.kategori || '',
    }))
  } catch (error: unknown) {
    console.error('[Units] FETCH ERROR:', error)

    units.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data satuan.'),
      })
    }
  } finally {
    isLoadingUnits.value = false
  }
}

const loadVendors = async (showAlert = true): Promise<void> => {
  isLoadingVendor.value = true

  try {
    const res = await axios.get('/master/vendor/dropdown-select', {
      headers: {
        Accept: 'application/json',
      },
    })

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    vendorList.value = data.map((item: any) => ({
      id: Number(item.id),
      nama_vendor: item.nama_vendor || item.title || '-',
      status_pkp: item.status_pkp || 'NON_PKP',
    }))
  } catch (error: unknown) {
    console.error('[Vendor] FETCH ERROR:', error)

    vendorList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data vendor.'),
      })
    }
  } finally {
    isLoadingVendor.value = false
  }
}

const setVendorPKP = (index: number): void => {
  const vendor = vendorList.value.find(
    item => item.id === Number(vendors.value[index].vendor_id),
  )

  vendors.value[index].status_pkp = vendor?.status_pkp ?? 'NON_PKP'
}

const isVendorUsed = (vendorId: number, currentIndex: number): boolean => {
  return vendors.value.some((vendor, index) => {
    return index !== currentIndex && Number(vendor.vendor_id) === vendorId
  })
}

const toggleSelectedVendor = (index: number): void => {
  vendors.value.forEach((vendor, i) => {
    vendor.is_selected = i === index
  })
}

const removeVendorRow = (index: number): void => {
  if (vendors.value.length > 1) {
    vendors.value.splice(index, 1)
  }
}

const addVendorRow = async (): Promise<void> => {
  if (vendors.value.length === 0) {
    vendors.value.push({
      vendor_id: null,
      is_selected: false,
      items: [createEmptyItem()],
    })
    return
  }

  const result = await Swal.fire({
    title: 'Tambah Vendor',
    text: 'Pilih item untuk vendor baru',
    input: 'select',
    inputOptions: {
      copy: 'Copy semua item dari vendor pertama',
      new: 'Item baru',
    },
    inputPlaceholder: 'Pilih opsi',
    showCancelButton: true,
    confirmButtonText: 'Lanjutkan',
    cancelButtonText: 'Batal',
    customClass: {
      confirmButton: 'swal-confirm-white',
      cancelButton: 'swal-cancel-white',
    },

    buttonsStyling: false,
    inputValidator: (value) => {
      if (!value) return 'Silakan pilih salah satu opsi'
      return null
    },
  })

  if (!result.value) return

  let items: VendorItem[] = []

  if (result.value === 'copy') {
    const sourceItems = vendors.value[0]?.items || []

    if (!sourceItems.length) {
      showWarningToast({
        title: 'Info',
        text: 'Vendor pertama belum memiliki item.',
      })
      return
    }

    items = sourceItems.map(item => ({
      nama_item: item.nama_item,
      qty: item.qty,
      satuan: item.satuan,
      keterangan: item.keterangan,
      harga_unit: item.harga_unit,
      subtotal: item.subtotal,
    }))
  } else {
    items = [createEmptyItem()]
  }

  vendors.value.push({
    vendor_id: null,
    is_selected: false,
    items,
  })
}



const addVendorItem = async (vendorIndex: number): Promise<void> => {
  const result = await Swal.fire({
    title: 'Tambah Item',
    text: 'Pilih jumlah item yang ingin ditambahkan',
    input: 'select',

    inputOptions: {
      '1': '1 Item',
      '10': '10 Item',
      '20': '20 Item',
      '30': '30 Item',
      custom: 'Custom',
    },

    inputPlaceholder: 'Pilih jumlah',

    showCancelButton: true,

    confirmButtonText: 'Tambah',
    cancelButtonText: 'Batal',

    customClass: {
      confirmButton: 'swal-confirm-white',
      cancelButton: 'swal-cancel-white',
    },

    buttonsStyling: false,
  })

  if (!result.value) return

  let total = 0

  if (result.value === 'custom') {
    const customResult = await Swal.fire({
      title: 'Jumlah Item',
      input: 'number',
      inputLabel: 'Masukkan jumlah item',
      inputAttributes: {
        min: '1',
      },
      showCancelButton: true,
      confirmButtonText: 'Tambah',
      cancelButtonText: 'Batal',
      inputValidator: (value) => {
        if (!value || Number(value) <= 0) {
          return 'Jumlah harus lebih dari 0'
        }
        return null
      },
    })

    if (!customResult.value) return

    total = Number(customResult.value)
  } else {
    total = Number(result.value)
  }

  for (let i = 0; i < total; i++) {
    vendors.value[vendorIndex].items.push(createEmptyItem())
  }
}

const resetVendorItems = async (vendorIndex: number): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Reset semua item?',
    text: 'Semua item vendor ini akan dihapus.',
    confirmButtonText: 'Ya, hapus semua',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  vendors.value[vendorIndex].items = [createEmptyItem()]
}

const removeVendorItem = (vendorIndex: number, itemIndex: number): void => {
  if (vendors.value[vendorIndex].items.length > 1) {
    vendors.value[vendorIndex].items.splice(itemIndex, 1)
  }
}

const updateItemSubtotal = (vendorIndex: number, itemIndex: number): void => {
  const item = vendors.value[vendorIndex].items[itemIndex]
  item.subtotal = (Number(item.qty) || 0) * (Number(item.harga_unit) || 0)
}

const formatMoney = (value: number | null): string => {
  if (!value) return ''
  return new Intl.NumberFormat('id-ID').format(value)
}

const handleItemPriceInput = (event: Event, vendorIndex: number, itemIndex: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 12,
    emptyAsZero: true,
  })

  vendors.value[vendorIndex].items[itemIndex].harga_unit = result.numeric ?? 0
  updateItemSubtotal(vendorIndex, itemIndex)

  target.value = result.formatted
}

const handleItemPricePaste = (
  event: ClipboardEvent,
  vendorIndex: number,
  itemIndex: number
): void => {
  const pastedText = event.clipboardData?.getData('text') || ''

  if (!/^\d+$/.test(pastedText.trim())) {
    event.preventDefault()

    showErrorToast({
      title: 'Input tidak valid',
      text: 'Harga hanya boleh berupa angka (0-9)',
    })

    return
  }

  const target = event.target as HTMLInputElement
  const harga = Number(pastedText)

  vendors.value[vendorIndex].items[itemIndex].harga_unit = harga
  updateItemSubtotal(vendorIndex, itemIndex)

  target.value = formatMoney(harga)
}

const onlyNumber = (e: KeyboardEvent): void => {
  onlyNumberKeypress(e)
}
const getVendorGrandTotal = (index: number): number => {
  return vendors.value[index].items.reduce((sum, item) => sum + item.subtotal, 0)
}

const calcVendorDPP = (index: number): number => {
  return (getVendorGrandTotal(index) * 11) / 12
}

const calcVendorPPN = (index: number): number => {
  return Math.round(calcVendorDPP(index) * 0.12)
}

const calcVendorTotalNonPKP = (index: number): number => {
  return getVendorGrandTotal(index)
}

const calcVendorTotalPKP = (index: number): number => {
  const total = getVendorGrandTotal(index)
  const ppn = calcVendorPPN(index)

  return total + ppn
}

const triggerFileInput = (): void => {
  fileRef.value?.click()
}

const handleFileUpload = async (event: Event): Promise<void> => {
  const input = event.target as HTMLInputElement
  if (!input.files) return

  errors.lampiran_request = ''

  const invalidMessages: string[] = []

  for (const file of Array.from(input.files)) {
    const ext = getExtension(file.name)
    const validMime = ALLOWED_TYPES.includes(file.type)
    const validExt = ALLOWED_EXTENSIONS.includes(ext)

    if (!validMime && !validExt) {
      invalidMessages.push(`"${file.name}" bukan file PDF/JPG/JPEG/PNG.`)
      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(`"${file.name}" lebih dari 3MB.`)
      continue
    }

    const exists = form.lampiran_requests.some(
      existing => existing.name === file.name && existing.size === file.size,
    )

    if (!exists) {
      form.lampiran_requests.push(file)
    }
  }

  if (invalidMessages.length) {
    errors.lampiran_request = invalidMessages.join(' ')
    showWarningToast({
      title: 'File tidak valid',
      text: invalidMessages.join(' '),
    })
  }

  input.value = ''
}

const getExistingFileType = (file: ExistingPrAttachment): string => {
  const type = String(file.filetype || '').toLowerCase()
  const name = String(file.filename || '').toLowerCase()

  if (type.includes('pdf') || name.endsWith('.pdf')) return 'PDF'

  return 'IMAGE'
}

const formatExistingFileSize = (bytes: number): string => {
  if (!bytes) return '-'

  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const removeLampiran = (index: number): void => {
  form.lampiran_requests.splice(index, 1)
}

const removeExistingLampiran = (index: number): void => {
  existingLampiranRequests.value.splice(index, 1)
}

const formatFileSize = (bytes: number): string => {
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const getFileType = (file: File): string => {
  return file.type === 'application/pdf' ? 'PDF' : 'IMAGE'
}

const validateForm = async (): Promise<boolean> => {
  if (
    !required(form.tanggal_pr)
    || !required(form.cabang)
    || !required(form.id_department)
    || !required(form.kategori)
  ) {
    showWarningToast({
      title: 'Warning',
      text: 'Lengkapi data wajib.',
    })
    return false
  }

  const emptyVendorIndex = vendors.value.findIndex(vendor => !required(vendor.vendor_id))

  if (emptyVendorIndex !== -1) {
    showWarningToast({
      title: 'Warning',
      text: 'Silahkan pilih vendor terlebih dahulu.',
    })
    return false
  }

  const hasRecommendedVendor = vendors.value.some(vendor => vendor.is_selected === true)

  if (!hasRecommendedVendor) {
    showWarningToast({
      title: 'Warning',
      text: 'Pilih satu vendor rekomendasi.',
    })
    return false
  }

  for (const vendor of vendors.value) {
    if (!vendor.vendor_id) continue

    if (vendor.items.some(item => !required(item.nama_item))) {
      showWarningToast({
        title: 'Warning',
        text: 'Nama item wajib diisi.',
      })
      return false
    }

    if (vendor.items.some(item => !item.qty || Number(item.qty) <= 0)) {
      showWarningToast({
        title: 'Warning',
        text: 'Qty item wajib diisi.',
      })
      return false
    }

    if (vendor.items.some(item => !required(item.satuan))) {
      showWarningToast({
        title: 'Warning',
        text: 'Satuan item wajib dipilih.',
      })
      return false
    }

    if (vendor.items.some(item => item.harga_unit === null || Number(item.harga_unit) <= 0)) {
      showWarningToast({
        title: 'Warning',
        text: 'Harga satuan item wajib diisi.',
      })
      return false
    }
  }

  return true
}

const buildFormData = (): FormData => {
  const formData = new FormData()

  formData.append('tanggal_pr', String(form.tanggal_pr || ''))
  formData.append('cabang', String(form.cabang || ''))
  formData.append('id_department', String(form.id_department || ''))
  formData.append('kategori', String(form.kategori || ''))
  formData.append('notes', String(form.notes || ''))

  formData.append(
    'existing_attachment_ids',
    JSON.stringify(existingLampiranRequests.value.map(file => file.id)),
  )

  formData.append(
    'vendors',
    JSON.stringify(
      vendors.value.map((vendor, index) => ({
        vendor_id: vendor.vendor_id,
        is_selected: vendor.is_selected,
        dpp: vendor.status_pkp === 'PKP' ? calcVendorDPP(index) : 0,
        ppn: vendor.status_pkp === 'PKP' ? calcVendorPPN(index) : 0,
        items: vendor.items,
      })),
    ),
  )

  if (Array.isArray(form.lampiran_requests)) {
    form.lampiran_requests.forEach((file: File) => {
      formData.append('lampiran_requests[]', file)
    })
  }

  return formData
}

const updatePurchaseRequest = async (): Promise<void> => {
  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    title: 'Update Purchase Request?',
    text: 'Pastikan perubahan data sudah benar.',
    confirmButtonText: 'Ya, update',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Mengupdate data...', 'Mohon tunggu sebentar')

    const formData = buildFormData()
    formData.append('_method', 'PUT')

    const response = await axios.post(
      `/transaction/purchase-request/${publicId.value}`,
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Purchase Request berhasil diperbarui.',
    })

    await router.replace('/purchase_non_trading/purchase_request')
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memperbarui Purchase Request.'),
    })
  } finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Batalkan perubahan?',
    confirmButtonText: 'Ya',
    cancelButtonText: 'Tidak',
  })

  if (confirm.isConfirmed) {
    goBack()
  }
}

const goBack = async (): Promise<void> => {
  await router.replace({
    path: '/purchase_non_trading/purchase_request',
  })
}

onMounted(async () => {
    form.tanggal_pr = today()
    await Promise.all([
        loadUnits(false),
        loadVendors(false),
        fetchCabangList(false),
        fetchDepartmentList(false),
    ])

    await loadPurchaseRequestDetail()
})
</script>

<template>
  <section>
    <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <div>
            <div class="text-h6 font-weight-bold">
              Form Edit Purchase Request
            </div>
            <div class="text-body-2 text-medium-emphasis">
              Silakan lengkapi data purchase request dengan benar
            </div>
          </div>

          <VBtn
            prepend-icon="mdi-arrow-left"
            variant="text"
            color="secondary"
            @click="goBack"
          >
            Kembali
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VRow>

            <VCol cols="12" md="3">
              <div class="position-relative">
                <VTextField
                  :model-value="tanggalPR.displayValue.value"
                  label="Tanggal PR *"
                  placeholder="DD/MM/YYYY"
                  readonly
                  append-inner-icon="tabler-calendar"
                  :error="isSubmitted && !form.tanggal_pr"
                  :error-messages="isSubmitted && !form.tanggal_pr ? ['Tanggal PR wajib diisi'] : []"
                  @click="tanggalPR.openPicker"
                  @click:append-inner="tanggalPR.openPicker"
                />

                <input
                  :ref="(el) => {
                    tanggalPR.nativeDateRef.value = el as HTMLInputElement | null
                  }"
                  type="date"
                  :value="form.tanggal_pr"
                  class="native-date-hidden"
                  tabindex="-1"
                  aria-hidden="true"
                  @change="tanggalPR.onDateChange"
                >
              </div>
            </VCol>

            <VCol cols="12" md="3">
              <VAutocomplete
                v-model="form.cabang"
                label="Cabang *"
                :items="cabangList"
                item-title="nama"
                item-value="id"
                clearable
                density="comfortable"
                :menu-props="{ maxHeight: 300 }"
                :loading="isLoadingCabang"
                :error="isSubmitted && !form.cabang"
                :error-messages="isSubmitted && !form.cabang ? ['Cabang wajib dipilih'] : []"
                no-data-text="Cabang tidak ditemukan"
                placeholder="Pilih cabang"
              >
                <template #append-inner>
                  <VTooltip
                    v-if="!isLoadingCabang && cabangList.length === 0"
                    text="Reload data cabang"
                    location="top"
                  >
                    <template #activator="{ props }">
                      <VBtn
                        v-bind="props"
                        icon
                        size="x-small"
                        variant="text"
                        color="primary"
                        @click.stop.prevent="fetchCabangList(true)"
                      >
                        <VIcon icon="tabler-refresh" />
                      </VBtn>
                    </template>
                  </VTooltip>
                </template>
              </VAutocomplete>
            </VCol>

            <VCol cols="12" md="3">
              <VAutocomplete
                v-model="form.id_department"
                label="Department *"
                :items="departmentList"
                item-title="label"
                item-value="id"
                clearable
                density="comfortable"
                :menu-props="{ maxHeight: 300 }"
                :loading="isLoadingDepartment"
                :error="isSubmitted && !form.id_department"
                :error-messages="isSubmitted && !form.id_department ? ['Department wajib dipilih'] : []"
                no-data-text="Department tidak ditemukan"
                placeholder="Pilih department"
              >
                <template #append-inner>
                  <VProgressCircular
                    v-if="isLoadingDepartment"
                    indeterminate
                    size="18"
                    width="2"
                  />

                  <VTooltip
                    v-else-if="departmentList.length === 0"
                    text="Reload data department"
                    location="top"
                  >
                    <template #activator="{ props }">
                      <VBtn
                        v-bind="props"
                        icon
                        size="x-small"
                        variant="text"
                        color="primary"
                        @click.stop.prevent="fetchDepartmentList(true)"
                      >
                        <VIcon icon="tabler-refresh" />
                      </VBtn>
                    </template>
                  </VTooltip>
                </template>
              </VAutocomplete>
            </VCol>

            <VCol cols="12" md="3">
              <VAutocomplete
                v-model="form.kategori"
                label="Kategori *"
                :items="kategoriList"
                clearable
                density="comfortable"
                :menu-props="{ maxHeight: 300 }"
                :error="isSubmitted && !form.kategori"
                :error-messages="isSubmitted && !form.kategori ? ['Kategori wajib dipilih'] : []"
                no-data-text="Kategori tidak ditemukan"
                placeholder="Pilih kategori"
              />
            </VCol>

            <!-- VENDOR SECTION -->
            <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-2">
                    <div class="text-subtitle-1 font-weight-bold">
                    Vendor Penawaran
                    </div>

                    <VBtn
                    type="button"
                    color="primary"
                    variant="outlined"
                    size="small"
                    @click="addVendorRow"
                    >
                    + Tambah Vendor
                    </VBtn>
                </div>

                <VDivider class="mb-4" />
            </VCol>

            <VCol
            v-for="(vendor, vIndex) in vendors"
            :key="`vendor-${vIndex}`"
            cols="12"
            >
            <VCard variant="outlined" class="mb-4">
                <VCardTitle class="d-flex align-center justify-space-between py-3">
                <div class="text-subtitle-2 font-weight-bold">
                    {{ vIndex + 1 }}. Vendor
                </div>

                <div class="d-flex align-center gap-2">
                    <VBtn
                    type="button"
                    color="error"
                    variant="text"
                    size="small"
                    v-if="vendors.length > 1"
                    @click="removeVendorRow(vIndex)"
                    >
                    Hapus Vendor
                    </VBtn>
                </div>
                </VCardTitle>

                <VDivider />

                <VCardText>
                <VRow>
                    <!-- HEADER VENDOR -->
                    <VCol cols="12" md="8">
                      <VAutocomplete
                        v-model="vendor.vendor_id"
                        label="Vendor *"
                        :items="vendorList"
                        item-title="nama_vendor"
                        item-value="id"
                        clearable
                        density="comfortable"
                        :menu-props="{ maxHeight: 300 }"
                        :loading="isLoadingVendor"
                        :error="isSubmitted && !vendor.vendor_id"
                        :error-messages="isSubmitted && !vendor.vendor_id ? ['Vendor wajib dipilih'] : []"
                        no-data-text="Vendor tidak ditemukan"
                        placeholder="Pilih vendor"
                        @update:model-value="setVendorPKP(vIndex)"
                      >
                        <template #append-inner>
                          <VProgressCircular
                            v-if="isLoadingVendor"
                            indeterminate
                            size="18"
                            width="2"
                          />

                          <VTooltip
                            v-else-if="vendorList.length === 0"
                            text="Reload data vendor"
                            location="top"
                          >
                            <template #activator="{ props }">
                              <VBtn
                                v-bind="props"
                                icon
                                size="x-small"
                                variant="text"
                                color="primary"
                                @click.stop.prevent="loadVendors(true)"
                              >
                                <VIcon icon="tabler-refresh" />
                              </VBtn>
                            </template>
                          </VTooltip>
                        </template>

                        <template #item="{ props, item }">
                          <VListItem
                            v-bind="props"
                            :title="item.raw?.nama_vendor || '-'"
                            :subtitle="item.raw?.status_pkp || 'NON_PKP'"
                          />
                        </template>
                      </VAutocomplete>
                    </VCol>

                    <VCol cols="12" md="4">
                    <div class="d-flex align-center h-100">
                        <VCheckbox
                        :model-value="vendor.is_selected"
                        label="Vendor Rekomendasi"
                        color="primary"
                        hide-details
                        @update:model-value="toggleSelectedVendor(vIndex)"
                        />
                    </div>
                    </VCol>

                    <!-- ITEM HEADER -->
                    <VCol cols="12">
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
                        <div class="text-body-1 font-weight-medium">
                        Daftar Item
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                          <VBtn
                            v-if="!mobile"
                            variant="tonal"
                            color="primary"
                            prepend-icon="tabler-maximize"
                            @click="openItemFullscreen(vIndex)"
                          >
                            Input Fullscreen
                          </VBtn>
                          <VBtn
                              type="button"
                              size="small"
                              color="primary"
                              variant="outlined"
                              @click="addVendorItem(vIndex)"
                          >
                              + Tambah Item
                          </VBtn>

                          <VBtn
                              type="button"
                              size="small"
                              color="error"
                              variant="outlined"
                              @click="resetVendorItems(vIndex)"
                          >
                              Reset Item
                          </VBtn>
                        </div>
                    </div>
                    </VCol>

                    <!-- ITEM TABLE -->
                    <VCol cols="12">
                      <div class="vendor-item-table-wrapper">
                        <VTable class="vendor-item-table">
                          <thead>
                            <tr>
                              <th class="text-center col-no">No</th>
                              <th class="col-item-name">Nama Item</th>
                              <th class="col-qty">Qty</th>
                              <th class="col-unit">Satuan</th>
                              <th class="col-price text-right">Harga Satuan</th>
                              <th class="col-subtotal text-right">Subtotal</th>
                              <th class="col-note">Keterangan</th>
                              <th class="text-center col-action">Aksi</th>
                            </tr>
                          </thead>

                          <tbody>
                            <tr
                              v-for="(item, iIndex) in vendor.items"
                              :key="`vendor-${vIndex}-item-${iIndex}`"
                            >
                              <td class="text-center">
                                {{ iIndex + 1 }}
                              </td>

                              <td>
                                <VTextField
                                  v-model="item.nama_item"
                                  placeholder="Nama item"
                                  density="compact"
                                  hide-details="auto"
                                  variant="outlined"
                                  class="table-field"
                                  :error="isSubmitted && !item.nama_item"
                                  :error-messages="isSubmitted && !item.nama_item ? ['Nama item wajib diisi'] : []"
                                />
                              </td>

                              <td>
                                <VTextField
                                  v-model.number="item.qty"
                                  type="number"
                                  min="1"
                                  placeholder="Qty"
                                  density="compact"
                                  hide-details="auto"
                                  variant="outlined"
                                  class="table-field text-right-field"
                                  :error="isSubmitted && (!item.qty || Number(item.qty) <= 0)"
                                  :error-messages="isSubmitted && (!item.qty || Number(item.qty) <= 0) ? ['Quantity wajib diisi'] : []"
                                  @update:model-value="updateItemSubtotal(vIndex, iIndex)"
                                />
                              </td>

                              <td>
                                <VAutocomplete
                                  v-model="item.satuan"
                                  label="Pilih Satuan"
                                  :items="units"
                                  item-title="nama"
                                  item-value="id"
                                  :clearable="!!item.satuan"
                                  clear-icon="mdi-close-circle"
                                  density="compact"
                                  hide-details="auto"
                                  variant="outlined"
                                  menu-icon="mdi-menu-down"
                                  class="table-field unit-field"
                                  :loading="isLoadingUnits"
                                  :menu-props="{
                                    maxHeight: 260,
                                    location: 'bottom',
                                    offset: 4
                                  }"
                                  :custom-filter="unitFilter"
                                  :error="isSubmitted && !item.satuan"
                                  :error-messages="isSubmitted && !item.satuan ? ['Satuan wajib dipilih'] : []"
                                >
                                  <template #append-inner>

                                    <VProgressCircular
                                      v-if="isLoadingUnits"
                                      indeterminate
                                      size="16"
                                      width="2"
                                    />

                                    <VTooltip
                                      v-else-if="units.length === 0"
                                      text="Reload data satuan"
                                      location="top"
                                    >
                                      <template #activator="{ props }">
                                        <VBtn
                                          v-bind="props"
                                          icon
                                          size="x-small"
                                          variant="text"
                                          color="primary"
                                          @click.stop.prevent="loadUnits(true)"
                                        >
                                          <VIcon icon="tabler-refresh" />
                                        </VBtn>
                                      </template>
                                    </VTooltip>

                                  </template>

                                  <template #item="{ props, item }">
                                    <VListItem
                                      v-bind="props"
                                      :title="`${item.raw?.kode ?? ''} - ${item.raw?.nama ?? ''}`"
                                      :subtitle="item.raw?.kategori ?? ''"
                                    />
                                  </template>

                                  <template #selection="{ item }">
                                    <span v-if="item?.raw?.kode">
                                      {{ item.raw.kode }}
                                    </span>
                                  </template>
                                </VAutocomplete>
                              </td>

                              <td class="text-right">
                                <VTextField
                                  :model-value="formatMoney(item.harga_unit)"
                                  placeholder="Harga satuan"
                                  density="compact"
                                  hide-details="auto"
                                  variant="outlined"
                                  inputmode="numeric"
                                  class="table-field text-right-field"
                                  @keypress="onlyNumber"
                                  :error="isSubmitted && (item.harga_unit === null || Number(item.harga_unit) <= 0)"
                                  :error-messages="isSubmitted && (item.harga_unit === null || Number(item.harga_unit) <= 0) ? ['Harga satuan wajib diisi'] : []"
                                  @input="handleItemPriceInput($event, vIndex, iIndex)"
                                  @paste.prevent="handleItemPricePaste($event, vIndex, iIndex)"
                                />
                              </td>

                              <td class="text-right">
                                <VTextField
                                  :model-value="formatMoney(item.subtotal)"
                                  density="compact"
                                  hide-details
                                  variant="outlined"
                                  readonly
                                  class="table-field text-right-field"
                                />
                              </td>

                              <td>
                                <VTextField
                                  v-model="item.keterangan"
                                  placeholder="Keterangan"
                                  rows="1"
                                  auto-grow
                                  density="compact"
                                  hide-details="auto"
                                  variant="outlined"
                                  class="table-field"
                                />
                              </td>

                              <td class="text-center">
                                <VBtn
                                  type="button"
                                  color="error"
                                  variant="text"
                                  size="small"
                                  v-if="vendor.items.length > 1"
                                  @click="removeVendorItem(vIndex, iIndex)"
                                >
                                  Hapus
                                </VBtn>
                              </td>
                            </tr>

                            <tr v-if="!vendor.items.length">
                              <td colspan="8" class="text-center text-medium-emphasis py-6">
                                Belum ada item.
                              </td>
                            </tr>
                          </tbody>
                        </VTable>
                      </div>
                    </VCol>

                    <!-- TOTAL -->
                    <VCol cols="12">
                    <div class="mt-4 text-right">
                        <div
                        v-if="vendor.status_pkp === 'PKP'"
                        class="d-inline-flex flex-column align-end ga-1"
                        >
                        <div class="text-body-2">
                            DPP:
                            <strong>{{ formatMoney(calcVendorDPP(vIndex)) }}</strong>
                        </div>
                        <div class="text-body-2">
                            PPN (11%):
                            <strong>{{ formatMoney(calcVendorPPN(vIndex)) }}</strong>
                        </div>
                        <div class="text-body-1 font-weight-bold">
                            Grand Total:
                            <strong>{{ formatMoney(calcVendorTotalPKP(vIndex)) }}</strong>
                        </div>
                        </div>

                        <div v-else class="d-inline-flex flex-column align-end">
                        <div class="text-body-1 font-weight-bold">
                            Grand Total:
                            <strong>{{ formatMoney(calcVendorTotalNonPKP(vIndex)) }}</strong>
                        </div>
                        </div>
                    </div>
                    </VCol>
                </VRow>
                </VCardText>
            </VCard>
            </VCol>

            <!-- LAMPIRAN -->
            <VCol cols="12">
              <div class="mt-4">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-2">
                  <div class="text-subtitle-1 font-weight-bold">
                    Lampiran Request
                  </div>

                  <div class="d-flex gap-2">
                    <input
                      ref="fileRef"
                      type="file"
                      multiple
                      accept=".pdf,.jpg,.jpeg,.png"
                      class="d-none"
                      @change="handleFileUpload"
                    />

                    <VBtn
                      type="button"
                      color="primary"
                      variant="outlined"
                      size="small"
                      @click="triggerFileInput"
                    >
                      + Tambah Lampiran
                    </VBtn>
                  </div>
                </div>

                <VDivider class="mb-4" />

                <VAlert
                  v-if="errors.lampiran_request"
                  type="warning"
                  variant="tonal"
                  class="mb-4"
                >
                  {{ errors.lampiran_request }}
                </VAlert>

                <VAlert
                  v-if="!existingLampiranRequests.length && !form.lampiran_requests.length"
                  type="info"
                  variant="tonal"
                >
                  Belum ada lampiran yang diunggah.
                </VAlert>

                <VList
                  v-else
                  density="comfortable"
                  border
                  rounded
                >
                  <!-- Lampiran lama dari BE -->
                  <VListItem
                    v-for="(file, index) in existingLampiranRequests"
                    :key="`existing-${file.id}-${index}`"
                  >
                    <template #prepend>
                      <VIcon
                        :icon="getExistingFileType(file) === 'PDF'
                          ? 'mdi-file-pdf-box'
                          : 'mdi-file-image-outline'"
                      />
                    </template>

                    <VListItemTitle class="text-body-2">
                      <a
                        :href="file.filepath"
                        target="_blank"
                        class="text-decoration-none"
                      >
                        {{ file.original_filename }}
                      </a>
                    </VListItemTitle>

                    <VListItemSubtitle>
                      {{ getExistingFileType(file) }} • {{ formatExistingFileSize(file.filesize) }} • File Lama
                    </VListItemSubtitle>

                    <template #append>
                      <VBtn
                        type="button"
                        color="error"
                        variant="text"
                        size="small"
                        @click="removeExistingLampiran(index)"
                      >
                        Hapus
                      </VBtn>
                    </template>
                  </VListItem>

                  <!-- Lampiran baru dari FE -->
                  <VListItem
                    v-for="(file, index) in form.lampiran_requests"
                    :key="`new-${file.name}-${file.size}-${index}`"
                  >
                    <template #prepend>
                      <VIcon
                        :icon="getFileType(file) === 'PDF'
                          ? 'mdi-file-pdf-box'
                          : 'mdi-file-image-outline'"
                      />
                    </template>

                    <VListItemTitle class="text-body-2">
                      {{ file.name }}
                    </VListItemTitle>

                    <VListItemSubtitle>
                      {{ getFileType(file) }} • {{ formatFileSize(file.size) }} • File Baru
                    </VListItemSubtitle>

                    <template #append>
                      <VBtn
                        type="button"
                        color="error"
                        variant="text"
                        size="small"
                        @click="removeLampiran(index)"
                      >
                        Hapus
                      </VBtn>
                    </template>
                  </VListItem>
                </VList>
              </div>
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                label="Catatan"
                placeholder="Tambahkan catatan purchase request"
                rows="3"
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
            >
              Batal
            </VBtn>

            <VBtn
              type="button"
              color="primary"
              :loading="isSaving"
              @click.prevent.stop="updatePurchaseRequest"
            >
              Update
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>
    </VRow>
    <VDialog
      v-model="itemDialog"
      fullscreen
      scrollable
    >
      <VCard>
        <VToolbar color="primary">
          <VBtn
            icon
            variant="text"
            color="white"
            @click="closeItemDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>

          <VToolbarTitle>Input Daftar Item</VToolbarTitle>

          <VSpacer />

          <VBtn
            variant="flat"
            class="me-3"
            @click="saveItemsFromDialog"
          >
            Simpan Item
          </VBtn>
        </VToolbar>

        <VCardText>
          <VTable>
            <thead>
              <tr>
                <th>No</th>
                <th width="300px">Nama Item</th>
                <th width="100px">Qty</th>
                <th width="150px">Satuan</th>
                <th width="200px">Harga Satuan</th>
                <th width="170px" class="text-center">Subtotal</th>
                <th width="280" class="text-center">Keterangan</th>
                <th>Aksi</th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="(item, index) in tempItems"
                :key="index"
              >
                <td>{{ index + 1 }}</td>

                <td>
                  <VTextField
                    v-model="item.nama_item"
                    placeholder="Nama item"
                    density="compact"
                    hide-details
                  />
                </td>

                <td>
                  <VTextField
                    v-model.number="item.qty"
                    type="number"
                    density="compact"
                    hide-details
                  />
                </td>

                <td>
                  <VAutocomplete
                    v-model="item.satuan"
                    :items="units"
                    item-title="kode"
                    item-value="id"
                    placeholder="Satuan"
                    density="compact"
                    hide-details
                  />
                </td>

                <td>
                  <VTextField
                    :model-value="formatMoney(item.harga_unit)"
                    placeholder="Harga satuan"
                    density="compact"
                    hide-details="auto"
                    variant="outlined"
                    inputmode="numeric"
                    class="table-field text-right-field"
                    @keypress="onlyNumber"
                    @update:model-value="value => item.harga_unit = unformatMoney(value)"
                  />
                </td>

                <td class="text-end font-weight-bold">
                  {{ formatCurrency(Number(item.qty || 0) * Number(item.harga_unit || 0)) }}
                </td>

                <td>
                  <VTextField
                    v-model="item.keterangan"
                    placeholder="Keterangan"
                    density="compact"
                    hide-details
                  />
                </td>

                <td>
                  <VBtn
                    v-if="tempItems.length > 1"
                    icon
                    color="error"
                    variant="text"
                    @click="tempItems.splice(index, 1)"
                  >
                    <VIcon icon="tabler-trash" />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>

          <VBtn
            class="mt-4"
            color="primary"
            variant="tonal"
            prepend-icon="tabler-plus"
            @click="tempItems.push({
              nama_item: '',
              qty: 1,
              satuan: null,
              harga_unit: 0,
              subtotal: 0,
              keterangan: '',
            })"
          >
            Tambah Baris Item
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>
    <VDialog
      v-model="confirmCloseItemDialog"
      max-width="460"
      persistent
    >
      <VCard>
        <VCardTitle class="text-h6">
          Tutup Input Item?
        </VCardTitle>

        <VCardText>
          Perubahan item yang belum disimpan akan hilang.
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="confirmCloseItemDialog = false"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            @click="confirmCloseFullscreenItem"
          >
            Ya, Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
.vendor-item-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  padding: 8px;
}

.vendor-item-table {
  min-width: 1500px;
  border-collapse: separate;
  border-spacing: 0;
}

.vendor-item-table thead th {
  font-weight: 600;
  font-size: 13px;
  white-space: nowrap;
  background: #f8f9fb;
  padding: 14px 12px !important;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
  text-align: center !important;
  vertical-align: middle;
}

.vendor-item-table tbody td {
  vertical-align: top;
  padding: 12px !important;
}

.vendor-item-table tbody tr:not(:last-child) td {
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.vendor-item-table .table-field {
  min-width: 100%;
}

.vendor-item-table .text-right-field :deep(input) {
  text-align: right;
}

.vendor-item-table .unit-field {
  min-width: 180px;
}

/* Lebar kolom */
.vendor-item-table .col-no {
  width: 60px;
}

.vendor-item-table .col-item-name {
  min-width: 260px;
}

.vendor-item-table .col-qty {
  width: 120px;
}

.vendor-item-table .col-unit {
  width: 190px;
  min-width: 190px;
}

.vendor-item-table .col-price {
  min-width: 220px;
}

.vendor-item-table .col-subtotal {
  min-width: 220px;
}

.vendor-item-table .col-note {
  min-width: 300px;
}

.vendor-item-table .col-action {
  width: 90px;
}

/* Rapikan textarea */
.vendor-item-table :deep(.v-field) {
  border-radius: 10px;
}

.vendor-item-table :deep(.v-field__input) {
  min-height: 40px;
  padding-top: 0;
  padding-bottom: 0;
  align-items: center;
}

/* Khusus textarea biar tetap nyaman */
.vendor-item-table :deep(textarea) {
  padding-top: 8px;
  padding-bottom: 8px;
  line-height: 1.4;
}

.vendor-item-table :deep(.v-field--dirty input::placeholder) {
  opacity: 0 !important;
}

.vendor-item-table :deep(.v-autocomplete__selection) {
  max-width: 100%;
}

.vendor-item-table :deep(.v-autocomplete .v-field__input) {
  align-items: center;
}

.vendor-item-table :deep(.unit-field .v-field__input) {
  min-height: 40px !important;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
  display: flex !important;
  align-items: center !important;
}

.vendor-item-table :deep(.unit-field input) {
  align-self: center !important;
}

.vendor-item-table :deep(.unit-field .v-field__field) {
  display: flex !important;
  align-items: center !important;
}
</style>