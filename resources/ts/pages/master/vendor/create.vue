<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch, nextTick, toRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { toUpper, toLower, onlyNumber, formatEmail, validateEmail, emailValidationMessage } from '@/utils/textFormatter'
import {
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  closeAlert,
} from '@/utils/alert'
import Swal from 'sweetalert2'
import axios from '@axios'
import { getApiErrorMessage } from '@/utils/apiHelper'

interface VendorForm {
  nama_vendor: string
  inisial_vendor: string
  telepon: string
  fax: string
  email: string
  jenis_perusahaan: string | null
  kategori_vendor: string | null
  id_department: string | number | null
  nomor_ktp: string
  contact_nama: string
  contact_jabatan: string
  contact_hp: string
  contact_email: string
  alamat: string
  status_pkp: string
  npwp: string
  npwp_alamat: string
  sppkp_nomor: string
  sppkp_tanggal: string
  sppkp_alamat: string
  same_as_npwp: boolean
  transaksi_ids: number[]
  jenis_pembayaran: string
  top: number
}

interface BankForm {
  bank_id: number | null
  nama_bank: string
  nama_bank_pendek: string
  kode_bank: string
  swift_code: string
  atas_nama: string
  nomor_rekening: string
  cabang: string
  alamat_bank: string
}

interface MasterBankItem {
  id: number
  kode_bank: string | null
  nama_bank: string
  nama_bank_pendek: string | null
  swift_code: string | null
  is_active: boolean
}

interface MasterDokumenItem {
  id: number
  nama_dokumen: string
  deskripsi: string
  is_required: boolean
}

interface DepartmentOption {
  id: number
  nama: string
  kode?: string
}

interface VendorResponsePayload {
  [key: string]: unknown
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

interface FileMap {
  [key: number]: File[]
}

interface MasterTransaksiItem {
  id: number
  kategori: string
  pasal_pajak: string
}

const router = useRouter()
const route = useRoute()

const isSubmitted = ref(false)
const isSaving = ref(false)
const showTopInput = ref(false)
const showEktp = ref(false)
const loadingTransaksi = ref(false)
const loadingDokumen = ref(false)
const showInfoNamaVendor = ref(false)

const transaksiError = ref<string | null>(null)
const dokumenError = ref<string | null>(null)

const masterTransaksi = ref<MasterTransaksiItem[]>([])
const masterDokumen = ref<MasterDokumenItem[]>([])
const selectedDokumen = ref<number[]>([])

const dokumenFiles = reactive<Record<number, File[]>>({})
const fileInputModels = reactive<Record<number, File[]>>({})

const loadingBanks = ref(false)
const bankError = ref<string | null>(null)
const masterBanks = ref<MasterBankItem[]>([])

const departmentOptions = ref<DepartmentOption[]>([])
const loadingDepartments = ref(false)


const form = reactive<VendorForm>({
  nama_vendor: '',
  inisial_vendor: '',
  telepon: '',
  fax: '',
  email: '',
  jenis_perusahaan: null,
  kategori_vendor: null,
  id_department: null,
  nomor_ktp: '',
  contact_nama: '',
  contact_jabatan: '',
  contact_hp: '',
  contact_email: '',
  alamat: '',
  status_pkp: 'PKP',
  npwp: '',
  npwp_alamat: '',
  sppkp_nomor: '',
  sppkp_tanggal: '',
  sppkp_alamat: '',
  same_as_npwp: false,
  transaksi_ids: [] as number[],
  jenis_pembayaran: '',
  top: 0
})

const banks = ref<BankForm[]>([
  {
    bank_id: null,
    nama_bank: '',
    nama_bank_pendek: '',
    kode_bank: '',
    swift_code: '',
    atas_nama: '',
    nomor_rekening: '',
    cabang: '',
    alamat_bank: '',
  },
])

const sppkpDate = useNativeDatePicker(toRef(form, 'sppkp_tanggal'))
const isPKP = computed<boolean>(() => form.status_pkp === 'PKP')

const createEmptyBank = (): BankForm => ({
  bank_id: null,
  nama_bank: '',
  nama_bank_pendek: '',
  kode_bank: '',
  swift_code: '',
  atas_nama: '',
  nomor_rekening: '',
  cabang: '',
  alamat_bank: '',
})

const addBank = (): void => {
  banks.value.push(createEmptyBank())
}

const removeBank = (index: number): void => {
  if (banks.value.length > 1) {
    banks.value.splice(index, 1)
  }
}

const loadMasterBanks = async (): Promise<void> => {
  loadingBanks.value = true
  bankError.value = null

  try {
    const res = await axios.get('/master/banks', {
      params: {
        is_active: 1,
      },
    })

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    masterBanks.value = data
  } catch (error: any) {
    bankError.value = 'Data master bank gagal dimuat'

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat master bank.'),
    })

    masterBanks.value = []
  } finally {
    loadingBanks.value = false
  }
}

const handleSelectBank = (index: number): void => {
  const selectedId = banks.value[index].bank_id

  if (!selectedId) {
    banks.value[index].nama_bank = ''
    banks.value[index].nama_bank_pendek = ''
    banks.value[index].kode_bank = ''
    banks.value[index].swift_code = ''
    return
  }

  const selectedBank = masterBanks.value.find(
    item => item.id === Number(selectedId),
  )

  if (!selectedBank) {
    banks.value[index].nama_bank = ''
    banks.value[index].nama_bank_pendek = ''
    banks.value[index].kode_bank = ''
    banks.value[index].swift_code = ''
    return
  }

  banks.value[index].nama_bank = selectedBank.nama_bank || ''
  banks.value[index].nama_bank_pendek = selectedBank.nama_bank_pendek || ''
  banks.value[index].kode_bank = selectedBank.kode_bank || ''
  banks.value[index].swift_code = selectedBank.swift_code || ''
}

