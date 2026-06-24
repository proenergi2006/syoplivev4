<script setup lang="ts">
import axios from '@axios'
import {
  computed,
  nextTick,
  onMounted,
  reactive,
  ref,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'

import {
  formatDate,
  formatDecimalQty,
  toTitleCase,
} from '@/utils/textFormatter'

import { usePermissionStore } from '@/stores/permission'

interface AxiosErrorShape {
  response?: {
    status?: number

    data?: {
      success?: boolean
      message?: string
      debug?: string | null
      errors?: Record<string, string[]>
    }
  }
}

interface GoodsReturnReason {
  id: number
  code: string
  name: string
  description?: string | null
  is_active?: boolean
}

interface GoodsReturnEditItem {
  goods_return_item_public_id: string
  goods_receive_item_public_id: string
  purchase_order_item_public_id: string

  nama_item: string

  unit_id?: number | null
  unit_name: string

  qty_received: number
  qty_returned_before: number
  qty_returnable: number
  qty_return: number | null

  qty_return_is_valid: boolean

  reason_id: number | null
  reason_code?: string | null
  reason_name?: string | null
  reason_notes: string

  is_selected: boolean
}

interface ExistingAttachment {
  id?: number | string
  public_id: string

  document_type?: string | null
  file_name?: string | null
  file_original_name?: string | null
  file_path?: string | null
  file_url?: string | null
  file_mime_type?: string | null
  file_size?: number | string | null
  created_at?: string | null
}

interface GoodsReturnEditResponse {
  public_id: string

  nomor_return?: string | null
  tanggal_return?: string | null
  status?: string | null
  notes?: string | null

  goods_receive_public_id?: string | null
  nomor_gr?: string | null
  tanggal_gr?: string | null

  purchase_order_public_id?: string | null
  nomor_po?: string | null
  tanggal_po?: string | null
  po_status_receive?: string | null

  vendor_id?: number | null
  vendor?: string | null

  cabang_id?: number | null
  cabang?: string | null
  nama_cabang?: string | null
  inisial_cabang?: string | null

  department_id?: number | null
  department?: string | null
  department_name?: string | null

  items?: Record<string, unknown>[]
  attachments?: Record<string, unknown>[]
  reasons?: Record<string, unknown>[]

  can_update?: boolean
}

interface GoodsReturnForm {
  tanggal_return: string
  notes: string
}

const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()

/*
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
*/
const canView = computed(() => {
  return permissionStore.can(
    'goods_return.view',
  )
})

const canUpdate = computed(() => {
  return permissionStore.can(
    'goods_return.update',
  )
})

/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/
const isLoadingDetail = ref(true)
const isSaving = ref(false)
const isSubmitted = ref(false)

const publicId = ref('')

const detail = ref<GoodsReturnEditResponse | null>(null)

const reasons = ref<GoodsReturnReason[]>([])
const items = ref<GoodsReturnEditItem[]>([])

const existingAttachments = ref<ExistingAttachment[]>([])
const deletedAttachmentIds = ref<string[]>([])

const newAttachments = ref<File[]>([])

const form = reactive<GoodsReturnForm>({
  tanggal_return: '',
  notes: '',
})

/*
|--------------------------------------------------------------------------
| Item pagination
|--------------------------------------------------------------------------
*/
const itemPage = ref(1)
const itemPerPage = ref<number | 'ALL'>(5)

const itemPerPageOptions = [
  {
    title: '5',
    value: 5,
  },
  {
    title: '10',
    value: 10,
  },
  {
    title: '20',
    value: 20,
  },
  {
    title: '50',
    value: 50,
  },
  {
    title: 'All',
    value: 'ALL',
  },
]

const itemTotalPage = computed(() => {
  if (itemPerPage.value === 'ALL')
    return 1

  return Math.ceil(
    items.value.length
    / Number(itemPerPage.value),
  ) || 1
})

const paginatedItems = computed(() => {
  if (itemPerPage.value === 'ALL')
    return items.value

  const start = (
    Number(itemPage.value) - 1
  ) * Number(itemPerPage.value)

  const end = start
    + Number(itemPerPage.value)

  return items.value.slice(
    start,
    end,
  )
})

/*
|--------------------------------------------------------------------------
| Computed items
|--------------------------------------------------------------------------
*/
const selectedItems = computed(() => {
  return items.value.filter(item => {
    return item.is_selected
  })
})

const selectedItemCount = computed(() => {
  return selectedItems.value.length
})

const selectableItems = computed(() => {
  return items.value.filter(item => {
    return Number(
      item.qty_returnable || 0,
    ) > 0
  })
})

const isAllItemsSelected = computed(() => {
  if (!selectableItems.value.length)
    return false

  return selectableItems.value.every(item => {
    return item.is_selected
  })
})

const isSomeItemsSelected = computed(() => {
  return selectableItems.value.some(item => {
    return item.is_selected
  }) && !isAllItemsSelected.value
})

const totalQtyReturnable = computed(() => {
  return items.value.reduce(
    (
      total: number,
      item: GoodsReturnEditItem,
    ) => {
      return total + Number(
        item.qty_returnable || 0,
      )
    },
    0,
  )
})

const totalQtyReturn = computed(() => {
  return selectedItems.value.reduce(
    (
      total: number,
      item: GoodsReturnEditItem,
    ) => {
      return total + Number(
        item.qty_return || 0,
      )
    },
    0,
  )
})

/*
|--------------------------------------------------------------------------
| Attachments
|--------------------------------------------------------------------------
*/
const activeExistingAttachments = computed(() => {
  return existingAttachments.value.filter(
    attachment => {
      return !deletedAttachmentIds.value.includes(
        attachment.public_id,
      )
    },
  )
})

const totalNewAttachmentSize = computed(() => {
  return newAttachments.value.reduce(
    (
      total: number,
      file: File,
    ) => {
      return total + Number(
        file.size || 0,
      )
    },
    0,
  )
})

const totalAttachmentCount = computed(() => {
  return (
    activeExistingAttachments.value.length
    + newAttachments.value.length
  )
})

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
const getRoutePublicId = (): string => {
  const queryValue = route.query.id

  if (Array.isArray(queryValue))
    return String(queryValue[0] || '')

  return String(queryValue || '')
}

const normalizeDateForInput = (
  value?: string | null,
): string => {
  if (!value)
    return ''

  const rawValue = String(value)

  const match = rawValue.match(
    /^(\d{4}-\d{2}-\d{2})/,
  )

  if (match)
    return match[1]

  const parsedDate = new Date(rawValue)

  if (Number.isNaN(parsedDate.getTime()))
    return ''

  parsedDate.setMinutes(
    parsedDate.getMinutes()
    - parsedDate.getTimezoneOffset(),
  )

  return parsedDate
    .toISOString()
    .slice(0, 10)
}

const safeFormatDate = (
  value?: string | null,
): string => {
  return formatDate(value ?? null)
}

const safeTitleCase = (
  value?: string | null,
): string => {
  return toTitleCase(value ?? '')
}

const formatFileSize = (
  value?: number | string | null,
): string => {
  const bytes = Number(value || 0)

  if (!bytes)
    return '-'

  const kb = bytes / 1024

  if (kb < 1024)
    return `${kb.toFixed(2)} KB`

  return `${(kb / 1024).toFixed(2)} MB`
}

const getReasonName = (
  value: unknown,
): string => {
  if (
    typeof value !== 'object'
    || value === null
  ) {
    return ''
  }

  const reason = value as Record<string, unknown>

  return String(
    reason.name
    ?? '',
  )
}

const findReason = (
  reasonId?: number | null,
): GoodsReturnReason | undefined => {
  return reasons.value.find(reason => {
    return Number(reason.id)
      === Number(reasonId)
  })
}

/*
|--------------------------------------------------------------------------
| Normalizer
|--------------------------------------------------------------------------
*/
const normalizeReason = (
  raw: Record<string, unknown>,
): GoodsReturnReason => {
  return {
    id:
      Number(raw.id),

    code:
      String(
        raw.code
        ?? '',
      ),

    name:
      String(
        raw.name
        ?? raw.nama
        ?? '-',
      ),

    description:
      raw.description !== undefined
        && raw.description !== null
        ? String(raw.description)
        : null,

    is_active:
      raw.is_active !== false,
  }
}

const normalizeItem = (
  raw: Record<string, unknown>,
): GoodsReturnEditItem => {
  const qtyReturn = Number(
    raw.qty_return
    ?? 0,
  )

  const qtyReturnable = Number(
    raw.qty_returnable
    ?? raw.qty_returnable_after
    ?? 0,
  )

  return {
    goods_return_item_public_id:
      String(
        raw.goods_return_item_public_id
        ?? raw.public_id
        ?? '',
      ),

    goods_receive_item_public_id:
      String(
        raw.goods_receive_item_public_id
        ?? '',
      ),

    purchase_order_item_public_id:
      String(
        raw.purchase_order_item_public_id
        ?? '',
      ),

    nama_item:
      String(
        raw.nama_item
        ?? '-',
      ),

    unit_id:
      raw.unit_id !== null
        && raw.unit_id !== undefined
        ? Number(raw.unit_id)
        : null,

    unit_name:
      String(
        raw.unit_name
        ?? raw.unit
        ?? '-',
      ),

    qty_received:
      Number(
        raw.qty_received
        ?? 0,
      ),

    qty_returned_before:
      Number(
        raw.qty_returned_before
        ?? 0,
      ),

    qty_returnable:
      qtyReturnable,

    qty_return:
      qtyReturn > 0
        ? qtyReturn
        : null,

    qty_return_is_valid:
      raw.qty_return_is_valid !== false,

    reason_id:
      raw.reason_id !== null
        && raw.reason_id !== undefined
        ? Number(raw.reason_id)
        : null,

    reason_code:
      raw.reason_code !== undefined
        && raw.reason_code !== null
        ? String(raw.reason_code)
        : null,

    reason_name:
      raw.reason_name !== undefined
        && raw.reason_name !== null
        ? String(raw.reason_name)
        : null,

    reason_notes:
      String(
        raw.reason_notes
        ?? '',
      ),

    is_selected:
      qtyReturn > 0,
  }
}

const normalizeAttachment = (
  raw: Record<string, unknown>,
): ExistingAttachment => {
  return {
    id:
      raw.id !== undefined
        ? String(raw.id)
        : undefined,

    public_id:
      String(
        raw.public_id
        ?? '',
      ),

    document_type:
      raw.document_type !== undefined
        && raw.document_type !== null
        ? String(raw.document_type)
        : null,

    file_name:
      raw.file_name !== undefined
        && raw.file_name !== null
        ? String(raw.file_name)
        : null,

    file_original_name:
      raw.file_original_name !== undefined
        && raw.file_original_name !== null
        ? String(raw.file_original_name)
        : null,

    file_path:
      raw.file_path !== undefined
        && raw.file_path !== null
        ? String(raw.file_path)
        : null,

    file_url:
      raw.file_url !== undefined
        && raw.file_url !== null
        ? String(raw.file_url)
        : null,

    file_mime_type:
      raw.file_mime_type !== undefined
        && raw.file_mime_type !== null
        ? String(raw.file_mime_type)
        : null,

    file_size:
      raw.file_size !== undefined
        && raw.file_size !== null
        ? Number(raw.file_size)
        : null,

    created_at:
      raw.created_at !== undefined
        && raw.created_at !== null
        ? String(raw.created_at)
        : null,
  }
}

/*
|--------------------------------------------------------------------------
| Inline validation
|--------------------------------------------------------------------------
*/
const qtyErrorMessages = (
  item: GoodsReturnEditItem,
): string[] => {
  if (
    !isSubmitted.value
    || !item.is_selected
  ) {
    return []
  }

  const qtyReturn = Number(
    item.qty_return || 0,
  )

  const qtyReturnable = Number(
    item.qty_returnable || 0,
  )

  if (qtyReturn <= 0) {
    return [
      'Qty return harus lebih besar dari 0.',
    ]
  }

  if (
    qtyReturn
    > (qtyReturnable + 0.0001)
  ) {
    return [
      `Maksimal qty return ${formatDecimalQty(qtyReturnable)}.`,
    ]
  }

  return []
}

const reasonErrorMessages = (
  item: GoodsReturnEditItem,
): string[] => {
  if (
    !isSubmitted.value
    || !item.is_selected
  ) {
    return []
  }

  if (!item.reason_id) {
    return [
      'Alasan retur wajib dipilih.',
    ]
  }

  return []
}

const reasonNotesErrorMessages = (
  item: GoodsReturnEditItem,
): string[] => {
  if (
    !isSubmitted.value
    || !item.is_selected
  ) {
    return []
  }

  const reason = findReason(
    item.reason_id,
  )

  if (
    String(reason?.code || '')
      .toUpperCase() === 'OTHER'
    && !item.reason_notes.trim()
  ) {
    return [
      'Keterangan alasan lain wajib diisi.',
    ]
  }

  return []
}

/*
|--------------------------------------------------------------------------
| Load edit detail
|--------------------------------------------------------------------------
*/
const loadEditData = async (): Promise<void> => {
  isLoadingDetail.value = true

  try {
    const response = await axios.get(
      `/transaction/goods-return/${encodeURIComponent(publicId.value)}/edit`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    const responseData = response.data?.data as
      | GoodsReturnEditResponse
      | undefined

    if (!responseData) {
      throw new Error(
        'Data Goods Return tidak ditemukan.',
      )
    }

    detail.value = responseData

    form.tanggal_return
      = normalizeDateForInput(
        responseData.tanggal_return,
      )

    form.notes = String(
      responseData.notes
      ?? '',
    )

    reasons.value = Array.isArray(
      responseData.reasons,
    )
      ? responseData.reasons.map(
          (
            raw: Record<string, unknown>,
          ) => {
            return normalizeReason(raw)
          },
        )
      : []

    items.value = Array.isArray(
      responseData.items,
    )
      ? responseData.items.map(
          (
            raw: Record<string, unknown>,
          ) => {
            return normalizeItem(raw)
          },
        )
      : []

    existingAttachments.value = Array.isArray(
      responseData.attachments,
    )
      ? responseData.attachments
          .map(
            (
              raw: Record<string, unknown>,
            ) => {
              return normalizeAttachment(raw)
            },
          )
          .filter(attachment => {
            return Boolean(
              attachment.public_id,
            )
          })
      : []

    deletedAttachmentIds.value = []
    newAttachments.value = []

    itemPage.value = 1

    /*
    |--------------------------------------------------------------------------
    | Backend tetap menjadi sumber validasi final
    |--------------------------------------------------------------------------
    */
    if (
      responseData.can_update === false
    ) {
      await router.replace('/forbidden')
    }
  }
  catch (error: unknown) {
    const err = error as AxiosErrorShape

    if (err.response?.status === 403) {
      await router.replace('/forbidden')

      return
    }

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal memuat data edit Goods Return.',
      ),
    })

    await router.replace(
      '/non_trade/goods_return',
    )
  }
  finally {
    isLoadingDetail.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Item actions
|--------------------------------------------------------------------------
*/
const toggleItemSelection = (
  item: GoodsReturnEditItem,
  selected: boolean,
): void => {
  item.is_selected = selected

  if (
    selected
    && Number(item.qty_return || 0) <= 0
  ) {
    item.qty_return = Number(
      item.qty_returnable || 0,
    )
  }

  if (!selected) {
    item.qty_return = null
    item.reason_id = null
    item.reason_notes = ''
  }
}

const handleItemSelectionChange = (
  item: GoodsReturnEditItem,
  value: unknown,
): void => {
  toggleItemSelection(
    item,
    Boolean(value),
  )
}

const toggleSelectAll = (
  value: unknown,
): void => {
  const selected = Boolean(value)

  selectableItems.value.forEach(item => {
    toggleItemSelection(
      item,
      selected,
    )
  })
}

const fillMaximumQty = (): void => {
  items.value.forEach(item => {
    if (
      Number(item.qty_returnable || 0) > 0
    ) {
      item.is_selected = true

      item.qty_return = Number(
        item.qty_returnable || 0,
      )
    }
  })
}

const clearItemSelection = (): void => {
  items.value.forEach(item => {
    item.is_selected = false
    item.qty_return = null
    item.reason_id = null
    item.reason_notes = ''
  })
}

const setMaximumItemQty = (
  item: GoodsReturnEditItem,
): void => {
  item.is_selected = true

  item.qty_return = Number(
    item.qty_returnable || 0,
  )
}

const updateQtyReturn = (
  item: GoodsReturnEditItem,
  value: unknown,
): void => {
  const normalizedValue = String(
    value ?? '',
  ).replace(',', '.')

  const parsedValue = Number(
    normalizedValue,
  )

  item.qty_return = Number.isFinite(
    parsedValue,
  )
    ? parsedValue
    : null

  if (
    Number(item.qty_return || 0) > 0
  ) {
    item.is_selected = true
  }
}

const handleQtyReturnChange = (
  item: GoodsReturnEditItem,
  value: unknown,
): void => {
  updateQtyReturn(
    item,
    value,
  )
}

/*
|--------------------------------------------------------------------------
| Existing attachments
|--------------------------------------------------------------------------
*/
const removeExistingAttachment = async (
  attachment: ExistingAttachment,
): Promise<void> => {
  if (!attachment.public_id)
    return

  const confirmation = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus lampiran?',

    text:
      `Lampiran "${attachment.file_original_name || attachment.file_name || '-'}" `
      + 'akan dihapus ketika Goods Return disimpan.',

    confirmButtonText: 'Ya, Hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirmation.isConfirmed)
    return

  if (
    !deletedAttachmentIds.value.includes(
      attachment.public_id,
    )
  ) {
    deletedAttachmentIds.value.push(
      attachment.public_id,
    )
  }
}

/*
|--------------------------------------------------------------------------
| New attachments
|--------------------------------------------------------------------------
*/
const handleAttachmentChange = (
  value: File[] | File | null,
): void => {
  if (Array.isArray(value)) {
    newAttachments.value = value

    return
  }

  newAttachments.value = value
    ? [value]
    : []
}

const removeNewAttachment = (
  index: number,
): void => {
  newAttachments.value.splice(
    index,
    1,
  )
}

const validateAttachments = (): boolean => {
  const allowedExtensions = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
  ]

  const maximumSize = 3 * 1024 * 1024

  for (const file of newAttachments.value) {
    const extension = (
      file.name
        .split('.')
        .pop()
        ?.toLowerCase()
      ?? ''
    )

    if (
      !allowedExtensions.includes(
        extension,
      )
    ) {
      showErrorToast({
        title: 'Format file tidak valid',

        text:
          `File "${file.name}" tidak diperbolehkan. `
          + 'Gunakan format PDF, JPG, JPEG, atau PNG.',
      })

      return false
    }

    if (file.size > maximumSize) {
      showErrorToast({
        title: 'Ukuran file terlalu besar',
        text:
          `Ukuran file "${file.name}" maksimal 3 MB.`,
      })

      return false
    }
  }

  return true
}

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/
const validateForm = (): boolean => {
  isSubmitted.value = true

  if (!form.tanggal_return) {
    showErrorToast({
      title: 'Tanggal wajib diisi',
      text: 'Tanggal Goods Return wajib diisi.',
    })

    return false
  }

  if (!selectedItems.value.length) {
    showErrorToast({
      title: 'Item belum dipilih',
      text: 'Pilih minimal satu item Goods Return.',
    })

    return false
  }

  for (const item of selectedItems.value) {
    if (
      !item.goods_return_item_public_id
      || !item.goods_receive_item_public_id
      || !item.purchase_order_item_public_id
    ) {
      showErrorToast({
        title: 'Referensi item tidak valid',

        text:
          `Referensi item ${item.nama_item} tidak lengkap.`,
      })

      return false
    }

    const qtyReturn = Number(
      item.qty_return || 0,
    )

    const qtyReturnable = Number(
      item.qty_returnable || 0,
    )

    if (qtyReturn <= 0) {
      showErrorToast({
        title: 'Qty tidak valid',

        text:
          `Qty return item ${item.nama_item} harus lebih besar dari 0.`,
      })

      return false
    }

    if (
      qtyReturn
      > (qtyReturnable + 0.0001)
    ) {
      showErrorToast({
        title: 'Qty melebihi batas',

        text:
          `Qty return item ${item.nama_item} maksimal `
          + `${formatDecimalQty(qtyReturnable)}.`,
      })

      return false
    }

    if (!item.reason_id) {
      showErrorToast({
        title: 'Alasan belum dipilih',

        text:
          `Alasan retur item ${item.nama_item} wajib dipilih.`,
      })

      return false
    }

    const reason = findReason(
      item.reason_id,
    )

    if (
      String(reason?.code || '')
        .toUpperCase() === 'OTHER'
      && !item.reason_notes.trim()
    ) {
      showErrorToast({
        title: 'Keterangan wajib diisi',

        text:
          `Keterangan alasan lain untuk item ${item.nama_item} wajib diisi.`,
      })

      return false
    }
  }

  return validateAttachments()
}

