<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
  sanitizeDecimalInput,
  parseDecimalInput,
  toTitleCase,
  formatDecimalQty,
} from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { useDisplay } from 'vuetify'

interface PrItem {
  nama_item: string
  qty: number
  satuan: number | string | null
  spesifikasi: string
  keterangan: string
  harga_unit: number
  subtotal: number
}

interface PurchaseRequestForm {
  tanggal_pr: string
  cabang: string | number | null
  id_department: string | number | null
  recommended_vendor_id: number | null
  kategori: string | null
  pr_type: string | null
  notes: string
  lampiran_requests: File[]
  items: PrItem[]
}

interface PurchaseRequestErrors {
  lampiran_request: string
}

interface ExistingPrAttachment {
  id: number
  filename: string
  original_filename: string
  filepath: string
  file_size: number
  mime_type: string
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

interface UnitItem {
  id: number
  kode: string
  nama: string
  kategori: string
}

const route = useRoute()
const router = useRouter()
const { mobile } = useDisplay()

const publicId = computed(() => String(route.query.id || ''))

const loadError = ref('')
const pageLoading = ref(true)
const isLoadingDetail = ref(false)
const isSubmitted = ref(false)
const isSaving = ref(false)

const fileRef = ref<HTMLInputElement | null>(null)

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const cabangList = ref<any[]>([])
const isLoadingCabang = ref(false)

const departmentList = ref<any[]>([])
const isLoadingDepartment = ref(false)

const vendorList = ref<any[]>([])
const isLoadingVendor = ref(false)

const units = ref<UnitItem[]>([])
const isLoadingUnits = ref(false)

const itemDialog = ref(false)
const confirmCloseItemDialog = ref(false)
const itemDialogSaved = ref(false)
const tempItems = ref<PrItem[]>([])

const existingLampiranRequests = ref<ExistingPrAttachment[]>([])

const createEmptyItem = (): PrItem => ({
  nama_item: '',
  qty: 1,
  satuan: null,
  spesifikasi: '',
  keterangan: '',
  harga_unit: 0,
  subtotal: 0,
})

const form = reactive<PurchaseRequestForm>({
  tanggal_pr: '',
  cabang: null,
  id_department: null,
  recommended_vendor_id: null,
  kategori: null,
  pr_type: null,
  notes: '',
  lampiran_requests: [],
  items: [createEmptyItem()],
})

const tanggalPR = useNativeDatePicker(toRef(form, 'tanggal_pr'))

const errors = reactive<PurchaseRequestErrors>({
  lampiran_request: '',
})

const kategoriList = ['Baru', 'Perbaikan', 'Improvement', 'Regular', 'Lain-lain']
const prTypeList = [
  'Rutin',
  'Non Rutin',
]

const today = (): string => new Date().toISOString().split('T')[0]

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
}

const getExtension = (fileName: string): string => {
  return fileName.split('.').pop()?.toLowerCase() || ''
}

const formatMoney = (value: number | null | undefined): string => {
  if (!value) return ''

  return new Intl.NumberFormat('id-ID').format(Number(value))
}

