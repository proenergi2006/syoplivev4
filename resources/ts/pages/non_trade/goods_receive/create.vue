<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@axios'
import Swal from 'sweetalert2'
import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatDate, formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

interface SelectOption {
  id: number | string
  title: string
  subtitle?: string
  raw?: any
}

interface GrItem {
  po_item_id: number | string
  item_id: number | string | null
  item_name: string
  item_code: string
  unit: string
  ordered_qty: number
  received_qty: number
  remaining_qty: number
  receive_qty: number
  notes: string
}

const router = useRouter()

const loading = ref(false)
const submitLoading = ref(false)
const poLoading = ref(false)
const itemLoading = ref(false)

const poOptions = ref<SelectOption[]>([])
const selectedPo = ref<number | string | null>(null)
const userData = JSON.parse(localStorage.getItem('userData') || '{}')

const MAX_FILE_SIZE = 3 * 1024 * 1024 // 3 MB
const attachmentInput = ref<File[]>([])
const attachments = ref<File[]>([])

const form = ref({
  receive_date: new Date().toISOString().slice(0, 10),
  po_id: null as number | string | null,
  po_number: '',
  vendor_id: null as number | string | null,
  vendor_name: '',
  cabang_id: null as number | string | null,
  cabang_name: '',
  department_id: null as number | string | null,
  department_name: '',
  delivery_note_no: '',
  created_by: userData?.name ?? '',
  notes: '',
})

const items = ref<GrItem[]>([])

const formatFileSize = (size: number): string => {
  if (!size) return '0 KB'

  const units = ['B', 'KB', 'MB', 'GB']
  let fileSize = size
  let unitIndex = 0

  while (fileSize >= 1024 && unitIndex < units.length - 1) {
    fileSize /= 1024
    unitIndex++
  }

  return `${fileSize.toFixed(2)} ${units[unitIndex]}`
}

const removeAttachment = (index: number): void => {
  attachments.value.splice(index, 1)
}

const handleAttachmentChange = (files: File[] | File | null): void => {
  if (!files) return

  const selectedFiles = Array.isArray(files) ? files : [files]
  const validFiles: File[] = []

  selectedFiles.forEach(file => {
    const isValidType =
      file.type === 'application/pdf'
      || file.type.startsWith('image/')

    if (!isValidType) {
      showErrorToast({
        title: 'Format File Tidak Valid',
        text: `${file.name} hanya boleh PDF atau gambar.`,
      })

      return
    }

    if (file.size > MAX_FILE_SIZE) {
      showErrorToast({
        title: 'Ukuran File Terlalu Besar',
        text: `${file.name} melebihi batas maksimal 3 MB.`,
      })

      return
    }

    validFiles.push(file)
  })

  attachments.value.push(...validFiles)

  attachmentInput.value = []
}

const totalReceiveQty = computed(() => {
  return items.value.reduce((sum, item) => sum + Number(item.receive_qty || 0), 0)
})

const totalItemSelected = computed(() => {
  return items.value.filter(item => Number(item.receive_qty || 0) > 0).length
})

const canSubmit = computed(() => {
  return (
    !!form.value.po_id &&
    !!form.value.receive_date &&
    items.value.length > 0 &&
    totalReceiveQty.value > 0 &&
    items.value.every(item => Number(item.receive_qty || 0) <= Number(item.remaining_qty || 0))
  )
})

const formatNumber = (value: number | string | null | undefined): string => {
  const num = Number(value || 0)

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(num)
}

const confirmCancel = async (): Promise<void> => {
  const result = await showConfirmAlert({
    title: 'Batalkan perubahan?',
    text: 'Data yang sudah diisi tidak akan tersimpan. Apakah Anda yakin?',
    confirmButtonText: 'Ya, batal',
    cancelButtonText: 'Tidak',
  })

  if (result.isConfirmed) {
    router.push('/non_trade/goods_receive')
  }
}