const filterMasterBank = (
  itemTitle: string,
  queryText: string,
  item: any,
): boolean => {
  const searchText = String(queryText || '').toLowerCase()
  const raw = item?.raw || {}

  return [
    raw.nama_bank,
    raw.nama_bank_pendek,
    raw.kode_bank,
    raw.swift_code,
  ]
    .filter(Boolean)
    .some(value => String(value).toLowerCase().includes(searchText))
}

const loadTransaksi = async (): Promise<void> => {
  loadingTransaksi.value = true
  transaksiError.value = null

  try {
    const res = await axios.get('/master/keterangan-transaksi')

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    if (!data.length) {
      showErrorToast({
        title: 'Data Kosong',
        text: 'Data keterangan transaksi tidak ditemukan',
      })
      transaksiError.value = 'Data keterangan transaksi tidak tersedia'
    }

    masterTransaksi.value = data
  } catch (error: any) {
    transaksiError.value = 'Keterangan transaksi gagal dimuat'
    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat keterangan transaksi.'),
    })

    masterTransaksi.value = []
  } finally {
    loadingTransaksi.value = false
  }
}

const fetchDepartments = async (showAlert = true): Promise<void> => {
  loadingDepartments.value = true

  try {
    const response = await axios.get('/master/department/dropdown-select', {
      headers: {
        Accept: 'application/json',
      },
    })

    departmentOptions.value = Array.isArray(response.data?.data)
    ? response.data.data.map((item: any) => ({
        id: Number(item.id),
        kode: item.kode || '',
        nama: item.nama || item.title || '-',
        label: `${item.kode || '-'} - ${item.nama || item.title || '-'}`,
      }))
    : []
  } catch (error: unknown) {
    console.error('[Department] FETCH ERROR:', error)

    departmentOptions.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data department'),
      })
    }
  } finally {
    loadingDepartments.value = false
  }
}

const loadMasterDokumen = async (): Promise<void> => {
  loadingDokumen.value = true
  dokumenError.value = null

  try {
    const res = await axios.get('/master/dokumen-pendukung')

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    if (!data.length) {
      showErrorToast({
        title: 'Data Kosong',
        text: 'Data dokumen pendukung tidak ditemukan',
      })
      dokumenError.value = 'Data dokumen pendukung tidak tersedia'
    }

    masterDokumen.value = data
  } catch (error: any) {
    dokumenError.value = 'Data dokumen pendukung gagal dimuat'
    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat dokumen pendukung.'),
    })

    masterDokumen.value = []
  } finally {
    loadingDokumen.value = false
  }
}

const allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf']
const maxFileSize = 3 * 1024 * 1024
const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png']
const getExtension = (fileName: string): string => {
  return fileName.split('.').pop()?.toLowerCase() || ''
}

const isValidFile = (file: File): { valid: boolean; message?: string } => {
  const ext = getExtension(file.name)
  const mime = file.type?.toLowerCase()

  const validMime = allowedMimeTypes.includes(mime)
  const validExt = allowedExtensions.includes(ext)

  if (!validMime && !validExt) {
    return {
      valid: false,
      message: `"${file.name}" bukan file PDF/JPG/JPEG/PNG.`,
    }
  }

  if (file.size > maxFileSize) {
    return {
      valid: false,
      message: `"${file.name}" lebih dari 3MB.`,
    }
  }

  return { valid: true }
}

const onFileChange = async (value: any, docId: number): Promise<void> => {

  if (!value) {
    fileInputModels[docId] = []
    return
  }

  const files = Array.isArray(value) ? value : [value]
  const validFiles: File[] = []
  const errors: string[] = []

  for (const file of files) {

    // pastikan benar file
    if (!(file instanceof File)) continue

    const name = file.name ?? ''
    const ext = name.includes('.') ? name.split('.').pop()?.toLowerCase() : ''

    if (!ext || !allowedExtensions.includes(ext)) {
      errors.push(`"${name}" bukan file PDF/JPG/JPEG/PNG`)
      continue
    }

    if (file.size > maxFileSize) {
      errors.push(`"${name}" lebih dari 3MB`)
      continue
    }

    validFiles.push(file)
  }

  if (errors.length) {
    await Swal.fire({
      icon: 'error',
      title: 'File tidak valid',
      html: errors.join('<br>')
    })
  }

  dokumenFiles[docId] = [...(dokumenFiles[docId] || []), ...validFiles]
  fileInputModels[docId] = []
}

const removeFile = (docId: number, index: number): void => {
  if (!dokumenFiles[docId]) return

  dokumenFiles[docId] = dokumenFiles[docId].filter((_, i) => i !== index)

  // sinkronkan juga file input model
  fileInputModels[docId] = []
}

watch(selectedDokumen, currentValue => {
  const keep = new Set(currentValue)

  Object.keys(dokumenFiles)
    .map(Number)
    .forEach(id => {
      if (!keep.has(id)) {
        delete dokumenFiles[id]
      }
    })
})

watch(
  () => form.jenis_pembayaran,
  value => {
    showTopInput.value = value === 'TOP'
    if (value !== 'TOP') {
      form.top = 0
    }
  },
  { immediate: true },
)

watch(
  () => form.jenis_perusahaan,
  value => {
    showEktp.value = value === '1'
  },
  { immediate: true },
)

watch(
  () => form.same_as_npwp,
  checked => {
    if (checked) {
      form.sppkp_alamat = form.npwp_alamat
    }
  },
)