const onlyNumber = (e: KeyboardEvent): void => {
  onlyNumberKeypress(e)
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
          value: Number(item.id),
          title: `${item.inisial_cabang || '-'} - ${item.nama_cabang || item.title || '-'}`,
          nama_cabang: item.nama_cabang || item.title || '-',
          inisial_cabang: item.inisial_cabang || '',
        }))
      : []
  } catch (error: unknown) {
    console.error('[Cabang] FETCH ERROR:', error)
    cabangList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data cabang.'),
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

const loadVendors = async (showAlert = true): Promise<void> => {
  isLoadingVendor.value = true

  try {
    const response = await axios.get('/master/vendor/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    const data = Array.isArray(response.data?.data)
      ? response.data.data
      : Array.isArray(response.data)
        ? response.data
        : []

    vendorList.value = data.map((item: any) => ({
      id: Number(item.id),
      label: item.nama_vendor || item.title || '-',
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

const loadUnits = async (showAlert = true): Promise<void> => {
  isLoadingUnits.value = true

  try {
    const response = await axios.get('/units/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    const payload = response?.data

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

const unitFilter = (itemTitle: string, queryText: string, item: any): boolean => {
  const search = String(queryText ?? '').toLowerCase()
  const kode = String(item?.raw?.kode ?? '').toLowerCase()
  const nama = String(item?.raw?.nama ?? '').toLowerCase()
  const kategori = String(item?.raw?.kategori ?? '').toLowerCase()

  return kode.includes(search) || nama.includes(search) || kategori.includes(search)
}

const normalizeItem = (item: any): PrItem => {
  const qty = Number(item.qty || 1)
  const hargaUnit = Number(item.harga_unit || 0)

  return {
    nama_item: item.nama_item ?? '',
    qty,
    satuan: item.satuan_id !== null && item.satuan_id !== undefined
      ? Number(item.satuan_id)
      : item.satuan ?? null,
    spesifikasi: item.spesifikasi ?? '',
    keterangan: item.keterangan ?? '',
    harga_unit: hargaUnit,
    subtotal: Number(item.subtotal || qty * hargaUnit),
  }
}

const loadPurchaseRequestDetail = async (): Promise<void> => {
  if (!publicId.value) {
    loadError.value = 'ID Purchase Request tidak ditemukan.'
    return
  }

  isLoadingDetail.value = true
  loadError.value = ''

  try {
    const response = await axios.get(`/transaction/purchase-request/${publicId.value}/edit`, {
      headers: { Accept: 'application/json' },
    })

    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data purchase request tidak ditemukan.')
    }

    form.tanggal_pr = detail.tanggal_pr ?? ''

    form.cabang = detail.cabang_id !== null && detail.cabang_id !== undefined
      ? Number(detail.cabang_id)
      : null

    form.id_department = detail.department_id !== null && detail.department_id !== undefined
      ? Number(detail.department_id)
      : null

    form.recommended_vendor_id = detail.recommended_vendor_id !== null && detail.recommended_vendor_id !== undefined
      ? Number(detail.recommended_vendor_id)
      : null

    form.kategori = detail.kategori ?? null
    form.pr_type = detail.pr_type ?? null
    form.notes = detail.notes ?? ''
    form.lampiran_requests = []

    form.items = Array.isArray(detail.items) && detail.items.length
      ? detail.items.map((item: any) => normalizeItem(item))
      : [createEmptyItem()]

    existingLampiranRequests.value = Array.isArray(detail.attachments)
      ? detail.attachments.map((file: any) => ({
          id: Number(file.id),
          filename: file.filename || '-',
          original_filename: file.original_filename || '-',
          filepath: file.filepath || '#',
          file_size: Number(file.file_size || file.filesize || 0),
          mime_type: file.mime_type || file.filetype || '',
        }))
      : []
  } catch (error: unknown) {
    loadError.value = getApiErrorMessage(error, 'Gagal memuat detail Purchase Request.')
  } finally {
    isLoadingDetail.value = false
  }
}

const resetItems = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Reset semua item?',
    text: 'Semua item akan dihapus dan diganti dengan 1 baris kosong.',
    confirmButtonText: 'Ya, reset',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  form.items = [createEmptyItem()]
}

const updateItemSubtotal = (index: number): void => {
  const item = form.items[index]
  if (!item) return

  const qty = Number(item.qty || 0)
  const hargaUnit = Number(item.harga_unit || 0)

  item.subtotal = qty * hargaUnit
}

const handleItemPriceInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 12,
    emptyAsZero: true,
  })

  if (!form.items[index]) return

  form.items[index].harga_unit = result.numeric ?? 0
  updateItemSubtotal(index)

  target.value = result.formatted
}

const handleItemPricePaste = (event: ClipboardEvent, index: number): void => {
  const pastedText = event.clipboardData?.getData('text') || ''

  if (!/^\d+$/.test(pastedText.trim())) {
    event.preventDefault()

    showErrorToast({
      title: 'Input tidak valid',
      text: 'Harga hanya boleh berupa angka (0-9)',
    })

    return
  }

  if (!form.items[index]) return

  const target = event.target as HTMLInputElement
  const harga = Number(pastedText)

  form.items[index].harga_unit = harga
  updateItemSubtotal(index)

  target.value = formatMoney(harga)
}

const handleTempQtyInput = (value: string | number, index: number): void => {
  if (!tempItems.value[index]) return

  const sanitized = sanitizeDecimalInput(value, {
    maxIntegerLength: 12,
    maxDecimalLength: 2,
  })

  tempItems.value[index].qty = parseDecimalInput(sanitized)
  updateTempItemSubtotal(index)
}

const calcGrandTotal = (): number => {
  return form.items.reduce((total, item) => {
    return total + Number(item.subtotal || 0)
  }, 0)
}

const openItemFullscreen = (): void => {
  tempItems.value = JSON.parse(JSON.stringify(form.items))
  itemDialogSaved.value = false
  itemDialog.value = true
}

const closeItemDialog = (): void => {
  if (itemDialogSaved.value) {
    tempItems.value = []
    itemDialog.value = false
    return
  }

  confirmCloseItemDialog.value = true
}

const confirmCloseFullscreenItem = (): void => {
  confirmCloseItemDialog.value = false
  tempItems.value = []
  itemDialog.value = false
}

const saveItemsFromDialog = (): void => {
  if (!tempItems.value.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Minimal harus ada 1 item.',
    })

    return
  }

  const invalidItemIndex = tempItems.value.findIndex(item => {
    return (
      !required(item.nama_item)
      || !item.qty
      || Number(item.qty) <= 0
      || !required(item.satuan)
      || item.harga_unit === null
      || Number(item.harga_unit) <= 0
    )
  })

  if (invalidItemIndex !== -1) {
    showWarningToast({
      title: 'Warning',
      text: `Lengkapi data item nomor ${invalidItemIndex + 1}. Nama item, qty, satuan, dan harga satuan wajib diisi.`,
    })

    isSubmitted.value = true
    return
  }

  const normalizedItems = tempItems.value.map(item => {
    const qty = Number(item.qty || 0)
    const hargaUnit = Number(item.harga_unit || 0)

    return {
      ...item,
      qty,
      harga_unit: hargaUnit,
      subtotal: qty * hargaUnit,
    }
  })

  form.items = JSON.parse(JSON.stringify(normalizedItems))
  itemDialogSaved.value = true
  itemDialog.value = false
}

const addTempItemRow = (): void => {
  tempItems.value.push(createEmptyItem())
}

const removeTempItemRow = (index: number): void => {
  if (tempItems.value.length <= 1) return

  tempItems.value.splice(index, 1)
}

const updateTempItemSubtotal = (index: number): void => {
  const item = tempItems.value[index]
  if (!item) return

  const qty = Number(item.qty || 0)
  const hargaUnit = Number(item.harga_unit || 0)

  item.subtotal = qty * hargaUnit
}

const handleTempItemPriceInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 12,
    emptyAsZero: true,
  })

  if (!tempItems.value[index]) return

  tempItems.value[index].harga_unit = result.numeric ?? 0
  updateTempItemSubtotal(index)

  target.value = result.formatted
}