const fetchPoOptions = async (forceReload = false): Promise<void> => {
  if (poLoading.value) return
  if (!forceReload && poOptions.value.length > 0) return

  poLoading.value = true

  try {
    const response = await axios.get('/transaction/purchase-order/dropdown-receivable', {
      headers: { Accept: 'application/json' },
    })

    const rows = response.data?.data ?? []

    poOptions.value = rows.map((row: any) => ({
      id: row.id,
      public_id: row.public_id,
      title: row.nomor_po ?? row.po_number ?? '-',
      subtitle: [
        row.vendor?.nama_vendor ?? row.vendor_name ?? '-',
        row.cabang ?? '-',
        row.department ?? '-',
      ].join(' • '),
      raw: row,
    }))
  } catch (error) {
    poOptions.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat purchase order'),
    })
  } finally {
    poLoading.value = false
  }
}

const loadPoDetail = async (public_id: number | string): Promise<void> => {
  itemLoading.value = true
  items.value = []

  try {
    const response = await axios.get(`/transaction/purchase-order/${public_id}/receivable-items`, {
      headers: { Accept: 'application/json' },
    })

    const data = response.data?.data ?? response.data

    form.value.po_id = data.public_id ?? data.id ?? public_id
    form.value.po_number = data.po_number ?? data.nomor_po ?? ''
    form.value.vendor_id = data.vendor_id ?? data.vendor?.id ?? null
    form.value.vendor_name = data.vendor_name ?? data.vendor?.nama_vendor ?? data.vendor?.name ?? ''
    form.value.cabang_id = data.cabang_id ?? data.cabang?.id ?? null
    form.value.cabang_name = data.cabang_name ?? data.cabang?.nama_cabang ?? ''
    form.value.department_id = data.department_id ?? data.department?.id ?? null
    form.value.department_name = data.department_name ?? data.department?.name ?? data.department?.nama_department ?? ''

    const rows = data.items ?? data.po_items ?? []

    items.value = rows.map((row: any) => ({
      po_item_id: row.public_id ?? row.id ?? row.po_item_id,
      item_id: row.item_id ?? row.item?.id ?? null,
      item_name: row.item_name ?? row.item?.name ?? row.nama_item ?? '-',
      item_code: row.item_code ?? row.item?.code ?? row.kode_item ?? '-',
      unit: row.satuan ?? row.unit ?? '-',
      ordered_qty: Number(row.qty ?? row.ordered_qty ?? 0),
      received_qty: Number(row.received_qty ?? row.qty_received ?? 0),
      remaining_qty: Number(row.remaining_qty ?? row.qty_remaining ?? 0),
      receive_qty: 0,
      notes: '',
    }))
  } catch (error) {
    Swal.fire({
      icon: 'error',
      title: 'Gagal memuat detail PO',
      text: 'Item PO yang masih bisa diterima belum berhasil dimuat.',
    })
  } finally {
    itemLoading.value = false
  }
}

watch(selectedPo, async value => {
  if (!value) return

  await loadPoDetail(value)
})

const setReceiveAll = (): void => {
  items.value = items.value.map(item => ({
    ...item,
    receive_qty: Number(item.remaining_qty || 0),
  }))
}

const clearReceiveQty = (): void => {
  items.value = items.value.map(item => ({
    ...item,
    receive_qty: 0,
  }))
}

const validateItems = (): boolean => {
  for (const item of items.value) {
    if (Number(item.receive_qty || 0) < 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Qty tidak valid',
        text: `Qty receive untuk item ${item.item_name} tidak boleh minus.`,
      })

      return false
    }

    if (Number(item.receive_qty || 0) > Number(item.remaining_qty || 0)) {
      Swal.fire({
        icon: 'warning',
        title: 'Qty melebihi sisa PO',
        text: `Qty receive untuk item ${item.item_name} tidak boleh melebihi remaining qty.`,
      })

      return false
    }
  }

  if (totalReceiveQty.value <= 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Item belum diisi',
      text: 'Minimal satu item harus memiliki qty receive.',
    })

    return false
  }

  return true
}