const validateBanks = async (): Promise<boolean> => {
  const bankList = banks.value || []

  for (let i = 0; i < bankList.length; i++) {
    const bank = bankList[i]

    const bankId = bank.bank_id
    const atasNama = String(bank.atas_nama || '').trim()
    const nomorRekening = String(bank.nomor_rekening || '').trim()
    const cabang = String(bank.cabang || '').trim()
    const alamatBank = String(bank.alamat_bank || '').trim()

    const allEmpty =
      !bankId &&
      atasNama === '' &&
      nomorRekening === '' &&
      cabang === '' &&
      alamatBank === ''

    if (allEmpty) continue

    const missingFields: string[] = []

    if (!bankId) missingFields.push('Nama Bank')
    if (!atasNama) missingFields.push('Atas Nama')
    if (!nomorRekening) missingFields.push('Nomor Rekening')

    if (missingFields.length) {
      showErrorToast({
        title: 'Data Bank Belum Lengkap',
        text: `Data bank ke-${i + 1} belum lengkap. Lengkapi: ${missingFields.join(', ')}`,
      })

      return false
    }
  }

  return true
}

const isBankRowFilled = (bank: any): boolean => {
  return Boolean(
    bank.bank_id
    || String(bank.atas_nama || '').trim()
    || String(bank.nomor_rekening || '').trim()
    || String(bank.cabang || '').trim()
    || String(bank.alamat_bank || '').trim(),
  )
}

const validateDokumen = async (): Promise<boolean> => {
  for (const id of selectedDokumen.value) {
    if (!dokumenFiles[id] || dokumenFiles[id].length === 0) {
      await Swal.fire('Peringatan', 'Lampiran wajib diunggah untuk dokumen yang dipilih.', 'warning')
      return false
    }
  }

  return true
}

const isValidNPWP = (value: string | null | undefined): boolean => {
  if (!value) return true // boleh kosong

  const digits = value.replace(/\D/g, '')

  return digits.length === 16
}

const handleNamaVendor = (event: InputEvent): void => {
  const inputEl = event.target as HTMLInputElement
  let value = inputEl.value

  // 🔥 paksa hapus semua titik
  value = value.replace(/\./g, '')

  // uppercase semua
  value = value.toUpperCase()

  // handle delete tetap normal
  if (
    event.inputType === 'deleteContentBackward'
    || event.inputType === 'deleteContentForward'
  ) {
    form.nama_vendor = value
    return
  }

  form.nama_vendor = value

  // pastikan format PT / CV / UD / PD tanpa titik
  if (/^(PT|CV|UD|PD)\s(?!\s)/.test(form.nama_vendor)) {
    form.nama_vendor = form.nama_vendor.replace(/^(PT|CV|UD|PD)\s+/, '$1 ')
  }

}

const confirmCancel = async (): Promise<void> => {
  const result = await showConfirmAlert({
    title: 'Batalkan perubahan?',
    text: 'Data yang sudah diisi tidak akan tersimpan. Apakah Anda yakin?',
    confirmButtonText: 'Ya, batal',
    cancelButtonText: 'Tidak',
  })

  if (result.isConfirmed) {
    router.push('/master/vendor')
  }
}

const validateForm = async (): Promise<boolean> => {
  let isValid = true
  if (!form.nama_vendor || !form.jenis_perusahaan || !form.status_pkp || !form.kategori_vendor) {
    showWarningToast({
        title: 'Warning',
        text: 'Silakan isi semua kolom wajib.',
    })
    return false
  }

  if (form.telepon && !/^[0-9]+$/.test(form.telepon)) {
    showWarningToast({
        title: 'Warning',
        text: 'Nomor telepon hanya boleh angka.',
    })
    return false
  }

  if (form.contact_email && !validateEmail(form.contact_email)) {
      showWarningToast({
          title: 'Warning',
          text: emailValidationMessage,
      })
      return false
  }

  if (!form.jenis_pembayaran) {
    showWarningToast({
        title: 'Warning',
        text: 'Pilih sistem pembayaran.',
    })
    return false
  }

  if (form.jenis_pembayaran === 'TOP' && (!form.top || form.top <= 0)) {
    showWarningToast({
        title: 'Warning',
        text: 'Isi jumlah hari TOP.',
    })
    return false
  }

  if (form.email && !validateEmail(form.email)) {
    showWarningToast({
        title: 'Warning',
        text: emailValidationMessage,
    })
    return false
  }

  if (!isValidNPWP(form.npwp)) {
    showErrorToast({
      title: 'NPWP Tidak Valid',
      text: 'NPWP harus terdiri dari 15 digit angka',
    })
    isValid = false
  }

  if (!(await validateDokumen())) {
    return false
  }

  return isValid
}

const getCleanBanks = () => {
  return (banks.value || [])
    .filter(bank => {
      return [
        bank.bank_id,
        bank.atas_nama,
        bank.nomor_rekening,
        bank.cabang,
        bank.alamat_bank,
      ].some(value => String(value || '').trim() !== '')
    })
    .map(bank => ({
      bank_id: bank.bank_id,
      atas_nama: String(bank.atas_nama || '').trim(),
      nomor_rekening: String(bank.nomor_rekening || '').trim(),
      cabang: String(bank.cabang || '').trim() || null,
      alamat_bank: String(bank.alamat_bank || '').trim() || null,
      swift_code_snapshot: bank.swift_code || null,
    }))
}

const buildFormData = (): FormData => {
  const formData = new FormData()

  formData.append('transaksi_ids', JSON.stringify(form.transaksi_ids))
  formData.append('banks', JSON.stringify(getCleanBanks()))
  formData.append('dokumen_pendukung', JSON.stringify(selectedDokumen.value))

  Object.entries(dokumenFiles).forEach(([id, files]) => {
    files.forEach((file: File) => {
      formData.append(`dokumen_files[${id}][]`, file)
    })
  })

  const mainFields: Record<string, string> = {
    nama_vendor: form.nama_vendor || '',
    telepon: form.telepon || '',
    fax: form.fax || '',
    email: form.email || '',
    jenis_perusahaan: form.jenis_perusahaan || '',
    kategori_vendor: form.kategori_vendor || '',
    id_department: String(form.id_department) || '',
    nomor_ktp: form.nomor_ktp || '',
    alamat: form.alamat || '',
    contact_nama: form.contact_nama || '',
    contact_jabatan: form.contact_jabatan || '',
    contact_hp: form.contact_hp || '',
    contact_email: form.contact_email || '',
    status_pkp: form.status_pkp || '',
    npwp: form.npwp || '',
    npwp_alamat: form.npwp_alamat || '',
    sppkp_nomor: form.sppkp_nomor || '',
    sppkp_tanggal: form.sppkp_tanggal || '',
    sppkp_alamat: form.sppkp_alamat || '',
    same_as_npwp: form.same_as_npwp ? 'true' : 'false',
    jenis_pembayaran: form.jenis_pembayaran || '',
    top: String(form.top ?? 0),
    inisial_vendor: form.inisial_vendor || '',
  }

  Object.entries(mainFields).forEach(([key, value]) => {
    formData.append(key, value)
  })

  return formData
}