const handleTempItemPricePaste = (event: ClipboardEvent, index: number): void => {
  const pastedText = event.clipboardData?.getData('text') || ''

  if (!/^\d+$/.test(pastedText.trim())) {
    event.preventDefault()

    showErrorToast({
      title: 'Input tidak valid',
      text: 'Harga hanya boleh berupa angka (0-9)',
    })

    return
  }

  if (!tempItems.value[index]) return

  const target = event.target as HTMLInputElement
  const harga = Number(pastedText)

  tempItems.value[index].harga_unit = harga
  updateTempItemSubtotal(index)

  target.value = formatMoney(harga)
}

const calcTempGrandTotal = (): number => {
  return tempItems.value.reduce((total, item) => {
    return total + Number(item.subtotal || 0)
  }, 0)
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

    if (!exists) form.lampiran_requests.push(file)
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

const removeLampiran = (index: number): void => {
  form.lampiran_requests.splice(index, 1)
}

const removeExistingLampiran = (index: number): void => {
  existingLampiranRequests.value.splice(index, 1)
}

const formatFileSize = (bytes: number): string => {
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const formatExistingFileSize = (bytes: number): string => {
  if (!bytes) return '-'

  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const getFileType = (file: File): string => {
  return file.type === 'application/pdf' ? 'PDF' : 'IMAGE'
}

const getExistingFileType = (file: ExistingPrAttachment): string => {
  const type = String(file.mime_type || '').toLowerCase()
  const name = String(file.filename || '').toLowerCase()

  if (type.includes('pdf') || name.endsWith('.pdf')) return 'PDF'

  return 'IMAGE'
}

const validateForm = async (): Promise<boolean> => {
  if (
    !required(form.tanggal_pr)
    || !required(form.cabang)
    || !required(form.id_department)
    || !required(form.kategori)
    || !required(form.pr_type)
  ) {
    showWarningToast({
      title: 'Warning',
      text: 'Lengkapi data wajib.',
    })

    return false
  }

  if (!form.items.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Minimal harus ada 1 item.',
    })

    return false
  }

  if (form.items.some(item => !required(item.nama_item))) {
    showWarningToast({
      title: 'Warning',
      text: 'Nama item wajib diisi.',
    })

    return false
  }

  if (form.items.some(item => !item.qty || Number(item.qty) <= 0)) {
    showWarningToast({
      title: 'Warning',
      text: 'Qty item wajib diisi.',
    })

    return false
  }

  if (form.items.some(item => !required(item.satuan))) {
    showWarningToast({
      title: 'Warning',
      text: 'Satuan item wajib dipilih.',
    })

    return false
  }

  if (form.items.some(item => item.harga_unit === null || Number(item.harga_unit) <= 0)) {
    showWarningToast({
      title: 'Warning',
      text: 'Harga satuan item wajib diisi.',
    })

    return false
  }

  return true
}

const buildFormData = (): FormData => {
  const formData = new FormData()

  formData.append('tanggal_pr', String(form.tanggal_pr || ''))
  formData.append('cabang', String(form.cabang || ''))
  formData.append('id_department', String(form.id_department || ''))
  formData.append('recommended_vendor_id', form.recommended_vendor_id ? String(form.recommended_vendor_id) : '')
  formData.append('kategori', String(form.kategori || ''))
  formData.append('pr_type', String(form.pr_type || ''))
  formData.append('notes', String(form.notes || ''))
  formData.append('grand_total', String(calcGrandTotal()))

  formData.append(
    'existing_attachment_ids',
    JSON.stringify(existingLampiranRequests.value.map(file => file.id)),
  )

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        nama_item: item.nama_item,
        qty: Number(item.qty || 0),
        satuan: item.satuan,
        spesifikasi: item.spesifikasi || '',
        keterangan: item.keterangan || '',
        harga_unit: Number(item.harga_unit || 0),
        subtotal: Number(item.subtotal || 0),
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

const updatePurchaseRequest = async (event?: Event): Promise<void> => {
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

    const response = await axios.post(`/transaction/purchase-request/${publicId.value}`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
        Accept: 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_trade/purchase_request',
      query: { success: 'updated' },
    })
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: err?.response?.data?.message || getApiErrorMessage(error, 'Gagal memperbarui Purchase Request.'),
    })
  } finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Batalkan perubahan?',
    text: 'Data yang sudah diubah tidak akan tersimpan. Apakah Anda yakin?',
    confirmButtonText: 'Ya',
    cancelButtonText: 'Tidak',
  })

  if (confirm.isConfirmed) {
    await goBack()
  }
}