const submit = async (): Promise<void> => {
  if (!validateItems()) return

  const confirm = await showConfirmAlert({
    title: 'Yakin Simpan?',
    text: 'Data akan disimpan sebagai DRAFT Goods Receipt.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  submitLoading.value = true

  try {
    showLoadingAlert('Menyimpan data...', 'Mohon tunggu sebentar')

    const payload = new FormData()

    payload.append('purchase_order_public_id', String(form.value.po_id ?? ''))
    payload.append('tanggal_gr', String(form.value.receive_date ?? ''))
    payload.append('nomor_surat_jalan', String(form.value.delivery_note_no ?? ''))
    payload.append('created_by', String(form.value.created_by ?? ''))
    payload.append('notes', String(form.value.notes ?? ''))

    items.value
      .filter(item => Number(item.receive_qty || 0) > 0)
      .forEach((item, index) => {
        payload.append(`items[${index}][purchase_order_item_public_id]`, String(item.po_item_id))
        payload.append(`items[${index}][qty_receive]`, String(item.receive_qty || 0))
        payload.append(`items[${index}][notes]`, String(item.notes ?? ''))
      })

    attachments.value.forEach((file, index) => {
      payload.append(`attachments[${index}]`, file)
    })

    await axios.post('/transaction/goods-receive', payload, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'multipart/form-data',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_trade/goods_receive',
      query: { success: 'created' },
    })
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    console.error('[Goods Receipt] SAVE ERROR:', err)

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
      text: err?.response?.data?.message || 'Goods Receipt gagal disimpan',
    })
  } finally {
    submitLoading.value = false
  }
}

const backToIndex = async (): Promise<void> => {
  await router.replace({
    path: '/non_trade/goods_receive',
  })
}

onMounted(async () => {
  loading.value = true
  await fetchPoOptions()
  loading.value = false
})
</script>

