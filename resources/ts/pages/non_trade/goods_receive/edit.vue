<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from '@axios'
import {
  showLoadingAlert,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  formatDate,
  formatStatusPKP,
  formatNumberWithoutRp,
  toTitleCase,
  formatDecimalQty,
  sanitizeDecimalInput,
  parseDecimalInput,
} from '@/utils/textFormatter'

interface AxiosErrorShape {
  response?: {
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface GrItem {
  id: number | string
  public_id: string
  purchase_order_item_id: number | string
  purchase_order_item_public_id?: string
  item_name: string
  item_code: string
  unit: string
  qty_ordered: number
  qty_received_before: number
  qty_receive: number
  original_qty_receive: number
  qty_received_after: number
  qty_outstanding: number
  notes: string
}

interface GrAttachmentItem {
  id?: number | string
  public_id?: string
  file?: File
  file_name: string
  file_original_name: string
  file_url?: string
  file_mime_type?: string
  file_size: number
  is_existing: boolean
}

const route = useRoute()
const router = useRouter()

const publicId = computed(() =>
  String(route.query.id || ''),
)

const isLoadingDetail = ref(false)
const loadError = ref('')
const submitLoading = ref(false)

const attachmentInput = ref<File[]>([])
const attachments = ref<GrAttachmentItem[]>([])
const deletedAttachmentIds = ref<string[]>([])
const initialExistingAttachmentCount = ref(0)

const MAX_FILE_SIZE = 3 * 1024 * 1024

const form = ref({
  public_id: '',
  nomor_gr: '',
  status: '',
  tanggal_gr: '',
  nomor_surat_jalan: '',
  notes: '',

  purchase_order_public_id: '',
  purchase_order_id: null as number | string | null,
  nomor_po: '',
  tanggal_po: '',

  vendor_id: null as number | string | null,
  vendor_name: '',
  status_pkp: '',

  cabang_id: null as number | string | null,
  cabang_name: '',

  department_id: null as number | string | null,
  department_name: '',

  created_by: '',
  posted_at: '',
  posted_by: '',
})

const items = ref<GrItem[]>([])

const totalReceiveQty = computed(() => {
  return items.value.reduce((sum, item) => sum + Number(item.qty_receive || 0), 0)
})

const totalItemSelected = computed(() => {
  return items.value.filter(item => Number(item.qty_receive || 0) > 0).length
})

const isDraft = computed(() => {
  return String(form.value.status || '').toUpperCase() === 'DRAFT'
})

const canSubmit = computed(() => {
  return (
    isDraft.value &&
    !!form.value.tanggal_gr &&
    items.value.length > 0 &&
    totalReceiveQty.value > 0 &&
    items.value.every(item => Number(item.qty_receive || 0) > 0)
  )
})

const backToIndex = async (): Promise<void> => {
  await router.replace({
    path: '/non_trade/goods_receive',
  })
}

const handleQtyReceiveInput = (value: string | number, index: number): void => {
  if (!items.value[index]) return

  const sanitized = sanitizeDecimalInput(value, {
    maxIntegerLength: 12,
    maxDecimalLength: 2,
  })

  items.value[index].qty_receive = parseDecimalInput(sanitized)
}

const formatFileSize = (size: number): string => {
  if (!size) return '-'

  const kb = size / 1024
  if (kb < 1024) return `${kb.toFixed(2)} KB`

  return `${(kb / 1024).toFixed(2)} MB`
}

const handleAttachmentChange = (files: File[] | File | null): void => {
  if (!files) return

  const selectedFiles = Array.isArray(files) ? files : [files]
  const validFiles: GrAttachmentItem[] = []

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

    validFiles.push({
      file,
      file_name: file.name,
      file_original_name: file.name,
      file_mime_type: file.type,
      file_size: file.size,
      is_existing: false,
    })
  })

  attachments.value.push(...validFiles)
  attachmentInput.value = []
}

const removeAttachment = (index: number): void => {
  const attachment = attachments.value[index]

  if (!attachment) return

  if (attachment.is_existing && attachment.public_id) {
    if (!deletedAttachmentIds.value.includes(attachment.public_id)) {
      deletedAttachmentIds.value.push(attachment.public_id)
    }
  }

  attachments.value.splice(index, 1)
}

