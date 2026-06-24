<script setup lang="ts">
import axios from '@axios'
import {
  computed,
  nextTick,
  onMounted,
  reactive,
  ref,
} from 'vue'
import { useRouter } from 'vue-router'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
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

  title?: string
}

interface ReturnableItem {
  id?: number | string

  goods_receive_item_public_id: string
  purchase_order_item_public_id: string

  nama_item: string
  unit_id?: number | null
  unit: string

  qty_received: number
  qty_returned_before: number
  qty_returnable: number

  qty_return: number | null
  reason_id: number | null
  reason_notes: string

  is_selected: boolean
}

interface GoodsReceiveOption {
  id?: number | string
  public_id: string

  nomor_gr: string
  tanggal_gr?: string | null

  purchase_order_id?: number | string | null
  purchase_order_public_id?: string | null

  nomor_po?: string | null
  tanggal_po?: string | null

  vendor_id?: number | null
  vendor?: string | null

  cabang_id?: number | null
  cabang?: string | null
  nama_cabang?: string | null

  department_id?: number | null
  department?: string | null
  department_name?: string | null

  items?: ReturnableItem[]

  label: string
}

interface GoodsReturnForm {
  goods_receive_public_id: string | null
  tanggal_return: string
  notes: string
}

const router = useRouter()
const permissionStore = usePermissionStore()

/*
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
*/
const canCreate = computed(() => {
  return permissionStore.can(
    'goods_return.create',
  )
})

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/
const isPageLoading = ref(false)
const isLoadingSources = ref(false)
const isLoadingSourceDetail = ref(false)
const isLoadingReasons = ref(false)
const isSaving = ref(false)
const isSubmitted = ref(false)

const loadError = ref('')

const goodsReceiveOptions = ref<GoodsReceiveOption[]>([])
const selectedGoodsReceive = ref<GoodsReceiveOption | null>(null)

const reasons = ref<GoodsReturnReason[]>([])
const items = ref<ReturnableItem[]>([])
const attachments = ref<File[]>([])

