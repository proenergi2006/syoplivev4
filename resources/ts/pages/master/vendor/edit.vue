<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from '@axios'
import {
  showLoadingAlert,
  showSuccessAlert,
  showErrorAlert,
  closeAlert,
  showConfirmAlert,
  showWarningAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'

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

interface MasterTransaksiItem {
  id: number
  kategori: string | null
  pasal_pajak: string | null
}

interface MasterDokumenItem {
  id: number
  nama_dokumen: string
  deskripsi?: string | null
}

interface BankItem {
  id?: number | null
  nama_bank: string
  atas_nama: string
  nomor_rekening: string
  cabang: string
  alamat_bank: string
  swift_code: string
}

interface ExistingDokumenFile {
  id: number
  dokumen_id: number
  file_name: string
  file_path: string
  file_url?: string | null
}

interface VendorForm {
  nama_vendor: string
  inisial_vendor: string
  telepon: string
  fax: string
  email: string
  jenis_perusahaan: string | null
  kategori_vendor: string | null
  nomor_ktp: string
  alamat: string

  contact_nama: string
  contact_jabatan: string
  contact_hp: string
  contact_email: string

  status_pkp: string | null
  npwp: string
  npwp_alamat: string
  sppkp_nomor: string
  sppkp_tanggal: string
  sppkp_alamat: string
  same_as_npwp: boolean

  transaksi_ids: number[]

  jenis_pembayaran: string | null
  top: number | null
}

interface VendorDetail {
  public_id: string
  nama_vendor: string
  inisial_vendor: string
  telepon: string | null
  fax: string | null
  email: string | null
  jenis_perusahaan: string | number | null
  kategori_vendor: string | null
  nomor_ktp: string | null
  alamat: string | null

  contact_nama: string | null
  contact_jabatan: string | null
  contact_hp: string | null
  contact_email: string | null

  status_pkp: string | null
  npwp: string | null
  npwp_alamat: string | null
  sppkp_nomor: string | null
  sppkp_tanggal: string | null
  sppkp_alamat: string | null
  same_as_npwp: boolean | null

  jenis_pembayaran: string | null
  top: number | null

  transaksi_ids?: number[]
  banks?: BankItem[]
  dokumen_ids?: number[]
  dokumen_files?: ExistingDokumenFile[]
}

interface VendorDetailResponse {
  success?: boolean
  message?: string
  data?: VendorDetail
}

interface BankForm {
  id?: number | null
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

const router = useRouter()
const route = useRoute()

const vendorPublicId = computed(() => String(route.query.id ?? ''))

const loading = ref(false)
const isSaving = ref(false)
const isSubmitted = ref(false)
const loadError = ref('')

const loadingTransaksi = ref(false)
const transaksiError = ref('')

const loadingDokumen = ref(false)
const dokumenError = ref('')

const masterTransaksi = ref<MasterTransaksiItem[]>([])
const masterDokumen = ref<MasterDokumenItem[]>([])

const selectedDokumen = ref<number[]>([])
const dokumenFiles = ref<Record<number, File[]>>({})
const fileInputModels = ref<Record<number, File[]>>({})
const existingDokumenFiles = ref<Record<number, ExistingDokumenFile[]>>({})

const loadingBanks = ref(false)
const bankError = ref<string | null>(null)
const masterBanks = ref<MasterBankItem[]>([])

const form = reactive<VendorForm>({
  nama_vendor: '',
  inisial_vendor: '',
  telepon: '',
  fax: '',
  email: '',
  jenis_perusahaan: null,
  kategori_vendor: null,
  nomor_ktp: '',
  alamat: '',

  contact_nama: '',
  contact_jabatan: '',
  contact_hp: '',
  contact_email: '',

  status_pkp: null,
  npwp: '',
  npwp_alamat: '',
  sppkp_nomor: '',
  sppkp_tanggal: '',
  sppkp_alamat: '',
  same_as_npwp: false,

  transaksi_ids: [],

  jenis_pembayaran: null,
  top: null,
})

const createEmptyBank = (): BankForm => ({
  id: null,
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

const banks = ref<BankForm[]>([createEmptyBank()])

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

const goBack = (): void => {
  router.push('/master/vendor')
}

const confirmCancel = async (): Promise<void> => {
  const result = await showConfirmAlert({
    title: 'Batalkan perubahan?',
    text: 'Data yang sudah diisi tidak akan tersimpan. Apakah Anda yakin ingin keluar?',
    confirmButtonText: 'Ya, keluar',
    cancelButtonText: 'Batal',
  })

  if (result.isConfirmed) {
    goBack()
  }
}

const toUpper = (value: unknown): string => String(value ?? '').toUpperCase()
const onlyNumber = (value: unknown): string => String(value ?? '').replace(/[^0-9]/g, '')
const formatEmail = (value: unknown): string => String(value ?? '').trim().toLowerCase()

const validateEmail = (value: string): boolean => {
  if (!value) return true
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

const emailValidationMessage = 'Format email tidak valid'

const handleNamaVendor = (event: Event): void => {
  const target = event.target as HTMLInputElement | null
  form.nama_vendor = toUpper(target?.value ?? '')
}

const formatNPWP = (): void => {
  form.npwp = onlyNumber(form.npwp).slice(0, 20)
}

const addBank = (): void => {
  banks.value.push(createEmptyBank())
}

const removeBank = (index: number): void => {
  if (banks.value.length > 1) {
    banks.value.splice(index, 1)
  }
}

const toggleTransaksi = (id: number): void => {
  const index = form.transaksi_ids.indexOf(id)

  if (index === -1) {
    form.transaksi_ids.push(id)
  } else {
    form.transaksi_ids.splice(index, 1)
  }
}

const toggleDokumen = (dokumenId: number): void => {
  const exists = selectedDokumen.value.includes(dokumenId)

  if (exists) {
    selectedDokumen.value = selectedDokumen.value.filter(id => id !== dokumenId)
    delete dokumenFiles.value[dokumenId]
    delete fileInputModels.value[dokumenId]
    delete existingDokumenFiles.value[dokumenId]
    return
  }

  selectedDokumen.value.push(dokumenId)
}

const onFileChange = (files: File[] | File | null, docId: number): void => {
  const incomingFiles = Array.isArray(files) ? files : files ? [files] : []

  if (!incomingFiles.length) {
    dokumenFiles.value[docId] = []
    fileInputModels.value[docId] = []
    return
  }

  const currentFiles = dokumenFiles.value[docId] || []
  const mergedFiles = [...currentFiles, ...incomingFiles]

  dokumenFiles.value[docId] = mergedFiles
  fileInputModels.value[docId] = mergedFiles
}

const removeFile = (dokumenId: number, index: number): void => {
  const currentFiles = dokumenFiles.value[dokumenId] || []
  dokumenFiles.value[dokumenId] = currentFiles.filter((_, i) => i !== index)
  fileInputModels.value[dokumenId] = [...dokumenFiles.value[dokumenId]]
}

const removeExistingFile = (dokumenId: number, fileId: number): void => {
  const currentFiles = existingDokumenFiles.value[dokumenId] || []
  existingDokumenFiles.value[dokumenId] = currentFiles.filter(file => file.id !== fileId)
}

const openDokumenFile = (url?: string | null): void => {
  if (!url) return
  window.open(url, '_blank', 'noopener,noreferrer')
}

const showEktp = computed(() => form.jenis_perusahaan === '1')
const isPKP = computed(() => form.status_pkp === 'PKP')
const showTopInput = computed(() => form.jenis_pembayaran === 'TOP')

watch(
  () => form.same_as_npwp,
  value => {
    if (value) {
      form.sppkp_alamat = form.npwp_alamat
    }
  },
)

watch(
  () => form.npwp_alamat,
  value => {
    if (form.same_as_npwp) {
      form.sppkp_alamat = value
    }
  },
)

watch(
  () => form.email,
  value => {
    form.email = formatEmail(value)
  },
)

watch(
  () => form.contact_email,
  value => {
    form.contact_email = formatEmail(value)
  },
)

watch(
  () => form.top,
  value => {
    if (value === null || value === undefined) return
    const numeric = Number(String(value).replace(/[^0-9]/g, ''))
    form.top = Number.isNaN(numeric) ? null : numeric
  },
)

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

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat master bank.'),
    })

    masterBanks.value = []
  } finally {
    loadingBanks.value = false
  }
}

const loadTransaksi = async (): Promise<void> => {
  loadingTransaksi.value = true
  transaksiError.value = ''

  try {
    const response = await axios.get('/master/keterangan-transaksi')
    masterTransaksi.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    transaksiError.value = getApiErrorMessage(err, 'Gagal memuat data transaksi')
  } finally {
    loadingTransaksi.value = false
  }
}

const loadMasterDokumen = async (): Promise<void> => {
  loadingDokumen.value = true
  dokumenError.value = ''

  try {
    const response = await axios.get('/master/dokumen-pendukung')
    masterDokumen.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    dokumenError.value = getApiErrorMessage(err, 'Gagal memuat data dokumen')
  } finally {
    loadingDokumen.value = false
  }
}

const validateDokumen = async (): Promise<boolean> => {
  for (const id of selectedDokumen.value) {
    const hasExistingFiles = (existingDokumenFiles.value[id] || []).length > 0
    const hasNewFiles = (dokumenFiles.value[id] || []).length > 0

    if (!hasExistingFiles && !hasNewFiles) {
      await showWarningAlert({
        title: 'Peringatan',
        text: 'Lampiran wajib diunggah untuk dokumen yang dipilih.',
      })
      return false
    }
  }

  return true
}

const loadVendorDetail = async (): Promise<void> => {
  if (!vendorPublicId.value) {
    loadError.value = 'ID vendor tidak ditemukan.'
    return
  }

  if (loading.value) return

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get<VendorDetailResponse>(`/master/vendor/${vendorPublicId.value}`)
    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data vendor tidak ditemukan')
    }

    form.nama_vendor = detail.nama_vendor ?? ''
    form.inisial_vendor = detail.inisial_vendor ?? ''
    form.telepon = detail.telepon ?? ''
    form.fax = detail.fax ?? ''
    form.email = formatEmail(detail.email ?? '')
    form.jenis_perusahaan =
      detail.jenis_perusahaan !== null && detail.jenis_perusahaan !== undefined
        ? String(detail.jenis_perusahaan)
        : null
    form.kategori_vendor = detail.kategori_vendor ?? null
    form.nomor_ktp = detail.nomor_ktp ?? ''
    form.alamat = detail.alamat ?? ''

    form.contact_nama = detail.contact_nama ?? ''
    form.contact_jabatan = detail.contact_jabatan ?? ''
    form.contact_hp = detail.contact_hp ?? ''
    form.contact_email = formatEmail(detail.contact_email ?? '')

    form.status_pkp = detail.status_pkp ?? null
    form.npwp = detail.npwp ?? ''
    form.npwp_alamat = detail.npwp_alamat ?? ''
    form.sppkp_nomor = detail.sppkp_nomor ?? ''
    form.sppkp_tanggal = detail.sppkp_tanggal ?? ''
    form.sppkp_alamat = detail.sppkp_alamat ?? ''
    form.same_as_npwp = Boolean(detail.same_as_npwp)

    form.jenis_pembayaran = detail.jenis_pembayaran ?? null
    form.top = detail.top ?? null

    form.transaksi_ids = Array.isArray(detail.transaksi_ids) ? detail.transaksi_ids : []

    mapBanksFromDetail(Array.isArray(detail.banks) ? detail.banks : [])

    selectedDokumen.value = Array.isArray(detail.dokumen_ids) ? detail.dokumen_ids : []

    dokumenFiles.value = {}
    fileInputModels.value = {}
    existingDokumenFiles.value = {}

    if (Array.isArray(detail.dokumen_files)) {
      detail.dokumen_files.forEach(file => {
        if (!existingDokumenFiles.value[file.dokumen_id]) {
          existingDokumenFiles.value[file.dokumen_id] = []
        }

        existingDokumenFiles.value[file.dokumen_id].push({
          id: file.id,
          dokumen_id: file.dokumen_id,
          file_name: file.file_name,
          file_path: file.file_path,
          file_url: file.file_url ?? file.file_path,
        })
      })
    }
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    loadError.value = getApiErrorMessage(err, 'Gagal memuat detail vendor')

    await showErrorAlert({
      title: 'Error',
      text: loadError.value,
    })
  } finally {
    loading.value = false
  }
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
      id: bank.id || null,
      bank_id: bank.bank_id ? Number(bank.bank_id) : null,
      atas_nama: String(bank.atas_nama || '').trim(),
      nomor_rekening: String(bank.nomor_rekening || '').trim(),
      cabang: String(bank.cabang || '').trim() || null,
      alamat_bank: String(bank.alamat_bank || '').trim() || null,
      swift_code_snapshot: bank.swift_code || null,
    }))
}