const shouldRemoveAllExistingAttachments = (): boolean => {
  const currentExistingAttachmentCount = attachments.value.filter(attachment => attachment.is_existing).length

  return (
    initialExistingAttachmentCount.value > 0 &&
    currentExistingAttachmentCount === 0
  )
}

const loadDetail = async (): Promise<void> => {
  isLoadingDetail.value = true
  loadError.value = ''
  deletedAttachmentIds.value = []

  try {
    if (!publicId.value) {
      throw new Error('Data Goods Receipt tidak ditemukan.')
    }

    const response = await axios.get(
      `/transaction/goods-receive/${encodeURIComponent(publicId.value)}/edit`,
      {
        headers: { Accept: 'application/json' },
      },
    )

    const data = response.data?.data ?? null

    if (!data) {
      throw new Error('Data Goods Receive tidak ditemukan.')
    }

    form.value = {
      public_id: data.public_id ?? publicId.value,
      nomor_gr: data.nomor_gr ?? '',
      status: data.status ?? '',
      tanggal_gr: data.tanggal_gr ?? '',
      nomor_surat_jalan: data.nomor_surat_jalan ?? '',
      notes: data.notes ?? '',

      purchase_order_public_id: data.purchase_order_public_id ?? data.purchase_order?.public_id ?? '',
      purchase_order_id: data.purchase_order_id ?? null,
      nomor_po: data.nomor_po ?? data.purchase_order?.nomor_po ?? '',
      tanggal_po: data.tanggal_po ?? data.purchase_order?.tanggal_po ?? '',

      vendor_id: data.vendor_id ?? data.purchase_order?.vendor_id ?? null,
      vendor_name: data.vendor_name ?? data.vendor ?? data.purchase_order?.vendor?.nama_vendor ?? '',
      status_pkp: data.status_pkp ?? data.purchase_order?.vendor?.status_pkp ?? '',

      cabang_id: data.cabang_id ?? data.purchase_order?.cabang_id ?? null,
      cabang_name: data.cabang_name ?? data.cabang ?? data.purchase_order?.cabang?.nama_cabang ?? '',

      department_id: data.department_id ?? data.purchase_order?.department_id ?? null,
      department_name: data.department_name ?? data.department ?? data.purchase_order?.department?.nama ?? '',

      created_by: data.created_by ?? '',
      posted_at: data.posted_at ?? '',
      posted_by: data.posted_by ?? '',
    }

    items.value = (data.items ?? []).map((row: any) => ({
      id: row.id,
      public_id: row.public_id,
      purchase_order_item_id: row.purchase_order_item_id,
      purchase_order_item_public_id: row.purchase_order_item_public_id ?? row.po_item_public_id ?? '',
      item_name: row.item_name ?? row.nama_item ?? '-',
      item_code: row.item_code ?? row.kode_item ?? '-',
      unit: row.unit ?? row.satuan ?? '-',
      qty_ordered: Number(row.qty_ordered ?? 0),
      qty_received_before: Number(row.qty_received_before ?? 0),
      qty_receive: Number(row.qty_receive ?? 0),
      original_qty_receive: Number(row.qty_receive ?? 0),
      qty_received_after: Number(row.qty_received_after ?? 0),
      qty_outstanding: Number(row.qty_outstanding ?? 0),
      notes: row.notes ?? '',
    }))

    attachments.value = (data.attachments ?? []).map((row: any) => ({
      id: row.id,
      public_id: row.public_id,
      file_name: row.file_name ?? '-',
      file_original_name: row.file_original_name ?? row.file_name ?? '-',
      file_url: row.file_url,
      file_mime_type: row.file_mime_type,
      file_size: Number(row.file_size ?? 0),
      is_existing: true,
    }))

    initialExistingAttachmentCount.value = attachments.value.filter(attachment => attachment.is_existing).length
    deletedAttachmentIds.value = []
  } catch (error: any) {
    const err = error as AxiosErrorShape

    loadError.value = error?.message?.includes('Public ID') || error?.message?.includes('Data Goods Receive')
      ? error.message
      : getApiErrorMessage(err, 'Gagal memuat data Goods Receive.')
  } finally {
    isLoadingDetail.value = false
  }
}