const saveVendor = async (): Promise<void> => {
  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const isBankValid = await validateBanks()
  if (!isBankValid) return

  const confirm = await showConfirmAlert({
    title: 'Simpan Vendor?',
    text: 'Pastikan data sudah benar.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan data...', 'Mohon tunggu sebentar')

    const formData = buildFormData()

    await axios.post('/master/vendor', formData, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/master/vendor',
      query: { success: 'created' },
    })
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    console.error('[Vendor] SAVE ERROR:', err)

    if (err?.response?.status === 401) {
      showErrorToast({
        title: 'Sesi Login Berakhir',
        text: 'Silakan login ulang terlebih dahulu.',
      })

      localStorage.removeItem('accessToken')
      localStorage.removeItem('userData')
      localStorage.removeItem('navItems')

      await router.replace('/login')
      return
    }

    showErrorToast({
      title: 'Error',
      text: err?.response?.data?.message || 'Vendor gagal disimpan',
    })
  } finally {
    isSaving.value = false
  }
}

const goBack = (): void => {
  router.push('/master/vendor')
}

const toggleTransaksi = (id : number) => {
  const index = form.transaksi_ids.indexOf(id)

  if (index === -1) {
    form.transaksi_ids.push(id)
  } else {
    form.transaksi_ids.splice(index, 1)
  }
}

const toggleDokumen = (id: number) => {
  const index = selectedDokumen.value.indexOf(id)

  if (index === -1) {
    selectedDokumen.value.push(id)
  } else {
    selectedDokumen.value.splice(index, 1)
  }
}