const validateForm = async (): Promise<boolean> => {
  if (!form.nama_vendor || !form.inisial_vendor || !form.jenis_perusahaan || !form.kategori_vendor) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Silakan isi semua kolom wajib.',
    })
    return false
  }

  if (form.telepon && !/^[0-9]+$/.test(form.telepon)) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Nomor telepon hanya boleh angka.',
    })
    return false
  }

  if (form.contact_hp && !/^[0-9]+$/.test(form.contact_hp)) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Nomor HP PIC hanya boleh angka.',
    })
    return false
  }

  if (form.email && !validateEmail(form.email)) {
    await showWarningAlert({
      title: 'Warning',
      text: emailValidationMessage,
    })
    return false
  }

  if (form.contact_email && !validateEmail(form.contact_email)) {
    await showWarningAlert({
      title: 'Warning',
      text: emailValidationMessage,
    })
    return false
  }

  if (!form.jenis_pembayaran) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Pilih sistem pembayaran.',
    })
    return false
  }

  if (form.jenis_pembayaran === 'TOP' && (!form.top || form.top <= 0)) {
    await showWarningAlert({
      title: 'Warning',
      text: 'Isi jumlah hari TOP.',
    })
    return false
  }

  if (!(await validateDokumen())) {
    return false
  }

  return true
}

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
      await showErrorAlert({
        title: 'Data Bank Belum Lengkap',
        text: `Data bank ke-${i + 1} belum lengkap. Lengkapi: ${missingFields.join(', ')}`,
      })

      return false
    }
  }

  return true
}