const validateItems = (): boolean => {
  if (!isDraft.value) {
    showErrorToast({
      title: 'Tidak dapat disimpan',
      text: 'Goods Receipt hanya dapat diubah jika status masih DRAFT.',
    })

    return false
  }

  if (!form.value.tanggal_gr) {
    showErrorToast({
      title: 'Tanggal wajib diisi',
      text: 'Tanggal GR wajib diisi.',
    })

    return false
  }

  if (!items.value.length) {
    showErrorToast({
      title: 'Item kosong',
      text: 'Item Goods Receipt belum tersedia.',
    })

    return false
  }

  for (const item of items.value) {
    const qtyReceive = Number(item.qty_receive || 0)

    if (qtyReceive <= 0) {
      showErrorToast({
        title: 'Qty tidak valid',
        text: `Qty receive untuk item ${item.item_name} harus lebih dari 0.`,
      })

      return false
    }

    const maxReceive =
      Number(item.original_qty_receive || 0) +
      Number(item.qty_outstanding || 0)

    if (qtyReceive > maxReceive) {
      showErrorToast({
        title: 'Qty melebihi sisa PO',
        text: `Qty receive untuk item ${item.item_name} melebihi qty yang tersedia.`,
      })

      return false
    }

    if (!item.public_id) {
      showErrorToast({
        title: 'Item tidak valid',
        text: `Public ID item ${item.item_name} tidak ditemukan.`,
      })

      return false
    }

    if (!item.purchase_order_item_public_id) {
      showErrorToast({
        title: 'Item PO tidak valid',
        text: `Purchase Order Item ID untuk ${item.item_name} tidak ditemukan.`,
      })

      return false
    }
  }

  return true
}

const setReceiveAll = (): void => {
  if (!isDraft.value) return

  items.value = items.value.map(item => {
    const qtyOrdered = Number(item.qty_ordered || 0)
    const qtyReceivedBefore = Number(item.qty_received_before || 0)

    return {
      ...item,
      qty_receive: Number((qtyOrdered - qtyReceivedBefore).toFixed(2)),
    }
  })
}

const clearReceiveQty = (): void => {
  if (!isDraft.value) return

  items.value = items.value.map(item => ({
    ...item,
    qty_receive: 0,
  }))
}

const submit = async (): Promise<void> => {
  if (!validateItems() || submitLoading.value) return

  const confirm = await showConfirmAlert({
    title: 'Yakin Simpan?',
    text: 'Data perubahan akan disimpan.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  submitLoading.value = true

  try {
    showLoadingAlert('Menyimpan Goods Receipt', 'Mohon tunggu sebentar')

    const payload = new FormData()

    payload.append('tanggal_gr', String(form.value.tanggal_gr ?? ''))
    payload.append('nomor_surat_jalan', String(form.value.nomor_surat_jalan ?? ''))
    payload.append('notes', String(form.value.notes ?? ''))

    items.value.forEach((item, index) => {
      payload.append(`items[${index}][goods_receive_item_public_id]`, String(item.public_id ?? ''))
      payload.append(`items[${index}][purchase_order_item_public_id]`, String(item.purchase_order_item_public_id ?? ''))
      payload.append(`items[${index}][qty_receive]`, String(Number(item.qty_receive || 0).toFixed(2)))
      payload.append(`items[${index}][notes]`, String(item.notes ?? ''))
    })

    /**
     * Kirim hanya attachment lama yang user hapus.
     * Kalau user tidak menghapus file lama, array ini kosong.
     * Backend tidak akan menghapus attachment existing.
     */
    deletedAttachmentIds.value.forEach((attachmentPublicId, index) => {
      payload.append(`deleted_attachment_ids[${index}]`, attachmentPublicId)
    })

    payload.append(
      'remove_all_attachments',
      shouldRemoveAllExistingAttachments() ? '1' : '0',
    )

    /**
     * Kirim hanya file baru.
     * Attachment existing tidak perlu dikirim ulang.
     */
    let newAttachmentIndex = 0

    attachments.value.forEach(attachment => {
      if (!attachment.is_existing && attachment.file) {
        payload.append(`attachments[${newAttachmentIndex}]`, attachment.file)
        newAttachmentIndex += 1
      }
    })

    await axios.post(
      `/transaction/goods-receive/${encodeURIComponent(form.value.public_id)}?_method=PUT`,
      payload,
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'multipart/form-data',
        },
      },
    )

    closeAlert()

    await router.replace({
      path: '/non_trade/goods_receive',
      query: { success: 'updated' },
    })
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal menyimpan Goods Receipt.'),
    })
  } finally {
    submitLoading.value = false
  }
}