const goBack = async (): Promise<void> => {
  await router.replace({
    path: '/non_trade/purchase_request',
  })
}

onMounted(async () => {
  form.tanggal_pr = today()

  pageLoading.value = true
  loadError.value = ''

  try {
    await Promise.all([
      loadUnits(false),
      loadVendors(false),
      fetchCabangList(false),
      fetchDepartmentList(false),
    ])

    await loadPurchaseRequestDetail()
  } catch (error: unknown) {
    loadError.value = getApiErrorMessage(error, 'Gagal memuat data Purchase Request.')
  } finally {
    pageLoading.value = false
  }
})
</script>

<template>
    <VCard
      v-if="pageLoading || isLoadingDetail"
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
            Memuat data Purchase Request...
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
          <div class="me-4">
            <VAvatar
              size="44"
              color="error"
              variant="tonal"
            >
              <VIcon
                icon="tabler-alert-circle"
                size="24"
              />
            </VAvatar>
          </div>

          <div>
            <div class="text-h6 font-weight-bold text-error mb-1">
              {{ loadError }}
            </div>

            <div class="text-caption text-disabled mt-2">
              Silakan coba muat ulang data. Jika masalah masih berlanjut, periksa koneksi atau hubungi tim IT.
            </div>
          </div>
        </div>

        <div class="d-flex ga-2 flex-wrap">
          <VBtn
            color="primary"
            :loading="isLoadingDetail"
            prepend-icon="tabler-refresh"
            @click="loadPurchaseRequestDetail"
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
  
  <section v-else>
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
              <VCol cols="12" md="4">
                <AppDateTimePicker
                  v-model="form.tanggal_pr"
                  label="Tanggal PR *"
                  placeholder="Pilih tanggal PR"
                  :config="{ dateFormat: 'Y-m-d' }"
                  :error="isSubmitted && !form.tanggal_pr"
                  :error-messages="isSubmitted && !form.tanggal_pr ? ['Tanggal PR wajib diisi'] : []"
                />
              </VCol>

              <VCol cols="12" md="4">
                <VAutocomplete
                  v-model="form.cabang"
                  label="Cabang *"
                  :items="cabangList"
                  item-title="title"
                  item-value="value"
                  clearable
                  density="comfortable"
                  :loading="isLoadingCabang"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
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

              <VCol cols="12" md="4">
                <VAutocomplete
                  v-model="form.id_department"
                  label="Department *"
                  :items="departmentList"
                  item-title="label"
                  item-value="id"
                  clearable
                  density="comfortable"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
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

              <VCol cols="12" md="4">
                <VAutocomplete
                  v-model="form.kategori"
                  label="Kategori *"
                  :items="kategoriList"
                  clearable
                  density="comfortable"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
                  :error="isSubmitted && !form.kategori"
                  :error-messages="isSubmitted && !form.kategori ? ['Kategori wajib dipilih'] : []"
                  no-data-text="Kategori tidak ditemukan"
                  placeholder="Pilih kategori"
                />
              </VCol>

              <VCol cols="12" md="4">
                <VAutocomplete
                  v-model="form.pr_type"
                  label="Tipe PR *"
                  :items="prTypeList"
                  clearable
                  density="comfortable"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
                  :error="isSubmitted && !form.pr_type"
                  :error-messages="isSubmitted && !form.pr_type ? ['Tipe PR wajib dipilih'] : []"
                  no-data-text="Tipe PR tidak ditemukan"
                  placeholder="Pilih tipe PR"
                />
              </VCol>
            </VRow>
            <VRow>
              <!-- DAFTAR ITEM SUMMARY -->
              <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-3">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Daftar Item
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Input dan edit item melalui mode fullscreen.
                    </div>
                  </div>

                  <div class="d-flex align-center flex-wrap gap-2">
                    <VBtn
                      type="button"
                      color="primary"
                      variant="tonal"
                      size="small"
                      prepend-icon="tabler-plus"
                      @click="openItemFullscreen"
                    >
                      Tambah Item
                    </VBtn>

                    <VBtn
                      type="button"
                      color="error"
                      variant="outlined"
                      size="small"
                      @click="resetItems"
                    >
                      Reset Item
                    </VBtn>
                  </div>
                </div>

                <VCard
                  flat
                  class="item-summary-card"
                >
                  <VCardText>
                    <VAlert
                      v-if="!form.items.length || form.items.every(item => !item.nama_item)"
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      Belum ada item. Klik <strong>Tambah Item</strong> untuk menambahkan item.
                    </VAlert>

                    <div v-else class="d-flex flex-column gap-3">
                      <div
                        v-for="(item, index) in form.items"
                        :key="`summary-item-${index}`"
                        class="item-summary-row"
                      >
                        <div class="d-flex align-start gap-3">
                          <VAvatar
                            size="30"
                            color="primary"
                            variant="tonal"
                          >
                            {{ index + 1 }}
                          </VAvatar>

                          <div class="flex-grow-1">
                            <div class="font-weight-bold">
                              {{ toTitleCase(item.nama_item) || '-' }}
                            </div>

                            <div class="text-caption text-medium-emphasis mt-1">
                              Qty: <strong>{{ formatDecimalQty(item.qty) || 0 }}</strong>
                              <span class="mx-1">•</span>
                              Satuan:
                              <strong>
                                {{
                                  units.find(unit => Number(unit.id) === Number(item.satuan))?.nama
                                  || item.satuan
                                  || '-'
                                }}
                              </strong>
                              <span class="mx-1">•</span>
                              Harga:
                              <strong>Rp {{ formatMoney(item.harga_unit) || '0' }}</strong>
                            </div>

                            <div
                              v-if="item.keterangan"
                              class="text-caption text-medium-emphasis mt-1 text-pre-line"
                            >
                              Keterangan: <br> {{ item.keterangan }}
                            </div>
                          </div>

                          <div class="text-end">
                            <div class="text-caption text-medium-emphasis">
                              Subtotal
                            </div>
                            <div class="font-weight-bold">
                              Rp {{ formatMoney(item.subtotal) || '0' }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <VDivider class="my-4" />

                    <div class="d-flex justify-end">
                      <div class="item-grand-total">
                        <span>Grand Total</span>
                        <strong> Rp {{ formatMoney(calcGrandTotal()) || '0' }}</strong>
                      </div>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <!-- VENDOR REKOMENDASI -->
              <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-2">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Vendor Rekomendasi
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Opsional, 1 PR hanya dapat memilih 1 vendor rekomendasi.
                    </div>
                  </div>
                </div>

                <VDivider class="mb-4" />
              </VCol>

              <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="form.recommended_vendor_id"
                  label="Vendor Rekomendasi"
                  :items="vendorList"
                  item-title="label"
                  item-value="id"
                  clearable
                  density="comfortable"
                  :loading="isLoadingVendor"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
                  no-data-text="Vendor tidak ditemukan"
                  placeholder="Pilih vendor rekomendasi"
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
                </VAutocomplete>
              </VCol>

              <!-- LAMPIRAN -->
            <VCol cols="12">
              <div class="mt-4">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-2">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Lampiran Request
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      File lama tetap dipertahankan selama tidak dihapus.
                    </div>
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
                        :color="getExistingFileType(file) === 'PDF' ? 'error' : 'primary'"
                      />
                    </template>

                    <VListItemTitle class="text-body-2">
                      <a
                        :href="file.filepath"
                        target="_blank"
                        class="text-decoration-none"
                      >
                        {{ file.original_filename || file.filename || 'Lampiran lama' }}
                      </a>
                    </VListItemTitle>

                    <VListItemSubtitle>
                      {{ getExistingFileType(file) }}
                      •
                      {{ formatExistingFileSize(file.file_size) }}
                      • File Lama
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
                        :color="getFileType(file) === 'PDF' ? 'error' : 'primary'"
                      />
                    </template>

                    <VListItemTitle class="text-body-2">
                      {{ file.name }}
                    </VListItemTitle>

                    <VListItemSubtitle>
                      {{ getFileType(file) }}
                      •
                      {{ formatFileSize(file.size) }}
                      • File Baru
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
                class="text-none"
              >
                Batal
              </VBtn>

              <VBtn
                type="button"
                color="primary"
                :loading="isSaving"
                @click.prevent.stop="updatePurchaseRequest($event)"
                class="text-none"
              >
                Simpan
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

        <VCardText class="pa-4 item-fullscreen-body">
          <div class="item-fullscreen-table-wrapper">
            <VTable class="item-fullscreen-table">
              <thead>
                <tr>
                  <th class="col-no">No</th>
                  <th class="col-name">Nama Item</th>
                  <th class="col-qty">Qty</th>
                  <th class="col-unit">Satuan</th>
                  <th class="col-price">Harga Satuan</th>
                  <th class="col-subtotal">Subtotal</th>
                  <th class="col-note">Keterangan</th>
                  <th class="col-action text-center">Aksi</th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(item, index) in tempItems"
                  :key="`temp-item-${index}`"
                >
                  <td>{{ index + 1 }}</td>

                  <td>
                    <VTextField
                      v-model="item.nama_item"
                      placeholder="Nama item"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      class="fullscreen-field"
                      :error="isSubmitted && !item.nama_item"
                      :error-messages="isSubmitted && !item.nama_item ? ['Nama item wajib diisi'] : []"
                    />
                  </td>

                  <td>
                    <VTextField
                      :model-value="item.qty"
                      type="text"
                      inputmode="decimal"
                      min="0.01"
                      placeholder="Qty"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      class="fullscreen-field"
                      :error="isSubmitted && (!item.qty || Number(item.qty) <= 0)"
                      :error-messages="isSubmitted && (!item.qty || Number(item.qty) <= 0) ? ['Qty wajib diisi'] : []"
                      @update:model-value="value => handleTempQtyInput(value, index)"
                    />
                  </td>

                  <td>
                    <VAutocomplete
                      v-model="item.satuan"
                      :items="units"
                      item-title="nama"
                      item-value="id"
                      placeholder="Satuan"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      class="fullscreen-field"
                      :loading="isLoadingUnits"
                      :menu-props="{
                        location: 'bottom',
                        offset: 8,
                        maxHeight: 300,
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

                  <td>
                    <VTextField
                      :model-value="formatMoney(item.harga_unit)"
                      placeholder="Harga satuan"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      inputmode="numeric"
                      class="text-right-field fullscreen-field"
                      :error="isSubmitted && (item.harga_unit === null || Number(item.harga_unit) <= 0)"
                      :error-messages="isSubmitted && (item.harga_unit === null || Number(item.harga_unit) <= 0) ? ['Harga wajib diisi'] : []"
                      @keypress="onlyNumber"
                      @input="handleTempItemPriceInput($event, index)"
                      @paste.prevent="handleTempItemPricePaste($event, index)"
                    />
                  </td>

                  <td>
                    <VTextField
                      :model-value="formatMoney(item.subtotal)"
                      density="compact"
                      hide-details
                      variant="outlined"
                      readonly
                      class="text-right-field fullscreen-field"
                    />
                  </td>

                  <td>
                    <VTextarea
                      v-model="item.keterangan"
                      placeholder="Keterangan / Spesifikasi"
                      density="compact"
                      hide-details
                      variant="outlined"
                      class="fullscreen-field fullscreen-textarea"
                      rows="2"
                      auto-grow
                    />
                  </td>

                  <td class="text-center">
                    <VBtn
                      v-if="tempItems.length > 1"
                      icon
                      color="error"
                      variant="text"
                      size="small"
                      @click="removeTempItemRow(index)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>

                <tr v-if="!tempItems.length">
                  <td
                    colspan="8"
                    class="text-center text-medium-emphasis py-6"
                  >
                    Belum ada item.
                  </td>
                </tr>
              </tbody>
            </VTable>
            <div class="d-flex justify-space-between align-center mt-4">
              <VBtn
                color="primary"
                variant="tonal"
                prepend-icon="tabler-plus"
                @click="addTempItemRow"
              >
                Tambah Baris Item
              </VBtn>

              <div class="text-body-1 font-weight-bold">
                Grand Total:
                <strong>{{ formatMoney(calcTempGrandTotal()) }}</strong>
              </div>
            </div>
          </div>
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
.item-fullscreen-body {
  overflow-x: hidden;
}

.item-fullscreen-table-wrapper {
  width: 100%;
  overflow-x: auto;
}

.item-fullscreen-table {
  width: 100%;
  min-width: 980px;
  table-layout: fixed;
}

.item-fullscreen-table th,
.item-fullscreen-table td {
  padding: 10px 8px !important;
  vertical-align: top;
}

.item-fullscreen-table .col-no {
  width: 44px;
}

.item-fullscreen-table .col-name {
  width: 230px;
}

.item-fullscreen-table .col-qty {
  width: 80px;
}

.item-fullscreen-table .col-unit {
  width: 130px;
}

.item-fullscreen-table .col-price {
  width: 160px;
}

.item-fullscreen-table .col-subtotal {
  width: 160px;
}

.item-fullscreen-table .col-note {
  width: 220px;
}

.item-fullscreen-table .col-action {
  width: 70px;
}

.fullscreen-field :deep(.v-field__input) {
  min-height: 38px !important;
  padding-top: 6px !important;
  padding-bottom: 6px !important;
  font-size: 14px;
}

.fullscreen-field :deep(.v-messages) {
  font-size: 11px;
  line-height: 1.1;
}

@media (max-width: 1200px) {
  .item-fullscreen-table {
    min-width: 900px;
  }

  .item-fullscreen-table .col-name {
    width: 200px;
  }

  .item-fullscreen-table .col-note {
    width: 180px;
  }
}

.fullscreen-textarea {
  min-width: 260px;
}

.fullscreen-textarea :deep(textarea) {
  line-height: 1.4;
  resize: vertical;
}
</style>