<template>
  <section>
    <VContainer fluid>
        <VRow>
            <VCol cols="12">
                <VCard class="rounded-lg">
                <VCardText>
                    <div class="d-flex flex-wrap align-center justify-space-between gap-4">
                    <div>
                        <h2 class="text-h5 font-weight-bold mb-1">
                        Form Goods Receipt
                        </h2>
                        <div class="text-body-2 text-medium-emphasis">
                        Buat penerimaan barang berdasarkan Purchase Order.
                        </div>
                    </div>

                    <VBtn
                        variant="tonal"
                        color="secondary"
                        prepend-icon="tabler-arrow-left"
                        @click="backToIndex"
                        class="text-none"
                    >
                        Kembali
                    </VBtn>
                    </div>
                </VCardText>
                </VCard>
            </VCol>

            <VCol cols="12">
                <VCard class="rounded-lg">
                  <VCardText>
                      <VRow>
                        <VCol cols="12" md="4">
                            <AppDateTimePicker
                            v-model="form.receive_date"
                            label="Tanggal Receive"
                            placeholder="Pilih tanggal receive"
                            :config="{ dateFormat: 'Y-m-d' }"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VAutocomplete
                                v-model="selectedPo"
                                :items="poOptions"
                                :loading="poLoading"
                                item-title="title"
                                item-value="public_id"
                                label="Purchase Order"
                                placeholder="Pilih Purchase Order"
                                clearable
                                density="compact"
                                no-data-text="Purchase Order tidak ditemukan"
                                @click:control="fetchPoOptions()"
                            >
                                <template #item="{ props, item }">
                                <VListItem v-bind="props">
                                    <VListItemSubtitle>
                                    {{ item.raw.subtitle }}
                                    </VListItemSubtitle>
                                </VListItem>
                                </template>

                                <template #append-inner>
                                <VTooltip
                                    v-if="!poLoading && poOptions.length === 0"
                                    text="Reload data Purchase Order"
                                    location="top"
                                >
                                    <template #activator="{ props }">
                                    <VBtn
                                        v-bind="props"
                                        icon
                                        size="x-small"
                                        variant="text"
                                        color="primary"
                                        @click.stop.prevent="fetchPoOptions(true)"
                                    >
                                        <VIcon icon="tabler-refresh" />
                                    </VBtn>
                                    </template>
                                </VTooltip>
                                </template>
                            </VAutocomplete>
                        </VCol>

                        <!-- <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.delivery_note_no"
                            label="No Surat Jalan"
                            placeholder="Masukkan nomor surat jalan"
                            density="compact"
                            />
                        </VCol> -->

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.vendor_name"
                            label="Vendor"
                            readonly
                            density="compact"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.cabang_name"
                            label="Cabang"
                            readonly
                            density="compact"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.department_name"
                            label="Department"
                            readonly
                            density="compact"
                            />
                        </VCol>

                        <VCol cols="12" md="4">
                            <VTextField
                            v-model="form.created_by"
                            label="Diterima Oleh"
                            readonly
                            placeholder="Nama penerima barang"
                            density="compact"
                            prepend-inner-icon="tabler-user"
                            />
                        </VCol>

                        <VCol cols="12" md="12">
                            <VTextarea
                            v-model="form.notes"
                            label="Catatan"
                            placeholder="Catatan penerimaan barang"
                            rows="2"
                            density="compact"
                            />
                        </VCol>
                      </VRow>
                  </VCardText>
                </VCard>
            </VCol>

            <VCol>
              <VCard
                elevation="2"
              >
                <VCardText class="pa-6">
                  <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
                    <div>
                      <h3 class="text-h6 font-weight-bold mb-1">
                        Lampiran
                      </h3>

                      <div class="text-body-2 text-medium-emphasis">
                        Upload dokumen pendukung seperti Surat Jalan, Delivery Order, atau Foto Barang.
                      </div>
                    </div>

                    <VChip
                      color="primary"
                      variant="tonal"
                      prepend-icon="tabler-paperclip"
                    >
                      {{ attachments.length }} File
                    </VChip>
                  </div>

                  <VFileInput
                    v-model="attachmentInput"
                    multiple
                    show-size
                    clearable
                    density="comfortable"
                    variant="outlined"
                    prepend-icon=""
                    prepend-inner-icon="tabler-upload"
                    label="Upload Lampiran"
                    placeholder="Pilih file PDF atau gambar"
                    accept="application/pdf,image/*"
                    @update:model-value="handleAttachmentChange"
                  />

                  <VAlert
                    type="info"
                    variant="tonal"
                    class="mt-3"
                  >
                    Format yang diperbolehkan: PDF, JPG, JPEG, PNG.
                    Maksimal ukuran file 3 MB per file.
                  </VAlert>

                  <VAlert
                    v-if="!attachments.length"
                    type="info"
                    variant="tonal"
                    class="mt-4"
                  >
                    Belum ada file yang diupload.
                  </VAlert>

                  <VTable
                    v-else
                    class="mt-4"
                  >
                    <thead>
                      <tr>
                        <th width="60">
                          No
                        </th>

                        <th>
                          Nama File
                        </th>

                        <th width="160">
                          Ukuran
                        </th>

                        <th width="120">
                          Tipe
                        </th>

                        <th width="100">
                          Aksi
                        </th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="(file, index) in attachments"
                        :key="`${file.name}-${index}`"
                      >
                        <td>
                          {{ index + 1 }}
                        </td>

                        <td>
                          <div class="d-flex align-center">
                            <VIcon
                              icon="tabler-file"
                              size="18"
                              class="me-2"
                            />

                            <span>{{ file.name }}</span>
                          </div>
                        </td>

                        <td>
                          {{ formatFileSize(file.size) }}
                        </td>

                        <td>
                          {{ file.type || '-' }}
                        </td>

                        <td>
                          <VBtn
                            icon
                            size="small"
                            color="error"
                            variant="text"
                            @click="removeAttachment(index)"
                          >
                            <VIcon icon="tabler-trash" />
                          </VBtn>
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="12">
                <VCard class="rounded-lg">
                <VCardText>
                  <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
                    <div>
                        <h3 class="text-h6 font-weight-bold mb-1">
                        List Item
                        </h3>
                        <div class="text-body-2 text-medium-emphasis">
                        Isi qty barang yang diterima berdasarkan remaining quantity PO.
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <VBtn
                        variant="tonal"
                        color="primary"
                        prepend-icon="tabler-checks"
                        :disabled="!items.length"
                        @click="setReceiveAll"
                        class="text-none"
                        >
                        Terima Semua
                        </VBtn>

                        <VBtn
                        variant="tonal"
                        color="secondary"
                        prepend-icon="tabler-x"
                        :disabled="!items.length"
                        @click="clearReceiveQty"
                        class="text-none"
                        >
                        Reset
                        </VBtn>
                    </div>
                  </div>

                    <VProgressLinear
                    v-if="itemLoading"
                    indeterminate
                    color="primary"
                    class="mb-4"
                    />

                    <VTable class="text-no-wrap">
                    <thead>
                        <tr>
                        <th width="50">No</th>
                        <th>Item</th>
                        <th width="120" class="text-end">Qty PO</th>
                        <th width="140" class="text-end">Sudah GR</th>
                        <th width="140" class="text-end">Sisa</th>
                        <th width="160" class="text-end">Qty Receive</th>
                        <th width="220">Catatan</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-if="!items.length">
                        <td colspan="7" class="text-center py-8 text-medium-emphasis">
                            Pilih Purchase Order terlebih dahulu.
                        </td>
                        </tr>

                        <tr
                        v-for="(item, index) in items"
                        :key="item.po_item_id"
                        >
                        <td>{{ index + 1 }}</td>

                        <td>
                            <div class="font-weight-medium">
                            {{ toTitleCase(item.item_name) }}
                            </div>
                            <div class="text-caption text-medium-emphasis">
                            {{ item.unit }}
                            </div>
                        </td>

                        <td class="text-end">
                            {{ formatNumber(item.ordered_qty) }}
                        </td>

                        <td class="text-end">
                            {{ formatNumber(item.received_qty) }}
                        </td>

                        <td class="text-end">
                            <VChip
                            size="small"
                            color="warning"
                            variant="tonal"
                            >
                            {{ formatNumber(item.remaining_qty) }}
                            </VChip>
                        </td>

                        <td>
                            <VTextField
                            v-model.number="item.receive_qty"
                            type="number"
                            min="0"
                            :max="item.remaining_qty"
                            density="compact"
                            hide-details
                            class="text-end"
                            />
                        </td>

                        <td>
                            <VTextField
                            v-model="item.notes"
                            placeholder="Catatan item"
                            density="compact"
                            hide-details
                            />
                        </td>
                        </tr>
                    </tbody>
                    </VTable>

                    <VDivider class="my-4" />

                    <VRow>
                    <VCol cols="12" md="4">
                        <VAlert
                        color="primary"
                        variant="tonal"
                        density="compact"
                        >
                        Total Item Dipilih:
                        <strong>{{ totalItemSelected }}</strong>
                        </VAlert>
                    </VCol>

                    <VCol cols="12" md="4">
                        <VAlert
                        color="success"
                        variant="tonal"
                        density="compact"
                        >
                        Total Qty Terima:
                        <strong>{{ formatNumber(totalReceiveQty) }}</strong>
                        </VAlert>
                    </VCol>

                    <VCol cols="12" md="4">
                        <VAlert
                        color="info"
                        variant="tonal"
                        density="compact"
                        >
                        Status Awal:
                        <strong>Draft</strong>
                        </VAlert>
                    </VCol>
                    </VRow>
                </VCardText>

                <VCardActions class="justify-end pa-6 pt-0">
                    <VBtn
                    variant="tonal"
                    color="secondary"
                    @click.prevent.stop="confirmCancel"
                    class="text-none"
                    >
                    Batal
                    </VBtn>

                    <VBtn
                      color="primary"
                      prepend-icon="tabler-device-floppy"
                      :loading="submitLoading"
                      :disabled="!canSubmit"
                      @click="submit"
                      class="text-none"
                    >
                    Simpan
                    </VBtn>
                </VCardActions>
                </VCard>
            </VCol>
        </VRow>
  </VContainer>
  </section>
</template>