onMounted(async () => {
  await loadDetail()
})
</script>

<template>
  <VContainer fluid>
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
                Memuat data Goods Receipt...
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
                class="text-none"
                @click="loadDetail"
            >
                Coba Lagi
            </VBtn>

            <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-arrow-left"
                class="text-none"
                @click="backToIndex"
            >
                Kembali
            </VBtn>
            </div>
        </div>
        </VCardText>
    </VCard>
    <VRow v-else>
      <VCol cols="12">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex flex-wrap align-center justify-space-between gap-4">
              <div>
                <h2 class="text-h5 font-weight-bold mb-1">
                  Form Edit Goods Receipt
                </h2>

                <div class="text-body-2 text-medium-emphasis">
                  Ubah data Goods Receipt selama status masih DRAFT.
                </div>
              </div>

              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-arrow-left"
                class="text-none"
                @click="backToIndex"
              >
                Kembali
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VAlert
          v-if="!isDraft && !isLoadingDetail"
          color="warning"
          variant="tonal"
          border="start"
          class="mb-4"
        >
          Goods Receipt ini tidak dapat diedit karena status sudah {{ form.status }}.
        </VAlert>

        <VCard class="rounded-lg">
          <VCardText>
            <VRow class="mb-3">
              <VCol cols="12" md="4">
                <AppDateTimePicker
                  v-model="form.tanggal_gr"
                  label="Tanggal GR"
                  placeholder="Pilih tanggal GR"
                  :disabled="!isDraft"
                  :config="{ dateFormat: 'Y-m-d' }"
                />
              </VCol>

              <VCol cols="12" md="4">
                <VTextField
                  v-model="form.nomor_gr"
                  label="Nomor GR"
                  readonly
                  density="compact"
                  prepend-inner-icon="tabler-file-invoice"
                />
              </VCol>
            </VRow>
            <VRow class="mb-3">
                <VCol cols="12" md="4">
                    <VTextField
                    v-model="form.nomor_po"
                    label="Purchase Order"
                    readonly
                    density="compact"
                    prepend-inner-icon="tabler-file-description"
                    />
                </VCol>

                <!-- <VCol cols="12" md="4">
                    <VTextField
                    v-model="form.nomor_surat_jalan"
                    label="No Surat Jalan"
                    placeholder="Masukkan nomor surat jalan"
                    density="compact"
                    :readonly="!isDraft"
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
              </VRow>

              <VRow>
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
                    label="Dibuat Oleh"
                    readonly
                    density="compact"
                    prepend-inner-icon="tabler-user"
                    />
                </VCol>

                <VCol cols="12">
                    <VTextarea
                    v-model="form.notes"
                    label="Catatan"
                    placeholder="Catatan Goods Receipt"
                    rows="2"
                    density="compact"
                    :readonly="!isDraft"
                    />
                </VCol>
              </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard class="rounded-lg">
          <VCardText>
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
              label="Upload Attachment"
              placeholder="Pilih file PDF atau gambar"
              accept="application/pdf,image/*"
              :disabled="!isDraft"
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
              density="compact"
              class="mt-4"
            >
              Tidak ada Lampiran.
            </VAlert>

            <VTable
              v-else
              class="text-no-wrap mt-4 rounded border"
            >
              <thead>
                <tr>
                  <th width="60">No</th>
                  <th>Nama File</th>
                  <th width="160">Ukuran</th>
                  <th width="180">Tipe</th>
                  <th width="120" class="text-center">Aksi</th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(attachment, index) in attachments"
                  :key="`${attachment.file_name}-${index}`"
                >
                  <td>{{ index + 1 }}</td>

                  <td>
                    <div class="d-flex align-center">
                      <VIcon
                        :icon="attachment.file_mime_type === 'application/pdf' ? 'tabler-file-type-pdf' : 'tabler-photo'"
                        size="20"
                        class="me-2"
                      />

                      <div>
                        <div class="font-weight-medium">
                          {{ attachment.file_original_name || attachment.file_name }}
                        </div>

                        <div class="text-caption text-medium-emphasis">
                          {{ attachment.is_existing ? 'File tersimpan' : 'File baru' }}
                        </div>
                      </div>
                    </div>
                  </td>

                  <td>
                    {{ formatFileSize(attachment.file_size) }}
                  </td>

                  <td>
                    {{ attachment.file_mime_type || '-' }}
                  </td>

                  <td class="text-center">
                    <VBtn
                      v-if="attachment.file_url"
                      icon
                      size="small"
                      variant="text"
                      color="primary"
                      :href="attachment.file_url"
                      target="_blank"
                    >
                      <VIcon icon="tabler-eye" />
                    </VBtn>

                    <VBtn
                      icon
                      size="small"
                      variant="text"
                      color="error"
                      :disabled="!isDraft"
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
                    :disabled="!items.length || !isDraft"
                    @click="setReceiveAll"
                    class="text-none"
                    >
                    Terima Semua
                    </VBtn>

                    <VBtn
                    variant="tonal"
                    color="secondary"
                    prepend-icon="tabler-x"
                    :disabled="!items.length || !isDraft"
                    @click="clearReceiveQty"
                    class="text-none"
                    >
                    Reset
                    </VBtn>
                </div>
            </div>

            <VTable class="text-no-wrap rounded border">
                <thead>
                    <tr>
                        <th width="50">No</th>
                        <th>Item</th>
                        <th width="120" class="text-end">Qty PO</th>
                        <th width="140" class="text-end">Sudah GR</th>
                        <th width="140" class="text-end">Sisa</th>
                        <th width="160" class="text-end">Qty Terima</th>
                        <th width="220">Catatan</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                    v-for="(item, index) in items"
                    :key="item.id"
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
                            {{ formatDecimalQty(item.qty_ordered) }}
                        </td>

                        <td class="text-end">
                            {{ formatDecimalQty(item.qty_received_before) }}
                        </td>

                        <td class="text-end">
                            <VChip
                            size="small"
                            color="warning"
                            variant="tonal"
                            >
                            {{ formatDecimalQty(item.qty_outstanding) }}
                            </VChip>
                        </td>

                        <td>
                            <VTextField
                                :model-value="item.qty_receive"
                                type="text"
                                inputmode="decimal"
                                min="0.01"
                                placeholder="Qty Terima"
                                density="compact"
                                hide-details="auto"
                                variant="outlined"
                                class="text-end"
                                :readonly="!isDraft"
                                @update:model-value="value => handleQtyReceiveInput(value, index)"
                            />
                        </td>

                        <td>
                            <VTextField
                            v-model="item.notes"
                            placeholder="Catatan item"
                            density="compact"
                            hide-details
                            :readonly="!isDraft"
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
                  Total Item:
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
                  <strong>{{ formatDecimalQty(totalReceiveQty) }}</strong>
                </VAlert>
              </VCol>

              <VCol cols="12" md="4">
                <VAlert
                  :color="isDraft ? 'warning' : 'secondary'"
                  variant="tonal"
                  density="compact"
                >
                  Status:
                  <strong>{{ form.status || '-' }}</strong>
                </VAlert>
              </VCol>
            </VRow>
          </VCardText>

          <VCardActions class="justify-end pa-6 pt-0">
            <VBtn
              variant="tonal"
              color="secondary"
              class="text-none"
              @click="backToIndex"
            >
              Batal
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="tabler-device-floppy"
              class="text-none"
              :loading="submitLoading"
              :disabled="!canSubmit"
              @click="submit"
            >
              Simpan Perubahan
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>