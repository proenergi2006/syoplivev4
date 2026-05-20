<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef } from 'vue'
import { useRouter } from 'vue-router'
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
  cabang: number | null
  id_department: number | null
  jenis_pembayaran: string
  top: number | null
  notes: string
  purchase_request_ids: number[]
}

interface VendorOption {
  id: number
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
  satuan: string
  harga_unit: number
  subtotal: number
}

const router = useRouter()

const isSubmitted = ref(false)
const isSaving = ref(false)

const prPage = ref(1)
const prPerPage = ref<number | 'ALL'>(5)

const vendorList = ref<VendorOption[]>([])
const cabangList = ref<any[]>([])
const departmentList = ref<any[]>([])
const purchaseRequestList = ref<PurchaseRequestOption[]>([])
const poItems = ref<PurchaseOrderItem[]>([])

const isLoadingVendor = ref(false)
const isLoadingCabang = ref(false)
const isLoadingDepartment = ref(false)
const isLoadingPR = ref(false)

const form = reactive<PurchaseOrderForm>({
  tanggal_po: '',
  vendor_id: null,
  cabang: null,
  id_department: null,
  jenis_pembayaran: '',
  top: null,
  notes: '',
  purchase_request_ids: [],
})

const tanggalPO = useNativeDatePicker(toRef(form, 'tanggal_po'))

const today = (): string => new Date().toISOString().split('T')[0]

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
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

const subtotal = computed(() => {
  return poItems.value.reduce((total, item) => total + Number(item.subtotal || 0), 0)
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

const toggleSelectAllPR = async (value: boolean): Promise<void> => {
  if (value) {
    form.purchase_request_ids = purchaseRequestList.value.map(pr => pr.id)
  } else {
    form.purchase_request_ids = []
  }

  await handleSelectPurchaseRequest()
}

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

const handleSelectVendor = (): void => {
  const vendor = vendorList.value.find(item => item.id === Number(form.vendor_id))

  form.jenis_pembayaran = vendor?.jenis_pembayaran || ''
  form.top = vendor?.top || null
}

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

const handleSelectPRFilter = async (): Promise<void> => {
  form.purchase_request_ids = []
  form.vendor_id = null
  poItems.value = []
  purchaseRequestList.value = []
  vendorList.value = []
  prPage.value = 1

  if (form.id_department) {
    await loadVendors(false)
  }

  if (form.cabang && form.id_department) {
    await loadPurchaseRequestsByFilter()
  }
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

    purchaseRequestList.value = Array.isArray(response.data?.data)
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
  } catch (error: unknown) {
    purchaseRequestList.value = []

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat Purchase Request'),
    })
  } finally {
    isLoadingPR.value = false
  }
}

const visibleAttachmentMap = ref<Record<number, number>>({})

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

const handleSelectPurchaseRequest = (): void => {
  const selectedPRs = purchaseRequestList.value.filter(pr =>
    form.purchase_request_ids.includes(pr.id),
  )

  poItems.value = selectedPRs.flatMap(pr =>
    (pr.items || [])
      .filter((item: any) => Number(item.qty_outstanding ?? item.qty ?? 0) > 0)
      .map((item: any) => {
        const qtyOutstanding = Number(item.qty_outstanding ?? item.qty ?? 0)
        const hargaUnit = Number(item.harga_unit || 0)

        return {
          purchase_request_id: pr.id,
          purchase_request_item_id: Number(item.id),
          nomor_pr: pr.nomor_pr,
          nama_item: item.nama_item || '-',
          qty_pr: Number(item.qty || 0),
          qty_po_existing: Number(item.qty_po || 0),
          qty_outstanding: qtyOutstanding,
          qty: qtyOutstanding,
          satuan: item.satuan?.nama || item.satuan || '-',
          harga_unit: hargaUnit,
          subtotal: qtyOutstanding * hargaUnit,
        }
      }),
  )
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

  item.subtotal = Number(item.qty || 0) * Number(item.harga_unit || 0)
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Batalkan?',
    text: 'Data yang sudah diisi akan hilang.',
    confirmButtonText: 'Ya, batal',
    cancelButtonText: 'Batal',
  })

  if (confirm.isConfirmed) {
    await router.replace('/non_trade/purchase_order')
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

  const invalidItemIndex = poItems.value.findIndex(item =>
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
    const item = poItems.value[invalidItemIndex]

    showWarningToast({
      title: 'Warning',
      text: `Qty PO item "${item.nama_item || '-'}" wajib lebih dari 0 dan tidak boleh melebihi outstanding.`,
    })

    return false
  }

  const itemIds = poItems.value.map(item => Number(item.purchase_request_item_id))
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
  const items = poItems.value.map(item => {
    const qty = Number(item.qty || 0)
    const hargaUnit = Number(item.harga_unit || 0)

    return {
      purchase_request_id: Number(item.purchase_request_id),
      purchase_request_item_id: Number(item.purchase_request_item_id),

      nama_item: item.nama_item,
      qty,
      satuan: item.satuan,
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

const savePurchaseOrder = async (): Promise<void> => {
  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    title: 'Simpan Purchase Order?',
    text: 'Pastikan data purchase order sudah benar.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan data...', 'Mohon tunggu sebentar')

    await axios.post('/transaction/purchase-order', buildPayload(), {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_trade/purchase_order',
      query: { success: 'created' },
    })
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal menyimpan Purchase Order'),
    })
  } finally {
    isSaving.value = false
  }
}