/*
|--------------------------------------------------------------------------
| Save update
|--------------------------------------------------------------------------
*/
const updateGoodsReturn = async (): Promise<void> => {
  if (
    isSaving.value
    || !validateForm()
  ) {
    return
  }

  const confirmation = await showConfirmAlert({
    icon: 'question',
    title: 'Simpan perubahan Goods Return?',

    text:
      'Perubahan Goods Return akan disimpan dan status tetap DRAFT.',

    confirmButtonText: 'Ya, Simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirmation.isConfirmed)
    return

  isSaving.value = true

  try {
    showLoadingAlert(
      'Menyimpan Goods Return...',
      'Mohon tunggu sebentar.',
    )

    const payload = new FormData()

    /*
    |--------------------------------------------------------------------------
    | Method spoofing untuk multipart update Laravel
    |--------------------------------------------------------------------------
    */
    payload.append(
      '_method',
      'PUT',
    )

    payload.append(
      'tanggal_return',
      form.tanggal_return,
    )

    payload.append(
      'notes',
      form.notes || '',
    )

    selectedItems.value.forEach(
      (
        item: GoodsReturnEditItem,
        index: number,
      ) => {
        payload.append(
          `items[${index}][goods_return_item_public_id]`,
          item.goods_return_item_public_id,
        )

        payload.append(
          `items[${index}][goods_receive_item_public_id]`,
          item.goods_receive_item_public_id,
        )

        payload.append(
          `items[${index}][purchase_order_item_public_id]`,
          item.purchase_order_item_public_id,
        )

        payload.append(
          `items[${index}][qty_return]`,
          String(
            Number(item.qty_return || 0),
          ),
        )

        payload.append(
          `items[${index}][reason_id]`,
          String(
            item.reason_id || '',
          ),
        )

        payload.append(
          `items[${index}][reason_notes]`,
          item.reason_notes || '',
        )
      },
    )

    payload.append(
      'deleted_attachment_ids',
      JSON.stringify(
        deletedAttachmentIds.value,
      ),
    )

    newAttachments.value.forEach(
      (
        file: File,
        index: number,
      ) => {
        payload.append(
          `attachments[${index}]`,
          file,
        )
      },
    )

    const response = await axios.post(
      `/transaction/goods-return/${encodeURIComponent(publicId.value)}`,
      payload,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    await router.replace({
      path:
        '/non_trade/goods_return',

      query: {
        success: 'updated',
      },
    })
  }
  catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',

      text: getApiErrorMessage(
        err,
        'Gagal memperbarui Goods Return.',
      ),
    })
  }
  finally {
    isSaving.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Cancel edit
|--------------------------------------------------------------------------
*/
const goBack = async (): Promise<void> => {
  await router.push(
    '/non_trade/goods_return',
  )
}

const cancelEdit = async (): Promise<void> => {
  if (isSaving.value)
    return

  const confirmation = await showConfirmAlert({
    icon: 'question',
    title: 'Batalkan perubahan Goods Return?',

    text:
      'Perubahan yang belum disimpan akan hilang. '
      + 'Apakah Anda yakin ingin kembali ke halaman Goods Return?',

    confirmButtonText: 'Ya, Batalkan',
    cancelButtonText: 'Tetap di Halaman',
  })

  if (!confirmation.isConfirmed)
    return

  await router.push(
    '/non_trade/goods_return',
  )
}

/*
|--------------------------------------------------------------------------
| Initialization
|--------------------------------------------------------------------------
*/
onMounted(async () => {
  await permissionStore.loadPermissions()

  if (
    !canView.value
    || !canUpdate.value
  ) {
    await router.replace('/forbidden')

    return
  }

  publicId.value = getRoutePublicId()

  if (!publicId.value) {
    showErrorToast({
      title: 'Error',
      text: 'ID Goods Return tidak ditemukan.',
    })

    await router.replace(
      '/non_trade/goods_return',
    )

    return
  }

  await loadEditData()
})
</script>

<template>
  <section>
    <!--
    |--------------------------------------------------------------------------
    | Loading detail
    |--------------------------------------------------------------------------
    -->
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
              Memuat data Goods Return...
            </div>

            <div class="text-body-2 text-medium-emphasis">
              Mohon tunggu sebentar
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!--
    |--------------------------------------------------------------------------
    | Edit form
    |--------------------------------------------------------------------------
    -->
    <VForm
      v-else
      @submit.prevent
    >
      <!-- Header -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex flex-wrap align-center justify-space-between gap-4">
            <div>
              <h2 class="text-h5 font-weight-bold mb-1">
                Edit Goods Return
              </h2>

              <div class="text-body-2 text-medium-emphasis">
                Perbarui draft Goods Return
                {{ detail?.nomor_return || '-' }}.
              </div>
            </div>

            <VBtn
                type="button"
                color="secondary"
                variant="tonal"
                :disabled="isSaving"
                @click="goBack"
                class="text-none"
            >
                <VIcon
                start
                icon="tabler-arrow-left"
                />

                Kembali
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- Informasi Goods Return -->
      <VCard
        title="Informasi Goods Return"
        class="mb-6"
      >
        <VCardText>
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                :model-value="detail?.nomor_gr || '-'"
                label="Goods Receipt"
                density="compact"
                readonly
                disabled
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <AppDateTimePicker
                v-model="form.tanggal_return"
                label="Tanggal Return *"
                density="compact"
                clearable
                :config="{
                  dateFormat: 'Y-m-d',
                }"
                :disabled="isSaving"
                :error-messages="
                  isSubmitted
                    && !form.tanggal_return
                    ? [
                        'Tanggal Goods Return wajib diisi.',
                      ]
                    : []
                "
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VTextField
                :model-value="safeTitleCase(detail?.status) || 'Draft'"
                label="Status"
                density="compact"
                readonly
                disabled
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                label="Catatan"
                placeholder="Tuliskan catatan Goods Return"
                rows="3"
                auto-grow
                counter="5000"
                :disabled="isSaving"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- Informasi sumber -->
      <VCard
        title="Informasi Goods Receipt"
        class="mb-6"
      >
        <VCardText>
          <VRow>
            <VCol
              cols="12"
              md="4"
            >
              <VCard
                variant="tonal"
                color="primary"
                class="h-100"
              >
                <VCardText>
                  <div class="text-caption text-medium-emphasis mb-1">
                    Goods Receipt
                  </div>

                  <div class="text-h6 font-weight-bold">
                    {{ detail?.nomor_gr || '-' }}
                  </div>

                  <div class="text-body-2 mt-1">
                    {{ safeFormatDate(detail?.tanggal_gr) }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VCard
                variant="tonal"
                color="success"
                class="h-100"
              >
                <VCardText>
                  <div class="text-caption text-medium-emphasis mb-1">
                    Purchase Order
                  </div>

                  <div class="text-h6 font-weight-bold">
                    {{ detail?.nomor_po || '-' }}
                  </div>

                  <div class="text-body-2 mt-1">
                    Vendor:
                    {{ detail?.vendor || '-' }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VCard
                variant="tonal"
                color="info"
                class="h-100"
              >
                <VCardText>
                  <div class="text-caption text-medium-emphasis mb-1">
                    Total Qty Dapat Diretur
                  </div>

                  <div class="text-h6 font-weight-bold">
                    {{ formatDecimalQty(totalQtyReturnable) }}
                  </div>

                  <div class="text-body-2 mt-1">
                    {{ items.length }} item tersedia
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>

          <VRow class="mt-2">
            <VCol
              cols="12"
              md="4"
            >
              <div class="text-caption text-medium-emphasis">
                Cabang
              </div>

              <div class="font-weight-medium">
                {{
                  detail?.nama_cabang
                  || detail?.cabang
                  || detail?.inisial_cabang
                  || '-'
                }}
              </div>
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <div class="text-caption text-medium-emphasis">
                Department
              </div>

              <div class="font-weight-medium">
                {{
                  detail?.department_name
                  || detail?.department
                  || '-'
                }}
              </div>
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <div class="text-caption text-medium-emphasis">
                Vendor
              </div>

              <div class="font-weight-medium">
                {{ detail?.vendor || '-' }}
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- Item Goods Return -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
            <div>
              <h3 class="text-h6 font-weight-bold mb-1">
                Item Goods Return
              </h3>

              <div class="text-body-2 text-medium-emphasis">
                Perbarui item, qty, dan alasan pengembalian.
              </div>
            </div>

            <div class="d-flex flex-wrap align-center gap-2">
              <VChip
                color="primary"
                variant="tonal"
                prepend-icon="tabler-list-check"
              >
                {{ selectedItemCount }} item dipilih
              </VChip>

              <VChip
                color="info"
                variant="tonal"
              >
                Total Return:
                {{ formatDecimalQty(totalQtyReturn) }}
              </VChip>
            </div>
          </div>

          <div
            v-if="items.length"
            class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4"
          >
            <VCheckbox
              :model-value="isAllItemsSelected"
              :indeterminate="isSomeItemsSelected"
              label="Pilih Semua Item"
              density="compact"
              hide-details
              :disabled="isSaving"
              @update:model-value="toggleSelectAll"
            />

            <div class="d-flex flex-wrap gap-2">
              <VBtn
                type="button"
                color="primary"
                variant="outlined"
                size="small"
                prepend-icon="tabler-checks"
                :disabled="isSaving"
                @click="fillMaximumQty"
                class="text-none"
              >
                Return Semua
              </VBtn>

              <VBtn
                type="button"
                color="secondary"
                variant="outlined"
                size="small"
                prepend-icon="tabler-eraser"
                :disabled="isSaving"
                @click="clearItemSelection"
                class="text-none"
              >
                Kosongkan
              </VBtn>
            </div>
          </div>

          <VAlert
            v-if="!items.length"
            type="warning"
            variant="tonal"
          >
            Tidak ada item Goods Return yang dapat diubah.
          </VAlert>

          <template v-else>
            <div class="table-responsive">
              <VTable class="text-no-wrap rounded border">
                <thead>
                  <tr>
                    <th
                      width="60"
                      class="text-center"
                    >
                      Pilih
                    </th>

                    <th width="60">
                      No
                    </th>

                    <th>
                      Item
                    </th>

                    <th class="text-end">
                      Qty Received
                    </th>

                    <th class="text-end">
                      Sudah Return
                    </th>

                    <th class="text-end">
                      Dapat Diretur
                    </th>

                    <th style="min-width: 190px;">
                      Qty Return
                    </th>

                    <th style="min-width: 350px;">
                      Alasan Return
                    </th>

                    <th style="min-width: 260px;">
                      Catatan
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="(item, index) in paginatedItems"
                    :key="
                      item.goods_return_item_public_id
                      || index
                    "
                  >
                    <td class="text-center align-top">
                      <VCheckbox
                        :model-value="item.is_selected"
                        density="compact"
                        hide-details
                        :disabled="
                          isSaving
                          || Number(item.qty_returnable || 0) <= 0
                        "
                        @update:model-value="
                          handleItemSelectionChange(
                            item,
                            $event,
                          )
                        "
                      />
                    </td>

                    <td class="align-top">
                      {{
                        itemPerPage === 'ALL'
                          ? Number(index) + 1
                          : (
                              (
                                Number(itemPage) - 1
                              )
                              * Number(itemPerPage)
                            )
                            + Number(index)
                            + 1
                      }}
                    </td>

                    <td class="align-top">
                      <div class="font-weight-medium">
                        {{ safeTitleCase(item.nama_item) }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ item.unit_name || '-' }}
                      </div>
                    </td>

                    <td class="text-end align-top">
                      {{ formatDecimalQty(item.qty_received) }}
                    </td>

                    <td class="text-end align-top">
                      {{ formatDecimalQty(item.qty_returned_before) }}
                    </td>

                    <td class="text-end align-top">
                      <VChip
                        :color="
                          Number(item.qty_returnable || 0) > 0
                            ? 'success'
                            : 'secondary'
                        "
                        variant="tonal"
                        size="small"
                      >
                        {{ formatDecimalQty(item.qty_returnable) }}
                      </VChip>
                    </td>

                    <td
                      class="align-top"
                      style="min-width: 190px;"
                    >
                      <div class="d-flex align-start gap-2">
                        <VTextField
                          :model-value="item.qty_return"
                          type="number"
                          min="0"
                          :max="item.qty_returnable"
                          step="0.01"
                          density="compact"
                          placeholder="0"
                          :disabled="
                            isSaving
                            || !item.is_selected
                          "
                          :error-messages="
                            qtyErrorMessages(item)
                          "
                          @update:model-value="
                            handleQtyReturnChange(
                              item,
                              $event,
                            )
                          "
                        />

                        <VBtn
                          type="button"
                          size="small"
                          variant="tonal"
                          color="primary"
                          :disabled="
                            isSaving
                            || Number(item.qty_returnable || 0) <= 0
                          "
                          @click="setMaximumItemQty(item)"
                          class="text-none"
                        >
                          Max
                        </VBtn>
                      </div>
                    </td>

                    <td
                      class="align-top"
                      style="
                        min-width: 220px;
                        width: 220px;
                        max-width: 220px;
                      "
                    >
                      <VAutocomplete
                        v-model="item.reason_id"
                        class="reason-autocomplete"
                        :items="reasons"
                        item-title="name"
                        item-value="id"
                        density="compact"
                        placeholder="Pilih alasan"
                        clearable
                        single-line
                        :loading="false"
                        :disabled="
                          isSaving
                          || !item.is_selected
                        "
                        :error-messages="
                          reasonErrorMessages(item)
                        "
                        :menu-props="{
                          maxHeight: 300,
                        }"
                      >
                        <template #selection="{ item: reasonOption }">
                          <span
                            class="reason-selection"
                            :title="
                              getReasonName(
                                reasonOption.raw,
                              )
                            "
                          >
                            {{
                              getReasonName(
                                reasonOption.raw,
                              )
                            }}
                          </span>
                        </template>
                      </VAutocomplete>
                    </td>

                    <td
                      class="align-top"
                      style="min-width: 260px;"
                    >
                      <VTextField
                        v-model="item.reason_notes"
                        density="compact"
                        placeholder="Catatan alasan retur"
                        maxlength="2000"
                        :disabled="
                          isSaving
                          || !item.is_selected
                        "
                        :error-messages="
                          reasonNotesErrorMessages(item)
                        "
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4">
              <div class="text-caption text-medium-emphasis">
                Total Item:
                {{ items.length }}
              </div>

              <div class="d-flex align-center gap-3">
                <VSelect
                  v-model="itemPerPage"
                  :items="itemPerPageOptions"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  hide-details
                  style="width: 110px;"
                  @update:model-value="itemPage = 1"
                />

                <VPagination
                  v-if="
                    itemPerPage !== 'ALL'
                    && items.length > Number(itemPerPage)
                  "
                  v-model="itemPage"
                  :length="itemTotalPage"
                  size="small"
                  :total-visible="3"
                />
              </div>
            </div>
          </template>
        </VCardText>
      </VCard>

      <!-- Existing attachments -->
      <VCard
        title="Lampiran Tersimpan"
        class="mb-6"
      >
        <VCardText>
          <VAlert
            v-if="!activeExistingAttachments.length"
            type="info"
            variant="tonal"
            density="compact"
          >
            Tidak ada lampiran tersimpan.
          </VAlert>

          <VTable
            v-else
            class="text-no-wrap rounded border"
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

                <th width="180">
                  Tipe
                </th>

                <th
                  width="120"
                  class="text-center"
                >
                  Aksi
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="(attachment, index) in activeExistingAttachments"
                :key="attachment.public_id"
              >
                <td>
                  {{ Number(index) + 1 }}
                </td>

                <td>
                  <div class="font-weight-medium">
                    {{
                      attachment.file_original_name
                      || attachment.file_name
                      || '-'
                    }}
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
                    type="button"
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
                    type="button"
                    icon
                    size="small"
                    variant="text"
                    color="error"
                    :disabled="isSaving"
                    @click="
                      removeExistingAttachment(
                        attachment,
                      )
                    "
                  >
                    <VIcon icon="tabler-trash" />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>

      <!-- New attachments -->
      <VCard
        title="Tambah Lampiran"
        class="mb-6"
      >
        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            Upload dokumen pendukung berupa PDF atau gambar.
          </div>

          <VFileInput
            :model-value="newAttachments"
            label="Pilih Lampiran"
            placeholder="Pilih satu atau beberapa file"
            prepend-icon="tabler-paperclip"
            multiple
            show-size
            chips
            clearable
            accept=".pdf,.jpg,.jpeg,.png"
            :disabled="isSaving"
            @update:model-value="handleAttachmentChange"
          />

          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            Format yang diperbolehkan: PDF, JPG, JPEG, dan PNG.
            Maksimal 3 MB per file.
          </VAlert>

          <template v-if="newAttachments.length">
            <div class="d-flex align-center justify-space-between mt-6 mb-3">
              <div class="text-subtitle-1 font-weight-bold">
                Lampiran Baru
              </div>

              <VChip
                color="primary"
                variant="tonal"
                prepend-icon="tabler-paperclip"
              >
                {{ newAttachments.length }} File
                ·
                {{ formatFileSize(totalNewAttachmentSize) }}
              </VChip>
            </div>

            <VTable class="text-no-wrap rounded border">
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

                  <th width="180">
                    Tipe
                  </th>

                  <th
                    width="100"
                    class="text-center"
                  >
                    Aksi
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(file, index) in newAttachments"
                  :key="`${file.name}-${index}`"
                >
                  <td>
                    {{ Number(index) + 1 }}
                  </td>

                  <td>
                    {{ file.name }}
                  </td>

                  <td>
                    {{ formatFileSize(file.size) }}
                  </td>

                  <td>
                    {{ file.type || '-' }}
                  </td>

                  <td class="text-center">
                    <VBtn
                      type="button"
                      icon
                      size="small"
                      variant="text"
                      color="error"
                      :disabled="isSaving"
                      @click="removeNewAttachment(index)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </template>

          <div class="text-caption text-medium-emphasis mt-4">
            Total lampiran setelah disimpan:
            {{ totalAttachmentCount }} file
          </div>
        </VCardText>
      </VCard>

      <!-- Actions -->
      <VCard>
        <VCardText>
          <div class="d-flex flex-wrap justify-end gap-3">
            <VBtn
                type="button"
                color="secondary"
                variant="tonal"
                prepend-icon="tabler-x"
                class="text-none"
                :disabled="isSaving"
                @click="cancelEdit"
                >
                Batal
            </VBtn>
            
            <VBtn
              type="button"
              color="primary"
              :loading="isSaving"
              :disabled="isSaving"
              @click="updateGoodsReturn"
              class="text-none"
            >
              <VIcon
                start
                icon="tabler-device-floppy"
              />

              Simpan Perubahan
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VForm>
  </section>
</template>

<style scoped>
.reason-autocomplete {
  inline-size: 100%;
  max-inline-size: 100%;
}

.reason-autocomplete :deep(.v-field__input) {
  flex-wrap: nowrap;
  min-inline-size: 0;
  overflow: hidden;
}

.reason-autocomplete :deep(.v-autocomplete__selection) {
  min-inline-size: 0;
  max-inline-size: 100%;
  overflow: hidden;
}

.reason-selection {
  display: block;
  overflow: hidden;
  max-inline-size: 100%;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.table-responsive {
  overflow-x: auto;
}
</style>