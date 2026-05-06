<script setup lang="ts">
import { onMounted, reactive, ref, toRef } from 'vue'
import { useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import axios from '@axios'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  onlyNumberKeypress,
  formatSanitizedNumberInput,
  getClipboardText,
} from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'

interface PurchaseRequestForm {
  tanggal_pr: string
  cabang: string | number | null
  id_department: string | number | null
  kategori: string | null
  notes: string
  lampiran_requests: File[]
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

const router = useRouter()

const isSubmitted = ref(false)
const isSaving = ref(false)

const fileRef = ref<HTMLInputElement | null>(null)

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const vendorList = ref<VendorOption[]>([])

const units = ref<UnitItem[]>([])

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

const cabangList = [
  { id: 1, nama: 'Samarinda' },
  { id: 2, nama: 'Surabaya' },
  { id: 3, nama: 'Jakarta' },
  { id: 4, nama: 'Banjarmasin' },
  { id: 5, nama: 'Palembang' },
  { id: 6, nama: 'Sulawesi' },
  { id: 7, nama: 'HO' },
]

const departmentList = [
  { id: 1, nama: 'IT' },
  { id: 2, nama: 'GA' },
  { id: 3, nama: 'LOGISTIK' },
  { id: 4, nama: 'HRD' },
  { id: 5, nama: 'ADMIN' },
  { id: 6, nama: 'FINANCE' },
]

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

const loadUnits = async (): Promise<void> => {
  try {
    const response = await axios.get('/units')
    const payload = response?.data
    console.log('UNITS RESPONSE:', response.data)

    units.value = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : []
  } catch (error: unknown) {
    units.value = []

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat data satuan.'),
    })
  }
}

const loadVendors = async (): Promise<void> => {
  try {
    const res = await axios.get('/master/vendor/dropdown-select')

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    vendorList.value = data
  } catch (error: unknown) {
    vendorList.value = []

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat data vendor.'),
    })
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
      await showWarningAlert({
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

    showErrorAlert({
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
    await showWarningAlert({
      title: 'File tidak valid',
      text: invalidMessages.join(' '),
    })
  }

  input.value = ''
}

const removeLampiran = (index: number): void => {
  form.lampiran_requests.splice(index, 1)
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
    await showWarningAlert({
      title: 'Warning',
      text: 'Lengkapi data wajib.',
    })
    return false
  }

  const emptyVendorIndex = vendors.value.findIndex(vendor => !required(vendor.vendor_id))

  if (emptyVendorIndex !== -1) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Silahkan pilih vendor terlebih dahulu.',
    })
    return false
  }

  const hasRecommendedVendor = vendors.value.some(vendor => vendor.is_selected === true)

  if (!hasRecommendedVendor) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Pilih satu vendor rekomendasi.',
    })
    return false
  }

  for (const vendor of vendors.value) {
    if (!vendor.vendor_id) continue

    if (vendor.items.some(item => !required(item.nama_item))) {
      await showWarningAlert({
        title: 'Warning',
        text: 'Nama item wajib diisi.',
      })
      return false
    }

    if (vendor.items.some(item => !item.qty || Number(item.qty) <= 0)) {
      await showWarningAlert({
        title: 'Warning',
        text: 'Qty item wajib diisi.',
      })
      return false
    }

    if (vendor.items.some(item => !required(item.satuan))) {
      await showWarningAlert({
        title: 'Warning',
        text: 'Satuan item wajib dipilih.',
      })
      return false
    }

    if (vendor.items.some(item => item.harga_unit === null || Number(item.harga_unit) <= 0)) {
      await showWarningAlert({
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

  form.lampiran_requests.forEach(file => {
    formData.append('lampiran_request[]', file)
  })

  return formData
}

const savePurchaseRequest = async (): Promise<void> => {
  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    title: 'Simpan Purchase Request?',
    text: 'Pastikan data sudah benar.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan data...', 'Mohon tunggu sebentar')

    const formData = buildFormData()

    const response = await axios.post('/transaction/purchase-request', formData, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    await showSuccessAlert({
      title: 'Berhasil',
      text: response.data?.message || 'Purchase Request berhasil disimpan.',
    })

    await router.replace('/purchase_non_trading/purchase_request')

    return
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    console.error('[Purchase Request] SAVE ERROR:', err)

    if (err?.response?.status === 401) {
      await showErrorAlert({
        title: 'Sesi Login Berakhir',
        text: 'Silakan login ulang terlebih dahulu.',
      })

      localStorage.removeItem('accessToken')
      localStorage.removeItem('userData')
      localStorage.removeItem('navItems')

      await router.replace('/login')
      return
    }

    await showErrorAlert({
      title: 'Error',
      text:
        err?.response?.data?.message
        || getApiErrorMessage(error, 'Gagal menyimpan Purchase Request.'),
    })

    return
  } finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Batalkan perubahan?',
    text: 'Data yang sudah diisi akan hilang.',
    confirmButtonText: 'Ya, keluar',
    cancelButtonText: 'Batal',
  })

  if (confirm.isConfirmed) {
    await router.push('/purchase-requests')
  }
}