const mapBanksFromDetail = (bankRows: any[] = []): void => {
  if (!Array.isArray(bankRows) || !bankRows.length) {
    banks.value = [createEmptyBank()]
    return
  }

  banks.value = bankRows.map((bank): BankForm => ({
    id: bank.id ?? null,
    bank_id: bank.bank_id ? Number(bank.bank_id) : null,
    nama_bank: bank.nama_bank || '',
    nama_bank_pendek: bank.nama_bank_pendek || '',
    kode_bank: bank.kode_bank || '',
    swift_code: bank.swift_code || '',
    atas_nama: bank.atas_nama || '',
    nomor_rekening: bank.nomor_rekening || '',
    cabang: bank.cabang || '',
    alamat_bank: bank.alamat_bank || '',
  }))
}

const buildPayload = (): FormData => {
  const payload = new FormData()

  payload.append('nama_vendor', form.nama_vendor ?? '')
  payload.append('inisial_vendor', form.inisial_vendor ?? '')
  payload.append('telepon', form.telepon ?? '')
  payload.append('fax', form.fax ?? '')
  payload.append('email', form.email ?? '')
  payload.append('jenis_perusahaan', form.jenis_perusahaan ?? '')
  payload.append('kategori_vendor', form.kategori_vendor ?? '')
  payload.append('nomor_ktp', form.nomor_ktp ?? '')
  payload.append('alamat', form.alamat ?? '')

  payload.append('contact_nama', form.contact_nama ?? '')
  payload.append('contact_jabatan', form.contact_jabatan ?? '')
  payload.append('contact_hp', form.contact_hp ?? '')
  payload.append('contact_email', form.contact_email ?? '')

  payload.append('status_pkp', form.status_pkp ?? '')
  payload.append('npwp', form.npwp ?? '')
  payload.append('npwp_alamat', form.npwp_alamat ?? '')
  payload.append('sppkp_nomor', form.sppkp_nomor ?? '')
  payload.append('sppkp_tanggal', form.sppkp_tanggal ?? '')
  payload.append('sppkp_alamat', form.sppkp_alamat ?? '')
  payload.append('same_as_npwp', form.same_as_npwp ? '1' : '0')

  payload.append('jenis_pembayaran', form.jenis_pembayaran ?? '')
  payload.append('top', String(form.top ?? ''))

  form.transaksi_ids.forEach((id, index) => {
    payload.append(`transaksi_ids[${index}]`, String(id))
  })

  selectedDokumen.value.forEach((id, index) => {
    payload.append(`dokumen_ids[${index}]`, String(id))
  })

  payload.append('banks', JSON.stringify(getCleanBanks()))

  Object.entries(existingDokumenFiles.value).forEach(([docId, files]) => {
    files.forEach((file, index) => {
      payload.append(`dokumen_existing_ids[${docId}][${index}]`, String(file.id))
    })
  })

  Object.entries(dokumenFiles.value).forEach(([docId, files]) => {
    files.forEach((file, index) => {
      payload.append(`dokumen_files[${docId}][${index}]`, file)
    })
  })

  payload.append('_method', 'PUT')

  return payload
}