const form = reactive<GoodsReturnForm>({
  goods_receive_public_id: null,
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
| Computed form
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

const totalQtyReturnable = computed(() => {
  return items.value.reduce(
    (
      total: number,
      item: ReturnableItem,
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
      item: ReturnableItem,
    ) => {
      return total + Number(
        item.qty_return || 0,
      )
    },
    0,
  )
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

const totalAttachmentSize = computed(() => {
  return attachments.value.reduce(
    (total: number, file: File) => {
      return total + Number(file.size || 0)
    },
    0,
  )
})

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
const today = (): string => {
  const currentDate = new Date()

  currentDate.setMinutes(
    currentDate.getMinutes()
    - currentDate.getTimezoneOffset(),
  )

  return currentDate
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
  size?: number | null,
): string => {
  const bytes = Number(size || 0)

  if (!bytes)
    return '-'

  const kb = bytes / 1024

  if (kb < 1024)
    return `${kb.toFixed(2)} KB`

  return `${(kb / 1024).toFixed(2)} MB`
}

const getVendorName = (
  rawVendor: unknown,
): string => {
  if (!rawVendor)
    return '-'

  if (typeof rawVendor === 'string')
    return rawVendor

  if (
    typeof rawVendor === 'object'
    && rawVendor !== null
  ) {
    const vendor = rawVendor as Record<string, unknown>

    return String(
      vendor.nama_vendor
      ?? vendor.name
      ?? '-',
    )
  }

  return '-'
}

const normalizeReturnableItem = (
  raw: Record<string, any>,
): ReturnableItem => {
  return {
    id:
      raw.id
      ?? raw.goods_receive_item_id
      ?? null,

    goods_receive_item_public_id:
      String(
        raw.goods_receive_item_public_id
        ?? raw.public_id
        ?? '',
      ),

    purchase_order_item_public_id:
      String(
        raw.purchase_order_item_public_id
        ?? raw.po_item_public_id
        ?? '',
      ),

    nama_item:
      String(
        raw.nama_item
        ?? raw.item_name
        ?? '-',
      ),

    unit_id:
      raw.unit_id
      ?? raw.satuan_id
      ?? null,

    unit:
      String(
        raw.unit
        ?? raw.unit_name
        ?? raw.satuan
        ?? '-',
      ),

    qty_received:
      Number(
        raw.qty_received
        ?? raw.qty_receive
        ?? 0,
      ),

    qty_returned_before:
      Number(
        raw.qty_returned_before
        ?? 0,
      ),

    qty_returnable:
      Number(
        raw.qty_returnable
        ?? raw.qty_returnable_after
        ?? 0,
      ),

    qty_return:
      raw.qty_return !== null
        && raw.qty_return !== undefined
        ? Number(raw.qty_return)
        : null,

    reason_id:
      raw.reason_id
        ? Number(raw.reason_id)
        : null,

    reason_notes:
      String(
        raw.reason_notes
        ?? '',
      ),

    is_selected:
      Boolean(
        raw.is_selected
        ?? false,
      ),
  }
}

const normalizeGoodsReceiveOption = (
  raw: Record<string, any>,
): GoodsReceiveOption => {
  const publicId = String(
    raw.public_id
    ?? raw.id
    ?? '',
  )

  const vendorName = getVendorName(
    raw.vendor
    ?? raw.vendor_name,
  )

  const nomorGr = String(
    raw.nomor_gr
    ?? '-',
  )

  const nomorPo = String(
    raw.nomor_po
    ?? raw.purchase_order?.nomor_po
    ?? '-',
  )

  return {
    id:
      raw.id,

    public_id:
      publicId,

    nomor_gr:
      nomorGr,

    tanggal_gr:
      raw.tanggal_gr
      ?? null,

    purchase_order_id:
      raw.purchase_order_id
      ?? raw.purchase_order?.id
      ?? null,

    purchase_order_public_id:
      raw.purchase_order_public_id
      ?? raw.purchase_order?.public_id
      ?? null,

    nomor_po:
      nomorPo,

    tanggal_po:
      raw.tanggal_po
      ?? raw.purchase_order?.tanggal_po
      ?? null,

    vendor_id:
      raw.vendor_id
      ?? raw.vendor?.id
      ?? null,

    vendor:
      vendorName,

    cabang_id:
      raw.cabang_id
      ?? raw.cabang
      ?? null,

    cabang:
      typeof raw.cabang === 'string'
        ? raw.cabang
        : (
            raw.nama_cabang
            ?? raw.cabang_name
            ?? raw.purchase_order
              ?.cabang_data
              ?.nama_cabang
            ?? '-'
          ),

    nama_cabang:
      raw.nama_cabang
      ?? raw.cabang_name
      ?? null,

    department_id:
      raw.department_id
      ?? raw.id_department
      ?? null,

    department:
      typeof raw.department === 'string'
        ? raw.department
        : (
            raw.department_name
            ?? raw.purchase_order
              ?.department_data
              ?.nama
            ?? '-'
          ),

    department_name:
      raw.department_name
      ?? null,

    items:
      Array.isArray(raw.items)
        ? raw.items.map(
            (
              item: Record<string, any>,
            ) => {
              return normalizeReturnableItem(
                item,
              )
            },
          )
        : [],

    label:
      `${nomorGr} | PO ${nomorPo} | ${vendorName}`,
  }
}

const normalizeReason = (
  raw: Record<string, any>,
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
      raw.description
      ?? raw.deskripsi
      ?? null,

    is_active:
      raw.is_active !== false,
  }
}

const resetSelectedSource = (): void => {
  selectedGoodsReceive.value = null
  items.value = []
  itemPage.value = 1
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
| Inline validation
|--------------------------------------------------------------------------
*/
const qtyErrorMessages = (
  item: ReturnableItem,
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
  item: ReturnableItem,
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
  item: ReturnableItem,
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
| Load reasons
|--------------------------------------------------------------------------
*/
const loadReasons = async (): Promise<void> => {
  isLoadingReasons.value = true

  try {
    const response = await axios.get(
      '/transaction/goods-return/reasons',
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    const responseData = (
      response.data?.data
      ?? []
    )

    const reasonRows = Array.isArray(
      responseData,
    )
      ? responseData
      : (
          responseData.reasons
          ?? []
        )

    reasons.value = Array.isArray(reasonRows)
      ? reasonRows
          .map(
            (
              raw: Record<string, any>,
            ) => {
              return normalizeReason(raw)
            },
          )
          .filter(reason => {
            return reason.is_active !== false
          })
      : []
  }
  catch (error: unknown) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        err,
        'Gagal memuat master alasan Goods Return.',
      ),
    })

    reasons.value = []
  }
  finally {
    isLoadingReasons.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Load source Goods Receipt
|--------------------------------------------------------------------------
*/
const loadCreateData = async (
  goodsReceivePublicId?: string | null,
): Promise<void> => {
  if (goodsReceivePublicId)
    isLoadingSourceDetail.value = true
  else
    isLoadingSources.value = true

  loadError.value = ''

  try {
    const response = await axios.get(
      '/transaction/goods-return/create-data',
      {
        headers: {
          Accept: 'application/json',
        },

        params: {
          goods_receive_public_id:
            goodsReceivePublicId
            || undefined,
        },
      },
    )

    const root = (
      response.data?.data
      ?? {}
    )

    /*
    |--------------------------------------------------------------------------
    | Mendukung beberapa kemungkinan nama key response
    |--------------------------------------------------------------------------
    */
    const sourceRows = Array.isArray(root)
      ? root
      : (
          root.goods_receives
          ?? root.goods_receive_options
          ?? root.available_goods_receives
          ?? root.options
          ?? root.list
          ?? []
        )

    if (
    !goodsReceivePublicId
    && Array.isArray(sourceRows)
    && sourceRows.length
    ) {
    goodsReceiveOptions.value = sourceRows
        .map(
        (
            raw: Record<string, any>,
        ) => {
            return normalizeGoodsReceiveOption(
            raw,
            )
        },
        )
        .filter(source => {
        return Boolean(source.public_id)
        })
    }

    const rootLooksLikeSelectedSource = (
      !Array.isArray(root)
      && (
        root.nomor_gr
        || Array.isArray(root.items)
      )
    )

    const selectedRaw = Array.isArray(root)
      ? null
      : (
          root.selected_goods_receive
          ?? root.goods_receive
          ?? root.selected_gr
          ?? root.selected
          ?? (
            rootLooksLikeSelectedSource
              ? root
              : null
          )
        )

    if (goodsReceivePublicId) {
      let normalizedSelected:
        | GoodsReceiveOption
        | null = null

      if (selectedRaw) {
        normalizedSelected
          = normalizeGoodsReceiveOption(
            selectedRaw,
          )
      }
      else {
        normalizedSelected
          = goodsReceiveOptions.value.find(
            source => {
              return source.public_id
                === goodsReceivePublicId
            },
          ) ?? null
      }

      selectedGoodsReceive.value
        = normalizedSelected

      const selectedRawItems = (
        selectedRaw?.items
        ?? (
          !Array.isArray(root)
            ? root.items
            : []
        )
        ?? normalizedSelected?.items
        ?? []
      )

      items.value = Array.isArray(
        selectedRawItems,
      )
        ? selectedRawItems.map(
            (
              item: Record<string, any>,
            ) => {
              return normalizeReturnableItem(
                item,
              )
            },
          )
        : []

      itemPage.value = 1

      if (!items.value.length) {
        showWarningToast({
          title: 'Item Tidak Tersedia',
          text: 'Goods Receipt ini tidak memiliki item yang masih dapat diretur.',
        })
      }
    }
  }
  catch (error: unknown) {
    const err = error as AxiosErrorShape

    loadError.value = getApiErrorMessage(
      err,
      goodsReceivePublicId
        ? 'Gagal memuat detail Goods Receipt.'
        : 'Gagal memuat daftar Goods Receipt.',
    )

    showErrorToast({
      title: 'Error',
      text: loadError.value,
    })

    if (goodsReceivePublicId)
      resetSelectedSource()
    else
      goodsReceiveOptions.value = []
  }
  finally {
    isLoadingSources.value = false
    isLoadingSourceDetail.value = false
  }
}

const handleGoodsReceiveChange = async (
  value: string | null,
): Promise<void> => {
  form.goods_receive_public_id
    = value || null

  resetSelectedSource()

  if (!value)
    return

  await loadCreateData(value)
}


/*
|--------------------------------------------------------------------------
| Item actions
|--------------------------------------------------------------------------
*/
const toggleItemSelection = (
  item: ReturnableItem,
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
}

const toggleSelectAll = (
  value: boolean | null,
): void => {
  const selected = Boolean(value)

  selectableItems.value.forEach(item => {
    item.is_selected = selected

    if (
      selected
      && Number(item.qty_return || 0) <= 0
    ) {
      item.qty_return = Number(
        item.qty_returnable || 0,
      )
    }
  })
}

const fillMaximumQty = (): void => {
  if (!items.value.length)
    return

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
  item: ReturnableItem,
): void => {
  item.is_selected = true

  item.qty_return = Number(
    item.qty_returnable || 0,
  )
}

const updateQtyReturn = (
  item: ReturnableItem,
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

/*
|--------------------------------------------------------------------------
| Attachment
|--------------------------------------------------------------------------
*/
const handleAttachmentChange = (
  value: File[] | File | null,
): void => {
  if (Array.isArray(value)) {
    attachments.value = value

    return
  }

  attachments.value = value
    ? [value]
    : []
}

const removeAttachment = (
  index: number,
): void => {
  attachments.value.splice(
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

  for (const file of attachments.value) {
    const extension = (
      file.name
        .split('.')
        .pop()
        ?.toLowerCase()
      ?? ''
    )

    if (!allowedExtensions.includes(extension)) {
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
| Form validation
|--------------------------------------------------------------------------
*/
const validateForm = (): boolean => {
  isSubmitted.value = true

  if (!form.goods_receive_public_id) {
    showErrorToast({
      title: 'Goods Receipt wajib dipilih',
      text: 'Silakan pilih Goods Receipt.',
    })

    return false
  }

  if (!form.tanggal_return) {
    showErrorToast({
      title: 'Tanggal wajib diisi',
      text: 'Tanggal Goods Return wajib diisi.',
    })

    return false
  }

  if (!items.value.length) {
    showErrorToast({
      title: 'Item tidak tersedia',
      text: 'Tidak terdapat item yang dapat diretur.',
    })

    return false
  }

  if (!selectedItems.value.length) {
    showErrorToast({
      title: 'Item belum dipilih',
      text: 'Pilih minimal satu item yang akan diretur.',
    })

    return false
  }

  for (const item of selectedItems.value) {
    if (
      !item.goods_receive_item_public_id
      || !item.purchase_order_item_public_id
    ) {
      showErrorToast({
        title: 'Referensi item tidak valid',
        text:
          `Referensi item ${item.nama_item} tidak tersedia.`,
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
| Submit
|--------------------------------------------------------------------------
*/
const submit = async (): Promise<void> => {
  if (
    isSaving.value
    || !validateForm()
  ) {
    return
  }

  const confirmation = await showConfirmAlert({
    icon: 'question',
    title: 'Simpan Goods Return?',

    text:
      'Goods Return akan disimpan sebagai DRAFT dan belum mengubah qty Purchase Order.',

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

    payload.append(
      'goods_receive_public_id',
      String(
        form.goods_receive_public_id
        ?? '',
      ),
    )

    payload.append(
      'tanggal_return',
      String(
        form.tanggal_return
        ?? '',
      ),
    )

    payload.append(
      'notes',
      String(
        form.notes
        ?? '',
      ),
    )

    selectedItems.value.forEach(
      (
        item: ReturnableItem,
        index: number,
      ) => {
        payload.append(
          `items[${index}][goods_receive_item_public_id]`,
          String(
            item.goods_receive_item_public_id
            ?? '',
          ),
        )

        payload.append(
          `items[${index}][purchase_order_item_public_id]`,
          String(
            item.purchase_order_item_public_id
            ?? '',
          ),
        )

        payload.append(
          `items[${index}][qty_return]`,
          String(
            Number(
              item.qty_return || 0,
            ),
          ),
        )

        payload.append(
          `items[${index}][reason_id]`,
          String(
            item.reason_id
            ?? '',
          ),
        )

        payload.append(
          `items[${index}][reason_notes]`,
          String(
            item.reason_notes
            ?? '',
          ),
        )
      },
    )

    attachments.value.forEach(
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

    await axios.post(
      '/transaction/goods-return',
      payload,
      {
        headers: {
          Accept: 'application/json',
          'Content-Type':
            'multipart/form-data',
        },
      },
    )

    closeAlert()

    await router.replace({
      path:
        '/non_trade/goods_return',

      query: {
        success: 'created',
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
        'Gagal menyimpan Goods Return.',
      ),
    })
  }
  finally {
    isSaving.value = false
  }
}

const goBack = async (): Promise<void> => {
  await router.push(
    '/non_trade/goods_return',
  )
}

const handleItemSelectionChange = (
  item: ReturnableItem,
  value: unknown,
): void => {
  toggleItemSelection(
    item,
    Boolean(value),
  )
}

const handleQtyReturnChange = (
  item: ReturnableItem,
  value: unknown,
): void => {
  updateQtyReturn(
    item,
    value,
  )
}

const cancelCreate = async (): Promise<void> => {
  if (isSaving.value)
    return

  const confirmation = await showConfirmAlert({
    icon: 'question',
    title: 'Batalkan pembuatan Goods Return?',
    text:
      'Data Goods Return yang sudah diisi tidak akan disimpan. '
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
  isPageLoading.value = true

  try {
    await permissionStore.loadPermissions()

    if (!canCreate.value) {
      await router.replace('/forbidden')

      return
    }

    form.tanggal_return = today()

    await Promise.all([
      loadReasons(),
      loadCreateData(),
    ])
  }
  finally {
    isPageLoading.value = false
  }
})
</script>

<template>
  <section>
    <VProgressLinear
      v-if="isPageLoading"
      indeterminate
      color="primary"
      class="mb-4"
    />

    <VForm @submit.prevent>
      <!--
      |--------------------------------------------------------------------------
      | Header
      |--------------------------------------------------------------------------
      -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex flex-wrap align-center justify-space-between gap-4">
            <div>
              <h2 class="text-h5 font-weight-bold mb-1">
                Tambah Goods Return
              </h2>

              <div class="text-body-2 text-medium-emphasis">
                Buat pengembalian barang berdasarkan Goods Receipt yang sudah diposting.
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

      <!--
      |--------------------------------------------------------------------------
      | Informasi Goods Return
      |--------------------------------------------------------------------------
      -->
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
                <VAutocomplete
                    v-model="form.goods_receive_public_id"
                    label="Goods Receipt Sumber *"
                    placeholder="Pilih nomor Goods Receipt"
                    :items="goodsReceiveOptions"
                    item-title="nomor_gr"
                    item-value="public_id"
                    density="compact"
                    clearable
                    :loading="
                        isLoadingSources
                        || isLoadingSourceDetail
                    "
                    :disabled="isSaving"
                    :error-messages="
                        isSubmitted
                        && !form.goods_receive_public_id
                        ? [
                            'Goods Receipt sumber wajib dipilih.',
                            ]
                        : []
                    "
                    @update:model-value="handleGoodsReceiveChange"
                    >
                    <template #no-data>
                        <div class="pa-4 text-center text-medium-emphasis">
                        Tidak ada Goods Receipt yang masih dapat diretur.
                        </div>
                    </template>
                </VAutocomplete>
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
                model-value="DRAFT"
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

          <VAlert
            v-if="loadError"
            type="error"
            variant="tonal"
            class="mt-4"
          >
            {{ loadError }}
          </VAlert>
        </VCardText>
      </VCard>

      <!--
      |--------------------------------------------------------------------------
      | Informasi source GR
      |--------------------------------------------------------------------------
      -->
      <VCard
        v-if="selectedGoodsReceive"
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
                    {{ selectedGoodsReceive.nomor_gr || '-' }}
                  </div>

                  <div class="text-body-2 mt-1">
                    {{ safeFormatDate(selectedGoodsReceive.tanggal_gr) }}
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
                    {{ selectedGoodsReceive.nomor_po || '-' }}
                  </div>

                  <div class="text-body-2 mt-1">
                    Vendor:
                    {{ selectedGoodsReceive.vendor || '-' }}
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
                  selectedGoodsReceive.nama_cabang
                  || selectedGoodsReceive.cabang
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
                  selectedGoodsReceive.department_name
                  || selectedGoodsReceive.department
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
                {{ selectedGoodsReceive.vendor || '-' }}
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!--
      |--------------------------------------------------------------------------
      | Item Goods Return
      |--------------------------------------------------------------------------
      -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
            <div>
              <h3 class="text-h6 font-weight-bold mb-1">
                Item Goods Return
              </h3>

              <div class="text-body-2 text-medium-emphasis">
                Pilih item, tentukan qty, dan pilih alasan pengembalian.
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
            v-if="
              !form.goods_receive_public_id
              && !isLoadingSourceDetail
            "
            type="info"
            variant="tonal"
          >
            Pilih Goods Receipt terlebih dahulu untuk menampilkan item.
          </VAlert>

          <div
            v-else-if="isLoadingSourceDetail"
            class="py-10 text-center"
          >
            <VProgressCircular
              indeterminate
              color="primary"
            />

            <div class="mt-3 text-medium-emphasis">
              Memuat item Goods Receipt...
            </div>
          </div>

          <VAlert
            v-else-if="!items.length"
            type="warning"
            variant="tonal"
          >
            Tidak ada item yang masih dapat diretur dari Goods Receipt ini.
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
                      item.goods_receive_item_public_id
                      || item.id
                      || index
                    "
                  >
                    <td class="text-center">
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

                    <td>
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

                    <td>
                      <div class="font-weight-medium">
                        {{ safeTitleCase(item.nama_item) }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ item.unit || '-' }}
                      </div>
                    </td>

                    <td class="text-end">
                      {{ formatDecimalQty(item.qty_received) }}
                    </td>

                    <td class="text-end">
                      {{ formatDecimalQty(item.qty_returned_before) }}
                    </td>

                    <td class="text-end">
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

                    <td>
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
                          :error-messages="qtyErrorMessages(item)"
                          @update:model-value="
                            value => updateQtyReturn(
                              item,
                              value,
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
                    style="min-width: 220px; width: 220px; max-width: 220px;"
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
                        :loading="isLoadingReasons"
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
                        <template #selection="{ item: reasonItem }">
                        <span
                            class="reason-selection"
                            :title="String(reasonItem.raw.name || '')"
                        >
                            {{ reasonItem.raw.name }}
                        </span>
                        </template>
                    </VAutocomplete>
                    </td>

                    <td>
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

      <!--
      |--------------------------------------------------------------------------
      | Attachment
      |--------------------------------------------------------------------------
      -->
      <VCard
        title="Lampiran"
        class="mb-6"
      >
        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            Upload dokumen pendukung retur seperti foto barang rusak,
            surat retur, atau dokumen vendor.
          </div>

            <VFileInput
            :model-value="attachments"
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

          <template v-if="attachments.length">
            <div class="d-flex align-center justify-space-between mt-6 mb-3">
              <div class="text-subtitle-1 font-weight-bold">
                Daftar Lampiran
              </div>

              <VChip
                color="primary"
                variant="tonal"
                prepend-icon="tabler-paperclip"
              >
                {{ attachments.length }} File
                ·
                {{ formatFileSize(totalAttachmentSize) }}
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
                  v-for="(file, index) in attachments"
                  :key="`${file.name}-${index}`"
                >
                  <td>
                    {{ Number(index) + 1 }}
                  </td>

                  <td>
                    <div class="d-flex align-center">
                      <VIcon
                        icon="tabler-file"
                        size="18"
                        class="me-2"
                      />

                      <div class="font-weight-medium">
                        {{ file.name }}
                      </div>
                    </div>
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
                      @click="removeAttachment(index)"
                    >
                      <VIcon icon="tabler-trash" />

                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        Hapus File
                      </VTooltip>
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </template>
        </VCardText>
      </VCard>

      <!--
      |--------------------------------------------------------------------------
      | Actions
      |--------------------------------------------------------------------------
      -->
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
                @click="cancelCreate"
                >
                Batal
            </VBtn>

            <VBtn
            type="button"
            color="primary"
            :loading="isSaving"
            :disabled="
                isSaving
                || isLoadingSourceDetail
            "
            @click="submit"
            class="text-none"
            >
            <VIcon
                start
                icon="tabler-device-floppy"
            />

            Simpan
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VForm>
  </section>
</template>
```vue
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
</style>
```