const goBack = async (): Promise<void> => {
  await router.replace({
    path: '/purchase_non_trading/purchase_request',
  })
}

onMounted(async () => {
  form.tanggal_pr = today()

  await loadUnits()
  await loadVendors()
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <div>
            <div class="text-h6 font-weight-bold">
              Form Purchase Request
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
                :error="isSubmitted && !form.cabang"
                :error-messages="isSubmitted && !form.cabang ? ['Cabang wajib dipilih'] : []"
                no-data-text="Cabang tidak ditemukan"
                placeholder="Pilih cabang"
              />
            </VCol>

            <VCol cols="12" md="3">
              <VAutocomplete
                v-model="form.id_department"
                label="Department *"
                :items="departmentList"
                item-title="nama"
                item-value="id"
                clearable
                density="comfortable"
                :menu-props="{ maxHeight: 300 }"
                :error="isSubmitted && !form.id_department"
                :error-messages="isSubmitted && !form.id_department ? ['Department wajib dipilih'] : []"
                no-data-text="Department tidak ditemukan"
                placeholder="Pilih department"
              />
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
                        :error="isSubmitted && !vendor.vendor_id"
                        :error-messages="isSubmitted && !vendor.vendor_id ? ['Vendor wajib dipilih'] : []"
                        no-data-text="Vendor tidak ditemukan"
                        placeholder="Pilih vendor"
                        @update:model-value="setVendorPKP(vIndex)"
                      >
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
                            color="warning"
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
                                  :menu-props="{
                                    maxHeight: 260,
                                    location: 'bottom',
                                    offset: 4
                                  }"
                                  :custom-filter="unitFilter"
                                  :error="isSubmitted && !item.satuan"
                                  :error-messages="isSubmitted && !item.satuan ? ['Satuan wajib dipilih'] : []"
                                >
                                  <template #item="{ props, item }">
                                    <VListItem
                                      v-bind="props"
                                      :title="`${item.raw?.kode ?? ''} - ${item.raw?.nama ?? ''}`"
                                      :subtitle="item.raw?.kategori ?? ''"
                                    />
                                  </template>

                                  <template #selection="{ item }">
                                    <span v-if="item?.raw?.kode">{{ item.raw.kode }}</span>
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
                  v-if="!form.lampiran_requests.length"
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
                  <VListItem
                    v-for="(file, index) in form.lampiran_requests"
                    :key="`${file.name}-${file.size}-${index}`"
                  >
                    <template #prepend>
                      <VIcon
                        :icon="getFileType(file) === 'PDF' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                      />
                    </template>

                    <VListItemTitle class="text-body-2">
                      {{ file.name }}
                    </VListItemTitle>

                    <VListItemSubtitle>
                      {{ getFileType(file) }} • {{ formatFileSize(file.size) }}
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
              @click.prevent.stop="savePurchaseRequest"
            >
              Simpan
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
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