const saveVendor = async (): Promise<void> => {
  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const isBankValid = await validateBanks()
  if (!isBankValid) return

  if (!vendorPublicId.value) {
    await showErrorAlert({
      title: 'Error',
      text: 'ID vendor tidak ditemukan.',
    })
    return
  }

  if (isSaving.value) return

  const confirm = await showConfirmAlert({
    title: 'Simpan Perubahan Vendor?',
    text: 'Pastikan data vendor yang diubah sudah benar.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan perubahan vendor...', 'Mohon tunggu sebentar')

    const payload = buildPayload()

    // Jika backend update memakai PUT/PATCH tapi request dikirim via multipart POST
    // maka spoof method seperti ini lebih aman.
    if (payload instanceof FormData) {
      payload.append('_method', 'PUT')
    }

    const response = await axios.post(`/master/vendor/${vendorPublicId.value}`, payload, {
      headers: {
        'Content-Type': 'multipart/form-data',
        Accept: 'application/json',
      },
    })

    closeAlert()

    await showSuccessAlert({
      title: 'Berhasil',
      text: response.data?.message || 'Data vendor berhasil diperbarui',
    })

    await goBack()
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memperbarui vendor'),
    })

    console.error('[Vendor] UPDATE ERROR:', err)
  } finally {
    isSaving.value = false
  }
}