onMounted(async () => {
  await loadTransaksi()
  await loadMasterDokumen()
  await loadMasterBanks()
  await fetchDepartments(false)
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
            <div>
                <div class="text-h6 font-weight-bold">
                Form Registrasi Vendor
                </div>
                <div class="text-body-2 text-medium-emphasis">
                Silakan lengkapi data vendor dengan benar
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
          <div>
            <VRow>
              <!-- Nama Perusahaan -->
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  :model-value="form.nama_vendor"
                  label="Nama Perusahaan *"
                  placeholder="Contoh: PT MAJU JAYA ABADI"
                  hint="Gunakan format PT/CV/UD/PD tanpa tanda titik."
                  persistent-hint
                  :error="isSubmitted && !form.nama_vendor"
                  :error-messages="isSubmitted && !form.nama_vendor ? ['Nama perusahaan wajib diisi'] : []"
                  @update:model-value="form.nama_vendor = $event"
                  @input="handleNamaVendor($event as InputEvent)"
                >
                  <template #append-inner>
                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      color="primary"
                      @click.stop="showInfoNamaVendor = true"
                    >
                      <VIcon icon="tabler-help-circle" size="20" />
                    </VBtn>
                  </template>
                </VTextField>
              </VCol>

              <VCol
                cols="12"
                md="6">
                    <VTextField
                    v-model="form.inisial_vendor"
                    label="Inisial Vendor *"
                    placeholder="Masukan inisial vendor"
                    :error="isSubmitted && !form.inisial_vendor"
                    :error-messages="isSubmitted && !form.inisial_vendor ? ['Inisial vendor wajib diisi'] : []"
                    @update:model-value="value => form.inisial_vendor = toUpper(value).slice(0, 4)"
                    />
              </VCol>

              <!-- Telepon -->
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="form.telepon"
                  label="Telepon"
                  placeholder="Masukan nomor telepon"
                  @update:model-value="value => form.telepon = onlyNumber(value)"
                />
              </VCol>

              <!-- Fax -->
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="form.fax"
                  label="Fax"
                  placeholder="Masukan nomor fax"
                  @update:model-value="value => form.fax = onlyNumber(value)"
                />
              </VCol>

              <!-- Email -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.email"
                  label="Email"
                  placeholder="contoh@email.com"
                  :error="!!(form.email && !validateEmail(form.email))"
                  :error-messages="form.email && !validateEmail(form.email)
                    ? [emailValidationMessage]
                    : []"
                  @update:model-value="value => form.email = formatEmail(value)"
                />
              </VCol>

              <!-- Jenis Perusahaan -->
              <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="form.jenis_perusahaan"
                  label="Jenis Perusahaan *"
                  :items="[
                    { title: 'Orang Pribadi / Perorangan', value: '1' },
                    { title: 'Firma / CV / PD', value: '2' },
                    { title: 'PT / Perseroan', value: '3' },
                  ]"
                  item-title="title"
                  item-value="value"
                  clearable
                  density="comfortable"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
                  :error="isSubmitted && !form.jenis_perusahaan"
                  :error-messages="isSubmitted && !form.jenis_perusahaan ? ['Jenis perusahaan wajib dipilih'] : []"
                  no-data-text="Jenis perusahaan tidak ditemukan"
                  placeholder="Pilih jenis perusahaan"
                />
              </VCol>

              <!-- Kategori Vendor -->
              <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="form.kategori_vendor"
                  label="Kategori Vendor *"
                  :items="[
                    { title: 'TRADING', value: 'TRADING' },
                    { title: 'NON TRADING', value: 'NON_TRADING' },
                  ]"
                  item-title="title"
                  item-value="value"
                  clearable
                  density="comfortable"
                  :menu-props="{
                    location: 'bottom',
                    offset: 8,
                    maxHeight: 300,
                  }"
                  :error="isSubmitted && !form.kategori_vendor"
                  :error-messages="isSubmitted && !form.kategori_vendor ? ['Kategori vendor wajib dipilih'] : []"
                  no-data-text="Kategori vendor tidak ditemukan"
                  placeholder="Pilih kategori vendor"
                />
              </VCol>

              <!-- Department -->
              <VCol cols="12" md="6">
                <VAutocomplete
                v-model="form.id_department"
                label="Department *"
                :items="departmentOptions"
                item-title="label"
                item-value="id"
                clearable
                density="comfortable"
                :loading="loadingDepartments"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error="isSubmitted && !form.id_department"
                :error-messages="isSubmitted && !form.id_department
                  ? ['Department wajib dipilih']
                  : []"
                no-data-text="Department tidak ditemukan"
                placeholder="Pilih department"
              >
                <template #append-inner>
                  <VProgressCircular
                    v-if="loadingDepartments"
                    indeterminate
                    size="18"
                    width="2"
                  />

                  <VTooltip
                    v-else-if="departmentOptions.length === 0"
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
                        @click.stop.prevent="fetchDepartments(true)"
                      >
                        <VIcon icon="tabler-refresh" />
                      </VBtn>
                    </template>
                  </VTooltip>
                </template>
              </VAutocomplete>
              </VCol>

              <!-- Nomor E-KTP -->
              <VCol
                v-if="showEktp"
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="form.nomor_ktp"
                  label="Nomor E-KTP"
                  placeholder="Masukkan 16 digit Nomor E-KTP"
                  maxlength="16"
                  @input="form.nomor_ktp = form.nomor_ktp.replace(/[^0-9]/g, '')"
                />
              </VCol>

              <!-- Alamat -->
              <VCol cols="12">
                <VTextarea
                  v-model="form.alamat"
                  label="Alamat"
                  placeholder="Alamat lengkap vendor"
                  rows="3"
                  auto-grow
                />
              </VCol>

              <!-- Contact Person -->
                <VCol cols="12">
                    <div class="mt-4">
                        <div class="text-subtitle-1 font-weight-bold mb-2">
                        Contact Person
                        </div>

                        <VDivider class="mb-4" />

                        <VRow>
                          <VCol
                              cols="12"
                              md="6"
                          >
                              <VTextField
                              v-model="form.contact_nama"
                              label="Nama Kontak"
                              placeholder="Nama contact person"
                              @input="form.contact_nama = form.contact_nama.toUpperCase()"
                              />
                          </VCol>

                          <VCol
                              cols="12"
                              md="6"
                          >
                              <VTextField
                              v-model="form.contact_jabatan"
                              label="Jabatan"
                              placeholder="Jabatan contact person"
                              @input="form.contact_jabatan = form.contact_jabatan.toUpperCase()"
                              />
                          </VCol>

                          <VCol
                              cols="12"
                              md="6"
                          >
                              <VTextField
                              v-model="form.contact_hp"
                              label="No. HP"
                              placeholder="0812xxxxxxx"
                              maxlength="15"
                              @input="form.contact_hp = form.contact_hp.replace(/[^0-9]/g, '')"
                              />
                          </VCol>

                          <VCol
                              cols="12"
                              md="6"
                          >
                              <VTextField
                                v-model="form.contact_email"
                                label="Email"
                                placeholder="contoh@email.com"
                                :error="!!(form.contact_email && !validateEmail(form.contact_email))"
                                :error-messages="form.contact_email && !validateEmail(form.contact_email)
                                    ? [emailValidationMessage]
                                    : []"
                                @update:model-value="value => form.contact_email = formatEmail(value)"
                            />
                          </VCol>
                        </VRow>
                    </div>
                </VCol>

              <!-- Data Pajak -->
                <VCol cols="12">
                    <div class="mt-4">
                        <div class="text-subtitle-1 font-weight-bold mb-2">
                        Data Pajak
                        </div>

                        <VDivider class="mb-4" />

                        <VRow>
                        <VCol
                            cols="12"
                            md="6"
                        >
                            <VSelect
                            v-model="form.status_pkp"
                            label="Status PKP *"
                            :items="[
                                { title: 'PKP', value: 'PKP' },
                                { title: 'Non PKP', value: 'NON_PKP' },
                            ]"
                            item-title="title"
                            item-value="value"
                            />
                        </VCol>
                        </VRow>

                        <template v-if="isPKP">
                        <!-- NPWP -->
                        <div class="mt-4">
                            <div class="text-subtitle-2 font-weight-bold mb-2">
                            NPWP
                            </div>

                            <VDivider class="mb-4" />

                            <VRow>
                            <VCol
                                cols="12"
                                md="6"
                            >
                                <VTextField
                                  v-model="form.npwp"
                                  label="NPWP"
                                  placeholder="Masukan nomor NPWP"
                                  :error="isSubmitted && !isValidNPWP(form.npwp)"
                                  :error-messages="
                                    isSubmitted && !isValidNPWP(form.npwp)
                                      ? ['NPWP harus 16 digit']
                                      : []
                                  "
                                  @update:model-value="value => form.npwp = onlyNumber(value)"
                                />
                            </VCol>

                            <VCol cols="12">
                                <VTextarea
                                v-model="form.npwp_alamat"
                                label="Alamat NPWP"
                                placeholder="Alamat sesuai NPWP"
                                rows="2"
                                auto-grow
                                />
                            </VCol>
                            </VRow>
                        </div>

                        <!-- SPPKP -->
                        <div class="mt-6">
                            <div class="text-subtitle-2 font-weight-bold mb-2">
                            SPPKP
                            </div>

                            <VDivider class="mb-4" />

                            <VRow>
                            <VCol
                                cols="12"
                                md="6"
                            >
                                <VTextField
                                v-model="form.sppkp_nomor"
                                label="Nomor SPPKP"
                                placeholder="Masukkan nomor SPPKP"
                                @input="form.sppkp_nomor = form.sppkp_nomor.toUpperCase()"
                                />
                            </VCol>

                            <VCol cols="12" md="6">
                            <div class="position-relative">
                              <VTextField
                                :model-value="sppkpDate.displayValue.value"
                                label="Tanggal SPPKP"
                                placeholder="DD-MM-YYYY"
                                readonly
                                append-inner-icon="tabler-calendar"
                                @click="sppkpDate.openPicker"
                                @click:append-inner="sppkpDate.openPicker"
                              />

                              <input
                                :ref="(el) => {
                                  sppkpDate.nativeDateRef.value = el as HTMLInputElement | null
                                }"
                                type="date"
                                :value="form.sppkp_tanggal"
                                class="native-date-hidden"
                                @change="sppkpDate.onDateChange"
                              >
                            </div>
                          </VCol>

                            <VCol cols="12">
                                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-2">
                                <div class="text-body-2 font-weight-medium">
                                    Alamat SPPKP
                                </div>

                                <VCheckbox
                                    v-model="form.same_as_npwp"
                                    label="Samakan dengan NPWP"
                                    density="compact"
                                    hide-details
                                />
                                </div>

                                <VTextarea
                                v-model="form.sppkp_alamat"
                                label="Alamat SPPKP"
                                placeholder="Alamat sesuai SPPKP"
                                rows="2"
                                auto-grow
                                :disabled="form.same_as_npwp"
                                />
                            </VCol>
                            </VRow>
                        </div>
                        </template>
                    </div>
                </VCol>
                <!-- Keterangan Transaksi -->
                <VCol cols="12">
                  <div class="mt-4">
                    <div class="text-subtitle-1 font-weight-bold mb-2">
                      Keterangan Transaksi
                    </div>

                    <VDivider class="mb-4" />

                    <div
                      v-if="loadingTransaksi"
                      class="d-flex flex-column align-center justify-center py-8 text-medium-emphasis"
                    >
                      <VProgressCircular
                        indeterminate
                        color="primary"
                        size="24"
                        width="3"
                        class="mb-2"
                      />
                      <span>Sedang memuat data...</span>
                    </div>

                    <!-- ERROR -->
                    <VAlert
                    v-else-if="transaksiError"
                    type="error"
                    variant="tonal"
                    prominent
                    icon="mdi-alert-circle"
                  >
                    <div class="d-flex justify-space-between align-center">

                      <div>
                        <div class="font-weight-medium">
                          Gagal Memuat Data
                        </div>

                        <div class="text-body-2">
                          {{ transaksiError }}
                        </div>
                      </div>

                      <VBtn
                        type="button"
                        size="small"
                        variant="outlined"
                        color="error"
                        @click="loadTransaksi"
                      >
                        Coba Lagi
                      </VBtn>

                    </div>
                  </VAlert>

                    <VRow v-else>
                      <VCol
                        v-for="trx in masterTransaksi"
                        :key="trx.id"
                        cols="12"
                        md="6"
                      >
                        <VCard
                          variant="outlined"
                          class="trx-card"
                          @click="toggleTransaksi(trx.id)"
                        >
                          <VCardText class="py-4">
                            <div class="d-flex align-start">
                              <VCheckbox
                                :model-value="form.transaksi_ids.includes(trx.id)"
                                density="compact"
                                hide-details
                                class="me-3"
                                @click.stop="toggleTransaksi(trx.id)"
                              />

                              <div>
                                <div class="font-weight-medium">
                                  {{ trx.kategori || '-' }}
                                </div>
                                <div class="text-caption text-medium-emphasis">
                                  {{ trx.pasal_pajak || '' }}
                                </div>
                              </div>
                            </div>
                          </VCardText>
                        </VCard>
                      </VCol>
                    </VRow>
                  </div>
                </VCol>
                <!-- Data Pembayaran -->
                <VCol cols="12">
                  <div class="mt-4">
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-2">
                      <div class="text-subtitle-1 font-weight-bold">
                        Data Pembayaran
                      </div>

                      <VBtn
                        type="button"
                        color="primary"
                        variant="outlined"
                        size="small"
                        @click="addBank"
                      >
                        + Tambah Bank
                      </VBtn>
                    </div>

                    <VDivider class="mb-4" />

                    <div
                      v-if="loadingBanks"
                      class="d-flex flex-column align-center justify-center py-8 text-medium-emphasis"
                    >
                      <VProgressCircular
                        indeterminate
                        color="primary"
                        size="24"
                        width="3"
                        class="mb-2"
                      />
                      <span>Sedang memuat master bank...</span>
                    </div>

                    <VAlert
                      v-else-if="bankError"
                      type="error"
                      variant="tonal"
                      prominent
                      icon="mdi-alert-circle"
                      class="mb-4"
                    >
                      <div class="d-flex justify-space-between align-center">
                        <div>
                          <div class="font-weight-medium">
                            Gagal Memuat Data
                          </div>

                          <div class="text-body-2">
                            {{ bankError }}
                          </div>
                        </div>

                        <VBtn
                          type="button"
                          size="small"
                          variant="outlined"
                          color="error"
                          @click="loadMasterBanks"
                        >
                          Coba Lagi
                        </VBtn>
                      </div>
                    </VAlert>

                    <div
                      v-for="(bank, index) in banks"
                      :key="index"
                      class="mb-4"
                    >
                      <VCard variant="outlined">
                        <VCardTitle class="d-flex align-center justify-space-between py-3">
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ index + 1 }}. Bank
                          </div>

                          <VBtn
                            type="button"
                            v-if="banks.length > 1"
                            color="error"
                            variant="text"
                            size="small"
                            @click="removeBank(index)"
                          >
                            Hapus
                          </VBtn>
                        </VCardTitle>

                        <VDivider />

                        <VCardText>
                          <VRow>
                            <VCol cols="12" md="6">
                              <VAutocomplete
                                v-model="bank.bank_id"
                                label="Nama Bank"
                                :items="masterBanks"
                                item-title="nama_bank"
                                item-value="id"
                                clearable
                                density="comfortable"
                                :menu-props="{
                                  location: 'bottom',
                                  offset: 8,
                                  maxHeight: 300,
                                }"
                                :custom-filter="filterMasterBank"
                                :error="isSubmitted && isBankRowFilled(bank) && !bank.bank_id"
                                :error-messages="isSubmitted && isBankRowFilled(bank) && !bank.bank_id ? ['Nama bank wajib dipilih'] : []"
                                no-data-text="Data bank tidak ditemukan"
                                placeholder="Cari nama bank..."
                                @update:model-value="handleSelectBank(index)"
                              >
                                <template #item="{ props, item }">
                                  <VListItem
                                    v-bind="props"
                                    :title="item.raw.nama_bank"
                                    :subtitle="`${item.raw.nama_bank_pendek || '-'} • Kode: ${item.raw.kode_bank || '-'} • Swift: ${item.raw.swift_code || '-'}`"
                                  />
                                </template>
                              </VAutocomplete>
                            </VCol>

                            <VCol cols="12">
                              <VAlert
                                v-if="bank.bank_id"
                                type="info"
                                variant="tonal"
                              >
                                <div class="d-flex flex-column ga-1">
                                  <div>
                                    <strong>Kode Bank :</strong>
                                    {{ bank.kode_bank || '-' }}
                                  </div>
                                  <div>
                                    <strong>Nama Bank :</strong>
                                    {{ bank.nama_bank_pendek || '-' }}
                                  </div>
                                  <div>
                                    <strong>Swift Code :</strong>
                                    {{ bank.swift_code || '-' }}
                                  </div>
                                </div>
                              </VAlert>
                            </VCol>

                            <VCol cols="12" md="6">
                              <VTextField
                                v-model="bank.atas_nama"
                                label="Atas Nama"
                                placeholder="Atas Nama Rekening"
                                :error="isSubmitted && isBankRowFilled(bank) && !bank.atas_nama"
                                :error-messages="isSubmitted && isBankRowFilled(bank) && !bank.atas_nama ? ['Atas nama wajib diisi'] : []"
                                @update:model-value="value => bank.atas_nama = toUpper(value)"
                              />
                            </VCol>

                            <VCol cols="12" md="6">
                              <VTextField
                                v-model="bank.nomor_rekening"
                                label="Nomor Rekening"
                                placeholder="Nomor Rekening"
                                :error="isSubmitted && isBankRowFilled(bank) && !bank.nomor_rekening"
                                :error-messages="isSubmitted && isBankRowFilled(bank) && !bank.nomor_rekening ? ['Nomor rekening wajib diisi'] : []"
                                @update:model-value="value => bank.nomor_rekening = onlyNumber(value)"
                              />
                            </VCol>

                            <VCol cols="12" md="6">
                              <VTextField
                                v-model="bank.cabang"
                                label="Cabang"
                                placeholder="Cabang Bank"
                              />
                            </VCol>

                            <VCol cols="12" md="6">
                              <VTextarea
                                v-model="bank.alamat_bank"
                                label="Alamat Bank"
                                placeholder="Alamat bank"
                                rows="2"
                                auto-grow
                              />
                            </VCol>
                          </VRow>
                        </VCardText>
                      </VCard>
                    </div>
                  </div>
                </VCol>
                <!-- Sistem Pembayaran -->
                <VCol cols="12">
                    <div class="mt-4">
                        <div class="text-subtitle-1 font-weight-bold mb-2">
                        Sistem Pembayaran
                        </div>

                        <VDivider class="mb-4" />

                        <VRow>
                            <VCol
                                cols="12"
                                md="6"
                            >
                                <VSelect
                                v-model="form.jenis_pembayaran"
                                label="Metode Pembayaran *"
                                :items="[
                                    { title: 'TOP (Term of Payment)', value: 'TOP' },
                                    { title: 'COD (Cash On Delivery)', value: 'COD' },
                                    { title: 'CBD (Cash Before Delivery)', value: 'CBD' },
                                ]"
                                item-title="title"
                                item-value="value"
                                :error="isSubmitted && !form.jenis_pembayaran"
                                :error-messages="isSubmitted && !form.jenis_pembayaran
                                    ? ['Metode pembayaran wajib dipilih']
                                    : []"
                                />
                            </VCol>

                            <VCol
                                v-if="showTopInput"
                                cols="12"
                                md="6"
                            >
                                <VTextField
                                v-model="form.top"
                                label="Lama TOP (hari) *"
                                placeholder="Contoh: 30"
                                :error="isSubmitted && (!form.top || Number(form.top) <= 0)"
                                :error-messages="isSubmitted && (!form.top || Number(form.top) <= 0)
                                    ? ['Lama TOP wajib diisi']
                                    : []"
                                @update:model-value="value => form.top = Number(onlyNumber(String(value || 0)))"
                                />
                            </VCol>
                        </VRow>
                    </div>
                </VCol>
                <!-- Dokumen Pendukung -->
                <VCol cols="12">
                <div class="mt-4">
                  <div class="text-subtitle-1 font-weight-bold mb-2">
                    Dokumen Pendukung
                  </div>

                  <VDivider class="mb-4" />

                  <div
                    v-if="loadingDokumen"
                    class="d-flex flex-column align-center justify-center py-8 text-medium-emphasis"
                  >
                    <VProgressCircular
                      indeterminate
                      color="primary"
                      size="24"
                      width="3"
                      class="mb-2"
                    />
                    <span>Sedang memuat data...</span>
                  </div>

                  <VAlert
                    v-else-if="dokumenError"
                    type="error"
                    variant="tonal"
                    prominent
                    icon="mdi-alert-circle"
                  >
                    <div class="d-flex justify-space-between align-center">

                      <div>
                        <div class="font-weight-medium">
                          Gagal Memuat Data
                        </div>

                        <div class="text-body-2">
                          {{ dokumenError }}
                        </div>
                      </div>

                      <VBtn
                        type="button"
                        size="small"
                        variant="outlined"
                        color="error"
                        @click="loadMasterDokumen"
                      >
                        Coba Lagi
                      </VBtn>

                    </div>
                  </VAlert>

                  <div v-else class="d-flex flex-column gap-4">
                    <VCard
                      v-for="doc in masterDokumen"
                      :key="doc.id"
                      variant="outlined"
                      class="dokumen-card"
                      @click="toggleDokumen(doc.id)"
                    >
                      <VCardText>
                        <div class="d-flex align-start">
                          <VCheckbox
                            :model-value="selectedDokumen.includes(doc.id)"
                            density="compact"
                            hide-details
                            class="me-2"
                            @click.stop="toggleDokumen(doc.id)"
                          />

                          <div class="flex-grow-1">
                            <div class="font-weight-medium">
                              {{ doc.nama_dokumen }} {{ doc.deskripsi }}
                            </div>

                            <div
                              v-if="selectedDokumen.includes(doc.id)"
                              class="mt-4"
                              @click.stop
                            >
                              <div class="text-body-2 text-medium-emphasis mb-2">
                                Lampiran (boleh multiple file)
                              </div>

                              <VFileInput
                                v-model="fileInputModels[doc.id]"
                                multiple
                                show-size
                                prepend-icon="mdi-paperclip"
                                label="Pilih file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                @update:modelValue="onFileChange($event, doc.id)"
                                @click.stop
                              />

                              <div
                                v-if="(dokumenFiles[doc.id] || []).length"
                                class="mt-3"
                              >
                                <VList density="compact" border rounded>
                                  <VListItem
                                    v-for="(file, index) in dokumenFiles[doc.id]"
                                    :key="`${doc.id}-${file.name}-${file.size}-${index}`"
                                  >
                                    <template #prepend>
                                      <VIcon icon="mdi-file-document-outline" />
                                    </template>

                                    <VListItemTitle class="text-body-2">
                                      {{ file.name }}
                                    </VListItemTitle>

                                    <template #append>
                                      <VBtn
                                        type="button"
                                        color="error"
                                        variant="text"
                                        size="small"
                                        @click.stop="removeFile(doc.id, index)"
                                      >
                                        Hapus
                                      </VBtn>
                                    </template>
                                  </VListItem>
                                </VList>
                              </div>
                            </div>
                          </div>
                        </div>
                      </VCardText>
                    </VCard>
                  </div>
                </div>
              </VCol>
            </VRow>
          </div>

          <!-- MODAL -->
          <VDialog
            v-model="showInfoNamaVendor"
            max-width="620"
          >
            <VCard>
              <VCardTitle class="d-flex align-center justify-space-between">
                <span>Ketentuan Penulisan Nama Perusahaan</span>

                <VBtn
                  icon
                  variant="text"
                  size="small"
                  @click="showInfoNamaVendor = false"
                >
                  <VIcon icon="tabler-x" />
                </VBtn>
              </VCardTitle>

              <VDivider />

              <VCardText class="text-body-2">
                <p class="mb-3">
                  Untuk menjaga konsistensi data master vendor, penulisan bentuk badan usaha seperti
                  <strong>PT</strong>, <strong>CV</strong>, <strong>UD</strong>, dan <strong>PD</strong>
                  wajib ditulis <strong>tanpa titik</strong>.
                </p>

                <p class="mb-3">
                  Contoh penulisan yang benar:
                </p>

                <ul class="mb-3 ps-5">
                  <li><strong>PT MAJU JAYA ABADI</strong></li>
                  <li><strong>CV SUMBER REJEKI</strong></li>
                  <li><strong>UD SENTOSA MAKMUR</strong></li>
                  <li><strong>PD BERKAH USAHA</strong></li>
                </ul>

                <p class="mb-3">
                  Contoh penulisan yang tidak disarankan:
                </p>

                <ul class="mb-3 ps-5">
                  <li>PT. MAJU JAYA ABADI</li>
                  <li>CV. SUMBER REJEKI</li>
                  <li>UD. SENTOSA MAKMUR</li>
                  <li>PD. BERKAH USAHA</li>
                </ul>

                <p class="mb-3">
                  Berdasarkan ketentuan nama Perseroan Terbatas, nama perseroan harus didahului
                  frasa <strong>Perseroan Terbatas</strong> atau disingkat <strong>PT</strong>.
                  Ketentuan ini terdapat dalam UU Nomor 40 Tahun 2007 tentang Perseroan Terbatas
                  dan PP Nomor 43 Tahun 2011 tentang Tata Cara Pengajuan dan Pemakaian Nama
                  Perseroan Terbatas.
                </p>

                <p class="mb-0">
                  Untuk CV, dasar hukum persekutuan komanditer terdapat dalam KUHD, khususnya
                  Pasal 19 sampai dengan Pasal 35. Di sistem ini, penulisan <strong>CV</strong>
                  juga distandarkan tanpa titik agar format data vendor konsisten.
                </p>
              </VCardText>

              <VCardActions class="justify-end">
                <VBtn
                  color="primary"
                  @click="showInfoNamaVendor = false"
                >
                  Mengerti
                </VBtn>
              </VCardActions>
            </VCard>
          </VDialog>

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
                    @click.prevent.stop="saveVendor"
                >
                    Simpan
                </VBtn>
            </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>