const goBack = async (): Promise<void> => {
  await router.replace('/non_trade/purchase_order')
}

onMounted(async () => {
  form.tanggal_po = today()

  await Promise.all([
    loadVendors(false),
    fetchCabangList(false),
    fetchDepartmentList(false),
  ])
})
</script>

<template>
  <section>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        <div>
          <div class="text-h6 font-weight-bold">
            Form Purchase Order
          </div>
          <div class="text-body-2 text-medium-emphasis">
            Silakan lengkapi data purchase order dengan benar
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
          <VCol cols="12" md="6">
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
          </VCol>

          <VCol cols="12" md="6"></VCol>

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
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
              :error="isSubmitted && !form.cabang"
              :error-messages="isSubmitted && !form.cabang ? ['Cabang wajib dipilih'] : []"
              placeholder="Pilih Cabang"
              @update:model-value="handleSelectPRFilter"
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
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
              :error="isSubmitted && !form.id_department"
              :error-messages="isSubmitted && !form.id_department ? ['Department wajib dipilih'] : []"
              placeholder="Pilih Department"
              @update:model-value="handleSelectPRFilter"
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
              <VTable class="border rounded">
                <thead>
                  <tr>
                    <th class="text-center" style="width: 50px;">
                      <VCheckbox
                        :model-value="isAllSelected"
                        hide-details
                        density="compact"
                        color="primary"
                        @update:model-value="toggleSelectAllPR"
                      />
                    </th>
                    <th>Nomor PR</th>
                    <th>Lampiran</th>
                    <th class="text-center">Tanggal</th>
                    <th class="text-center">Cabang</th>
                    <th class="text-center">Department</th>
                    <th class="text-end">Total PR</th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-if="isLoadingPR">
                    <td colspan="6" class="text-center py-6">
                      Memuat Purchase Request...
                    </td>
                  </tr>

                  <tr v-else-if="!purchaseRequestList.length">
                    <td colspan="6" class="text-center text-medium-emphasis py-6">
                      Tidak ada Purchase Request tersedia untuk department ini.
                    </td>
                  </tr>

                  <tr
                    v-for="pr in paginatedPurchaseRequests"
                    v-else
                    :key="pr.id"
                  >
                    <td class="text-center">
                      <VCheckbox
                        v-model="form.purchase_request_ids"
                        :value="pr.id"
                        hide-details
                        density="compact"
                        color="primary"
                        @update:model-value="handleSelectPurchaseRequest"
                      />
                    </td>

                    <td class="font-weight-medium">
                      {{ pr.nomor_pr || '-' }}
                    </td>

                    <td class="pr-attachment-cell">
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
                            <VIcon
                              icon="tabler-paperclip"
                              size="16"
                              class="me-1"
                            />
                            <span>{{ file.original_filename || file.filename || 'Lampiran PR' }}</span>
                          </a>
                        </TransitionGroup>

                        <div class="d-flex gap-2 mt-2">
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

                      <span
                        v-else
                        class="text-medium-emphasis text-caption"
                      >
                        Tidak ada lampiran
                      </span>
                    </td>

                    <td class="text-center">
                      {{ formatDate(pr.tanggal_pr) }}
                    </td>

                    <td class="text-center">{{ pr.cabang || '-' }}</td>
                    <td class="text-center">{{ pr.department || '-' }}</td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(pr.total_amount) }}
                    </td>
                  </tr>
                </tbody>
              </VTable>

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
                      {{ group.items.length }} Item
                    </VChip>
                  </div>

                  <div class="po-item-table-wrapper">
                    <VTable class="po-item-table">
                      <thead>
                        <tr>
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
                        >
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
                              :error="isSubmitted && (!item.qty || Number(item.qty) <= 0 || Number(item.qty) > Number(item.qty_outstanding))"
                              :error-messages="isSubmitted && (!item.qty || Number(item.qty) <= 0 || Number(item.qty) > Number(item.qty_outstanding))
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
                            Rp {{ formatNumberWithoutRp(item.subtotal) }}
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
            <VCard
              variant="tonal"
              class="rounded-xl"
            >
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

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                  >
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

                <div
                  v-else
                  class="d-flex flex-wrap gap-2"
                >
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
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
              :error="isSubmitted && !form.vendor_id"
              :error-messages="isSubmitted && !form.vendor_id ? ['Vendor wajib dipilih'] : []"
              placeholder="Pilih vendor untuk PO"
              @update:model-value="handleSelectVendor"
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

          <VCol cols="12" md="6"></VCol>

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
          >
            Batal
          </VBtn>

          <VBtn
            type="button"
            color="primary"
            :loading="isSaving"
            @click="savePurchaseOrder"
          >
            Simpan
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
</style>