onMounted(async () => {
  await Promise.all([
    loadMasterBanks(),
    loadTransaksi(),
    loadMasterDokumen(),
    loadVendorDetail(),
  ])
})
</script>

<template>
  <div>
    <VCard
      v-if="loading"
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
              Memuat data vendor...
            </div>
            <div class="text-body-2 text-medium-emphasis">
              Mohon tunggu sebentar
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- error -->
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
                <VIcon icon="tabler-alert-circle" size="24" />
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
              :loading="loading"
              prepend-icon="tabler-refresh"
              @click="loadVendorDetail"
            >
              Coba Lagi
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-arrow-left"
              @click="goBack"
            >
              Kembali
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Form -->
    <template v-else>
      <VRow>
        <VCol cols="12">
          <VCard>
            <VCardTitle class="d-flex align-center justify-space-between">
              <div>
                <div class="text-h6 font-weight-bold">
                  Form Edit Vendor
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
              <VForm @submit.prevent="saveVendor">
                <VRow>
                  <!-- Nama Perusahaan -->
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <VTextField
                      :model-value="form.nama_vendor"
                      label="Nama Perusahaan *"
                      placeholder="Nama perusahaan/vendor"
                      :error="isSubmitted && !form.nama_vendor"
                      :error-messages="isSubmitted && !form.nama_vendor ? ['Nama perusahaan wajib diisi'] : []"
                      @update:model-value="form.nama_vendor = $event"
                      @input="handleNamaVendor($event as InputEvent)"
                    />
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
                  <VCol
                    cols="12"
                    md="4"
                  >
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
                  <VCol
                    cols="12"
                    md="4"
                  >
                    <VSelect
                      v-model="form.jenis_perusahaan"
                      label="Jenis Perusahaan *"
                      :items="[
                        { title: 'Orang Pribadi / Perorangan', value: '1' },
                        { title: 'Firma / CV / PD', value: '2' },
                        { title: 'PT / Perseroan', value: '3' },
                      ]"
                      item-title="title"
                      item-value="value"
                      :error="isSubmitted && !form.jenis_perusahaan"
                      :error-messages="isSubmitted && !form.jenis_perusahaan ? ['Jenis perusahaan wajib dipilih'] : []"
                    />
                  </VCol>

                  <!-- Kategori vendor -->
                  <VCol
                    cols="12"
                    md="4"
                  >
                    <VSelect
                      v-model="form.kategori_vendor"
                      label="Kategori Vendor *"
                      :items="[
                        { title: 'TRADING', value: 'TRADING' },
                        { title: 'NON TRADING', value: 'NON_TRADING' },
                      ]"
                      item-title="title"
                      item-value="value"
                      :error="isSubmitted && !form.kategori_vendor"
                      :error-messages="isSubmitted && !form.kategori_vendor ? ['Kategori vendor wajib dipilih'] : []"
                    />
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
                                type="email"
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
                                    label="Nomor NPWP"
                                    placeholder="Masukkan nomor NPWP"
                                    maxlength="20"
                                    @input="formatNPWP"
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

                                <VCol
                                    cols="12"
                                    md="6"
                                >
                                    <VTextField
                                    v-model="form.sppkp_tanggal"
                                    label="Tanggal SPPKP"
                                    type="date"
                                    />
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
                          :key="bank.id || index"
                          class="mb-4"
                        >
                          <VCard variant="outlined">
                            <VCardTitle class="d-flex align-center justify-space-between py-3">
                              <div class="text-subtitle-2 font-weight-bold">
                                {{ index + 1 }}. Bank
                              </div>

                              <VBtn
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
                                    label="Nama Bank *"
                                    :items="masterBanks"
                                    item-title="nama_bank"
                                    item-value="id"
                                    clearable
                                    density="comfortable"
                                    :menu-props="{ maxHeight: 300 }"
                                    :custom-filter="filterMasterBank"
                                    :error="isSubmitted && !bank.bank_id"
                                    :error-messages="isSubmitted && !bank.bank_id ? ['Nama bank wajib dipilih'] : []"
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
                                        <strong>Kode Bank:</strong>
                                        {{ bank.kode_bank || '-' }}
                                      </div>
                                      <div>
                                        <strong>Nama Bank Pendek:</strong>
                                        {{ bank.nama_bank_pendek || '-' }}
                                      </div>
                                      <div>
                                        <strong>Swift Code:</strong>
                                        {{ bank.swift_code || '-' }}
                                      </div>
                                    </div>
                                  </VAlert>
                                </VCol>

                                <VCol cols="12" md="6">
                                  <VTextField
                                    v-model="bank.atas_nama"
                                    label="Atas Nama *"
                                    placeholder="Atas Nama Rekening"
                                    :error="isSubmitted && !bank.atas_nama"
                                    :error-messages="isSubmitted && !bank.atas_nama ? ['Atas nama wajib diisi'] : []"
                                    @update:model-value="value => bank.atas_nama = toUpper(value)"
                                  />
                                </VCol>

                                <VCol cols="12" md="6">
                                  <VTextField
                                    v-model="bank.nomor_rekening"
                                    label="Nomor Rekening *"
                                    placeholder="Nomor Rekening"
                                    :error="isSubmitted && !bank.nomor_rekening"
                                    :error-messages="isSubmitted && !bank.nomor_rekening ? ['Nomor rekening wajib diisi'] : []"
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
                                    placeholder="Alamat lengkap bank"
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
                                    v-if="(existingDokumenFiles[doc.id] || []).length || (dokumenFiles[doc.id] || []).length"
                                    class="mt-3"
                                  >
                                    <VList density="compact" border rounded>
                                      <VListItem
                                        v-for="file in (existingDokumenFiles[doc.id] || [])"
                                        :key="`existing-${doc.id}-${file.id}`"
                                      >
                                        <template #prepend>
                                          <VIcon icon="mdi-file-document-outline" />
                                        </template>

                                        <VListItemTitle class="text-body-2">
                                          {{ file.file_name }}
                                        </VListItemTitle>

                                        <template #append>
                                          <div class="d-flex align-center ga-2">
                                            <VBtn
                                              color="primary"
                                              variant="text"
                                              size="small"
                                              @click.stop="openDokumenFile(file.file_url)"
                                            >
                                              Lihat
                                            </VBtn>

                                            <VBtn
                                              color="error"
                                              variant="text"
                                              size="small"
                                              @click.stop="removeExistingFile(doc.id, file.id)"
                                            >
                                              Hapus
                                            </VBtn>
                                          </div>
                                        </template>
                                      </VListItem>

                                      <VListItem
                                        v-for="(file, index) in (dokumenFiles[doc.id] || [])"
                                        :key="`new-${doc.id}-${file.name}-${file.size}-${index}`"
                                      >
                                        <template #prepend>
                                          <VIcon icon="mdi-file-document-outline" />
                                        </template>

                                        <VListItemTitle class="text-body-2">
                                          {{ file.name }}
                                        </VListItemTitle>

                                        <template #append>
                                          <div class="d-flex align-center ga-2">
                                            <VChip
                                              size="small"
                                              color="success"
                                              variant="tonal"
                                            >
                                              Baru
                                            </VChip>

                                            <VBtn
                                              color="error"
                                              variant="text"
                                              size="small"
                                              @click.stop="removeFile(doc.id, index)"
                                            >
                                              Hapus
                                            </VBtn>
                                          </div>
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
              </VForm>
              <VDivider class="mt-6 mb-4" />
                <div class="d-flex justify-end gap-3">
                    <VBtn
                        type="button"
                        color="secondary"
                        variant="outlined"
                        @click="confirmCancel"
                    >
                        Batal
                    </VBtn>

                    <VBtn
                        type="button"
                        color="primary"
                        :loading="isSaving"
                        @click="saveVendor"
                    >
                        Simpan
                    </VBtn>
                </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
</template>
  </div>
</template>