<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@axios'
import {
  showLoadingAlert,
  closeAlert,
  showErrorToast,
  showSuccessToast,
  showConfirmAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatNumberWithoutRp, toTitleCase } from '@/utils/textFormatter'

interface AxiosErrorShape {
  response?: {
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface ApprovalStep {
  id?: number | string
  public_id?: string
  step_order: number
  sequence?: number
  approver_type: string
  approver_id?: number | string
  approver_public_id?: string
  approver_name?: string
  approval_role_name?: string
  role_name?: string
  label?: string
  is_required?: boolean
}

interface ApprovalFlowItem {
  id?: number | string
  public_id: string
  module?: string
  module_name?: string
  document_type?: string
  document_type_label?: string
  name?: string
  approval_name?: string
  min_amount?: number | null
  max_amount?: number | null
  description?: string | null
  notes?: string | null
  is_active?: boolean
  status?: string
  steps_count?: number
  steps?: ApprovalStep[]
  created_at?: string
  updated_at?: string
}

interface MasterRoleOption {
  id: number
  name: string
  kode?: string | null
}

interface MasterUserOption {
  id: number
  name: string
  email?: string | null
}

interface EditApprovalStep {
  id?: number
  public_id?: string
  local_key: string
  step_order: number
  approver_type: 'ROLE' | 'USER'
  approver_id: number | null
  approver_name?: string
  role_name?: string
  label: string
  is_required: boolean
}

interface EditApprovalFlowForm {
  public_id: string
  name: string
  description: string
  min_amount: number | null
  max_amount: number | null
  steps: EditApprovalStep[]
}

const router = useRouter()

const isLoading = ref(false)
const isActionLoading = ref(false)

const isEditDialogOpen = ref(false)
const isEditSubmitting = ref(false)
const editSubmitted = ref(false)

const keyword = ref('')
const selectedDocumentType = ref('PO')
const selectedStatus = ref('active')

const page = ref(1)
const perPage = ref(10)
const totalItems = ref(0)
const lastPage = ref(1)

const approvalFlows = ref<ApprovalFlowItem[]>([])

const roleOptions = ref<MasterRoleOption[]>([])
const userOptions = ref<MasterUserOption[]>([])

const approverTypeOptions = [
  { title: 'Role', value: 'ROLE' },
  { title: 'User', value: 'USER' },
]

const editForm = ref<EditApprovalFlowForm>({
  public_id: '',
  name: '',
  description: '',
  min_amount: null,
  max_amount: null,
  steps: [],
})

const normalizeNumberInput = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '') return null

  const numberValue = Number(value)

  return Number.isFinite(numberValue) ? numberValue : null
}

const makeLocalKey = (): string => {
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

const openEditDialog = (flow: any): void => {
  editSubmitted.value = false

  const steps = Array.isArray(flow.steps)
    ? flow.steps
    : []

  editForm.value = {
    public_id: flow.public_id,
    name: flow.name || flow.approval_name || '',
    description: flow.description || flow.notes || '',
    min_amount: normalizeNumberInput(flow.min_amount),
    max_amount: normalizeNumberInput(flow.max_amount),
    steps: steps.map((step: any, index: number) => ({
      id: step.id,
      public_id: step.public_id,
      local_key: makeLocalKey(),
      step_order: Number(step.step_order || step.sequence || index + 1),
      approver_type: String(step.approver_type || 'ROLE').toUpperCase() === 'USER' ? 'USER' : 'ROLE',
      approver_id: typeof step.approver_id === 'object'
      ? Number(step.approver_id?.id ?? step.approver_id?.value ?? 0) || null
      : Number(step.approver_id || 0) || null,
      approver_name: step.approver_name || step.role_name || step.label || '',
      role_name: step.role_name || step.approver_name || '',
      label: step.label || step.approver_name || step.role_name || '',
      is_required: true,
    })),
  }

  if (!editForm.value.steps.length) {
    addEditStep()
  }

  isEditDialogOpen.value = true
}

const addEditStep = (): void => {
  editForm.value.steps.push({
    local_key: makeLocalKey(),
    step_order: editForm.value.steps.length + 1,
    approver_type: 'ROLE',
    approver_id: null,
    label: '',
    is_required: true,
  })
}

const removeEditStep = (index: number): void => {
  if (editForm.value.steps.length <= 1) return

  editForm.value.steps.splice(index, 1)

  editForm.value.steps = editForm.value.steps.map((step, stepIndex) => ({
    ...step,
    step_order: stepIndex + 1,
  }))
}

const onEditStepTypeChange = (index: number): void => {
  const step = editForm.value.steps[index]

  if (!step) return

  step.approver_id = null
  step.label = ''
  step.role_name = ''
  step.approver_name = ''
}

const getSelectedApproverId = (value: any): number | null => {
  if (value === null || value === undefined || value === '') return null

  if (typeof value === 'object') {
    return Number(value.id ?? value.value ?? 0) || null
  }

  return Number(value) || null
}

const isApproverAlreadySelected = (
  approverType: 'ROLE' | 'USER',
  approverId: number,
  currentStepIndex: number,
): boolean => {
  return editForm.value.steps.some((step, index) => {
    if (index === currentStepIndex) return false

    return String(step.approver_type).toUpperCase() === approverType
      && Number(getSelectedApproverId(step.approver_id)) === Number(approverId)
  })
}

const getAvailableRoleOptions = (currentStepIndex: number): MasterRoleOption[] => {
  return roleOptions.value.filter(role => {
    return !isApproverAlreadySelected('ROLE', Number(role.id), currentStepIndex)
  })
}

const getAvailableUserOptions = (currentStepIndex: number): MasterUserOption[] => {
  return userOptions.value.filter(user => {
    return !isApproverAlreadySelected('USER', Number(user.id), currentStepIndex)
  })
}

const syncEditStepLabel = (index: number): void => {
  const step = editForm.value.steps[index]

  if (!step) return

  const selectedApproverId = getSelectedApproverId(step.approver_id)

  step.approver_id = selectedApproverId

  if (!selectedApproverId) {
    step.label = ''
    step.role_name = ''
    step.approver_name = ''
    return
  }

  if (step.approver_type === 'ROLE') {
    const role = roleOptions.value.find(item => Number(item.id) === Number(selectedApproverId))

    step.label = role?.name || ''
    step.role_name = role?.name || ''
    step.approver_name = role?.name || ''

    return
  }

  const user = userOptions.value.find(item => Number(item.id) === Number(selectedApproverId))

  step.label = user?.name || ''
  step.approver_name = user?.name || ''
  step.role_name = ''
}

const closeEditDialog = (): void => {
  if (isEditSubmitting.value) return

  isEditDialogOpen.value = false
  editSubmitted.value = false

  editForm.value = {
    public_id: '',
    name: '',
    description: '',
    min_amount: null,
    max_amount: null,
    steps: [],
  }
}

const validateEditApprovalFlow = (): boolean => {
  editSubmitted.value = true

  if (!editForm.value.name) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Nama approval flow wajib diisi.',
    })

    return false
  }

  const minAmount = normalizeNumberInput(editForm.value.min_amount)
  const maxAmount = normalizeNumberInput(editForm.value.max_amount)

  if (minAmount !== null && minAmount < 0) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Minimal nilai tidak boleh lebih kecil dari 0.',
    })

    editForm.value.min_amount = null

    return false
  }

  if (maxAmount !== null && maxAmount < 0) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Maksimal nilai tidak boleh lebih kecil dari 0.',
    })

    editForm.value.max_amount = null

    return false
  }

  if (minAmount !== null && maxAmount !== null && maxAmount > 0 && maxAmount < minAmount) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Maksimal nilai tidak boleh lebih kecil dari minimal nilai.',
    })

    editForm.value.max_amount = null

    return false
  }

  if (!editForm.value.steps.length) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Minimal harus ada 1 step approval.',
    })

    return false
  }

  const invalidStepIndex = editForm.value.steps.findIndex(step => {
    return !step.approver_type || !step.approver_id
  })

  if (invalidStepIndex >= 0) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: `Approver pada step ${invalidStepIndex + 1} wajib dipilih.`,
    })

    return false
  }

  const duplicateApproverIndex = editForm.value.steps.findIndex((step, index, steps) => {
  const currentApproverType = String(step.approver_type || '').toUpperCase()
  const currentApproverId = Number(getSelectedApproverId(step.approver_id))

  return steps.findIndex(otherStep => {
      const otherApproverType = String(otherStep.approver_type || '').toUpperCase()
      const otherApproverId = Number(getSelectedApproverId(otherStep.approver_id))

      return otherApproverType === currentApproverType
        && otherApproverId === currentApproverId
    }) !== index
  })

  if (duplicateApproverIndex >= 0) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: `Approver pada step ${duplicateApproverIndex + 1} duplikat. Silakan pilih approver yang berbeda.`,
    })

    return false
  }

  editForm.value.min_amount = minAmount
  editForm.value.max_amount = maxAmount

  editForm.value.steps = editForm.value.steps.map((step, index) => ({
    ...step,
    step_order: index + 1,
    approver_type: String(step.approver_type || 'ROLE').toUpperCase() === 'USER' ? 'USER' : 'ROLE',
    approver_id: Number(step.approver_id),
    label: step.label || step.approver_name || step.role_name || '',
    is_required: true,
  }))

  return true
}

const normalizeDropdownItems = (payload: any): any[] => {
  const rawItems = payload?.data?.data
    ?? payload?.data
    ?? payload
    ?? []

  if (!Array.isArray(rawItems)) return []

  return rawItems.map((item: any) => ({
    id: Number(item.id),
    name: String(item.name || item.nama || item.label || '-'),
    email: item.email || null,
    kode: item.kode || null,
  }))
}

const loadApproverOptions = async (): Promise<void> => {
  try {
    const [roleResponse, userResponse] = await Promise.all([
      axios.get('/master/dropdown/roles', {
        headers: { Accept: 'application/json' },
      }),
      axios.get('/master/dropdown/users', {
        headers: { Accept: 'application/json' },
      }),
    ])

    roleOptions.value = normalizeDropdownItems(roleResponse.data)
    userOptions.value = normalizeDropdownItems(userResponse.data)
  } catch (error: any) {
    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message || 'Gagal memuat data role atau user approver.',
    })
  }
}

const submitEditApprovalFlow = async (): Promise<void> => {
  if (isEditSubmitting.value) return

  if (!validateEditApprovalFlow()) return

  isEditDialogOpen.value = false

  await nextTick()

  const confirm = await showConfirmAlert({
    title: 'Simpan Perubahan?',
    text: 'Data approval flow akan diperbarui.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    isEditDialogOpen.value = true
    return
  }

  isEditSubmitting.value = true

  try {
    showLoadingAlert('Menyimpan Approval Flow', 'Mohon tunggu sebentar')

    const payload = {
      name: editForm.value.name,
      description: editForm.value.description,
      min_amount: normalizeNumberInput(editForm.value.min_amount),
      max_amount: normalizeNumberInput(editForm.value.max_amount),
      steps: editForm.value.steps.map((step, index) => ({
        step_order: index + 1,
        approver_type: String(step.approver_type || 'ROLE').toUpperCase() === 'USER'
          ? 'USER'
          : 'ROLE',
        approver_id: Number(getSelectedApproverId(step.approver_id)),
        label: step.label,
        is_required: true,
      })),
    }

    const response = await axios.put(
      `/master/approval-flows/${encodeURIComponent(editForm.value.public_id)}`,
      payload,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    if (response.data?.success) {
      showSuccessToast({
        title: 'Berhasil',
        text: response.data?.message || 'Approval flow berhasil diperbarui.',
      })

      closeEditDialog()
      await loadApprovalFlows()

      return
    }

    isEditDialogOpen.value = true

    showErrorToast({
      title: 'Gagal',
      text: response.data?.message || 'Gagal memperbarui approval flow.',
    })
  } catch (error: any) {
    closeAlert()

    isEditDialogOpen.value = true

    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message || 'Gagal memperbarui approval flow.',
    })
  } finally {
    isEditSubmitting.value = false
  }
}

const documentTypeOptions = ref([
  {
    title: 'Purchase Request (PR)',
    value: 'PR',
  },
  {
    title: 'Purchase Order (PO)',
    value: 'PO',
  },
  {
    title: 'Master Vendor',
    value: 'Vendor',
  },
])

const statusOptions = ref([
  {
    title: 'Aktif',
    value: 'active',
  },
  {
    title: 'Nonaktif',
    value: 'inactive',
  },
  {
    title: 'Semua Status',
    value: 'all',
  },
])

const totalActiveFlow = computed(() => {
  return approvalFlows.value.filter(item => isFlowActive(item)).length
})

const totalInactiveFlow = computed(() => {
  return approvalFlows.value.filter(item => !isFlowActive(item)).length
})

const hasFilter = computed(() => {
  return !!keyword.value || selectedDocumentType.value !== 'PO' || selectedStatus.value !== 'active'
})

const isFlowActive = (item: ApprovalFlowItem): boolean => {
  if (typeof item.is_active === 'boolean') return item.is_active

  return String(item.status || '').toUpperCase() === 'ACTIVE'
}

const getDocumentTypeLabel = (item: ApprovalFlowItem): string => {
  if (item.document_type_label) return item.document_type_label

  const type = String(item.document_type || '').toUpperCase()

  if (type === 'PO') return 'Purchase Order (PO)'
  if (type === 'PR') return 'Purchase Requisition (PR)'
  if (type === 'VENDOR') return 'Master Vendor'

  return item.document_type || '-'
}

const getFlowName = (item: ApprovalFlowItem): string => {
  return item.name || item.approval_name || '-'
}

const getModuleName = (item: ApprovalFlowItem): string => {
  return item.module_name || item.module || '-'
}

const getFlowDescription = (item: ApprovalFlowItem): string => {
  return item.description || item.notes || '-'
}

const formatAmount = (value: number | null | undefined): string => {
  if (value === null || value === undefined) return ''

  return `Rp ${formatNumberWithoutRp(Number(value || 0))}`
}

const getAmountRangeLabel = (item: ApprovalFlowItem): string => {
  const minAmount = item.min_amount
  const maxAmount = item.max_amount

  const hasMin = minAmount !== null && minAmount !== undefined && Number(minAmount) > 0
  const hasMax = maxAmount !== null && maxAmount !== undefined && Number(maxAmount) > 0

  if (!hasMin && !hasMax) {
    return 'Semua Nilai'
  }

  if (hasMin && !hasMax) {
    return `> ${formatAmount(Number(minAmount))}`
  }

  if (!hasMin && hasMax) {
    return `≤ ${formatAmount(Number(maxAmount))}`
  }

  return `${formatAmount(Number(minAmount))} - ${formatAmount(Number(maxAmount))}`
}

const getStatusColor = (item: ApprovalFlowItem): string => {
  return isFlowActive(item) ? 'success' : 'secondary'
}

const getStatusText = (item: ApprovalFlowItem): string => {
  return isFlowActive(item) ? 'Aktif' : 'Nonaktif'
}

const getStepName = (step: ApprovalStep): string => {
  return (
    step.approver_name
    || step.approval_role_name
    || step.role_name
    || step.label
    || '-'
  )
}

const getStepOrder = (step: ApprovalStep): number => {
  return Number(step.step_order ?? step.sequence ?? 0)
}

const getSortedSteps = (item: ApprovalFlowItem): ApprovalStep[] => {
  return [...(item.steps ?? [])].sort((a, b) => getStepOrder(a) - getStepOrder(b))
}

const isBaseAllAmountFlow = (item: ApprovalFlowItem): boolean => {
  const minAmount = Number(item.min_amount || 0)
  const maxAmount = Number(item.max_amount || 0)

  return minAmount <= 0 && maxAmount <= 0
}

const getComparableMinAmount = (item: ApprovalFlowItem): number => {
  return Number(item.min_amount || 0)
}

/**
 * Ini bagian penting.
 *
 * Data DB kamu sekarang:
 * - Flow Semua Nilai = GM
 * - Flow 10 juta - 50 juta = CFO
 * - Flow > 50 juta = CEO
 *
 * Tapi untuk tampilan user, harus terlihat alur efektif:
 * - Semua Nilai = GM
 * - 10 juta - 50 juta = GM -> CFO
 * - > 50 juta = GM -> CFO -> CEO
 */
const getEffectiveApprovalSteps = (currentFlow: ApprovalFlowItem): ApprovalStep[] => {
  const currentDocumentType = String(currentFlow.document_type || '').toUpperCase()
  const currentMinAmount = getComparableMinAmount(currentFlow)

  const relatedFlows = approvalFlows.value
    .filter(flow => String(flow.document_type || '').toUpperCase() === currentDocumentType)
    .filter(flow => isFlowActive(flow))
    .filter(flow => {
      if (isBaseAllAmountFlow(flow)) return true

      const flowMinAmount = getComparableMinAmount(flow)

      return flowMinAmount <= currentMinAmount
    })
    .sort((a, b) => {
      const aMinAmount = getComparableMinAmount(a)
      const bMinAmount = getComparableMinAmount(b)

      return aMinAmount - bMinAmount
    })

  const mergedSteps: ApprovalStep[] = []
  const usedApproverKeys = new Set<string>()

  relatedFlows.forEach(flow => {
    getSortedSteps(flow).forEach(step => {
      const approverType = String(step.approver_type || '').toUpperCase()
      const approverIdentifier = String(
        step.approver_id
        || step.approver_public_id
        || step.approver_name
        || step.approval_role_name
        || step.role_name
        || step.label
        || '',
      )

      const approverKey = `${approverType}-${approverIdentifier}`

      if (!usedApproverKeys.has(approverKey)) {
        usedApproverKeys.add(approverKey)
        mergedSteps.push(step)
      }
    })
  })

  return mergedSteps.map((step, index) => ({
    ...step,
    step_order: index + 1,
    sequence: index + 1,
  }))
}

const getEffectiveStepCount = (item: ApprovalFlowItem): number => {
  return getEffectiveApprovalSteps(item).length
}

const buildParams = (): Record<string, any> => {
  const params: Record<string, any> = {
    page: page.value,
    per_page: perPage.value,
  }

  if (keyword.value) {
    params.search = keyword.value
  }

  if (selectedDocumentType.value) {
    params.document_type = selectedDocumentType.value
  }

  if (selectedStatus.value !== 'all') {
    params.status = selectedStatus.value
  }

  return params
}

const assignResponseData = (responseData: any): void => {
  if (Array.isArray(responseData)) {
    approvalFlows.value = responseData
    totalItems.value = responseData.length
    lastPage.value = 1

    return
  }

  if (Array.isArray(responseData?.data)) {
    approvalFlows.value = responseData.data
    page.value = Number(responseData.current_page ?? page.value)
    perPage.value = Number(responseData.per_page ?? perPage.value)
    totalItems.value = Number(responseData.total ?? responseData.data.length)
    lastPage.value = Number(responseData.last_page ?? 1)

    return
  }

  if (Array.isArray(responseData?.items)) {
    approvalFlows.value = responseData.items
    page.value = Number(responseData.meta?.current_page ?? page.value)
    perPage.value = Number(responseData.meta?.per_page ?? perPage.value)
    totalItems.value = Number(responseData.meta?.total ?? responseData.items.length)
    lastPage.value = Number(responseData.meta?.last_page ?? 1)

    return
  }

  approvalFlows.value = []
  totalItems.value = 0
  lastPage.value = 1
}

const loadApprovalFlows = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get('/master/approval-flows', {
      params: buildParams(),
      headers: {
        Accept: 'application/json',
      },
    })

    const responseData = response.data?.data ?? response.data

    assignResponseData(responseData)
  } catch (error: any) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Data',
      text: getApiErrorMessage(err, 'Gagal memuat data approval flow.'),
    })
  } finally {
    isLoading.value = false
  }
}

const reloadData = async (): Promise<void> => {
  page.value = 1
  await loadApprovalFlows()
}

const resetFilter = async (): Promise<void> => {
  keyword.value = ''
  selectedDocumentType.value = 'PO'
  selectedStatus.value = 'active'
  page.value = 1

  await loadApprovalFlows()
}

const goToCreate = async (): Promise<void> => {
  await router.push({
    path: '/master/approval-flows/create',
    query: {
      document_type: selectedDocumentType.value || 'PO',
    },
  })
}

const goToEdit = async (item: ApprovalFlowItem): Promise<void> => {
  if (!item.public_id) {
    showErrorToast({
      title: 'Data Tidak Valid',
      text: 'Public ID approval flow tidak ditemukan.',
    })

    return
  }

  await router.push({
    path: '/master/approval-flows/edit',
    query: {
      id: item.public_id,
    },
  })
}

const toggleStatus = async (item: ApprovalFlowItem): Promise<void> => {
  if (!item.public_id || isActionLoading.value) return

  const active = isFlowActive(item)

  const confirm = await showConfirmAlert({
    title: active ? 'Nonaktifkan Approval Flow?' : 'Aktifkan Approval Flow?',
    text: active
      ? 'Approval flow ini tidak akan digunakan pada proses approval PO.'
      : 'Approval flow ini akan digunakan kembali pada proses approval PO.',
    confirmButtonText: active ? 'Ya, nonaktifkan' : 'Ya, aktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isActionLoading.value = true

  try {
    showLoadingAlert('Memproses Approval Flow', 'Mohon tunggu sebentar.')

    await axios.patch(
      `/master/approval-flows/${encodeURIComponent(item.public_id)}/toggle-status`,
      {},
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: active
        ? 'Approval flow berhasil dinonaktifkan.'
        : 'Approval flow berhasil diaktifkan.',
    })

    await loadApprovalFlows()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memproses',
      text: getApiErrorMessage(err, 'Gagal mengubah status approval flow.'),
    })
  } finally {
    isActionLoading.value = false
  }
}

const deleteFlow = async (item: ApprovalFlowItem): Promise<void> => {
  if (!item.public_id || isActionLoading.value) return

  const confirm = await showConfirmAlert({
    title: 'Hapus Approval Flow?',
    text: 'Data approval flow yang dihapus tidak dapat digunakan lagi.',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isActionLoading.value = true

  try {
    showLoadingAlert('Menghapus Approval Flow', 'Mohon tunggu sebentar.')

    await axios.delete(
      `/master/approval-flows/${encodeURIComponent(item.public_id)}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: 'Approval flow berhasil dihapus.',
    })

    await loadApprovalFlows()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Menghapus',
      text: getApiErrorMessage(err, 'Gagal menghapus approval flow.'),
    })
  } finally {
    isActionLoading.value = false
  }
}

const goToPreviousPage = async (): Promise<void> => {
  if (page.value <= 1) return

  page.value -= 1
  await loadApprovalFlows()
}

const goToNextPage = async (): Promise<void> => {
  if (page.value >= lastPage.value) return

  page.value += 1
  await loadApprovalFlows()
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(keyword, () => {
  if (searchTimeout) clearTimeout(searchTimeout)

  searchTimeout = setTimeout(async () => {
    page.value = 1
    await loadApprovalFlows()
  }, 500)
})

watch([selectedDocumentType, selectedStatus], async () => {
  page.value = 1
  await loadApprovalFlows()
})

onMounted(async () => {
  await Promise.all([
    loadApprovalFlows(),
    loadApproverOptions(),
  ])
})
</script>

<template>
  <section>
    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row justify-space-between gap-4">
          <div>
            <div class="text-overline text-primary font-weight-bold mb-1 text-none">
              Master Approval
            </div>

            <h2 class="text-h5 font-weight-bold mb-1">
              Approval Flow
            </h2>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Atur approval berjenjang berdasarkan jenis dokumen, batas nilai, dan role approver.
            </p>
          </div>

          <div class="d-flex align-center gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-refresh"
              :loading="isLoading"
              @click="loadApprovalFlows"
              class="text-none"
            >
              Refresh
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="tabler-plus"
              @click="goToCreate"
              class="text-none"
            >
              Tambah Flow
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VRow class="mb-6">
      <VCol
        cols="12"
        md="4"
      >
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Total Flow
                </div>
                <div class="text-h5 font-weight-bold">
                  {{ totalItems }}
                </div>
              </div>

              <VAvatar
                color="primary"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-git-branch" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Flow Aktif
                </div>
                <div class="text-h5 font-weight-bold text-success">
                  {{ totalActiveFlow }}
                </div>
              </div>

              <VAvatar
                color="success"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-circle-check" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Flow Nonaktif
                </div>
                <div class="text-h5 font-weight-bold text-secondary">
                  {{ totalInactiveFlow }}
                </div>
              </div>

              <VAvatar
                color="secondary"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-circle-off" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              v-model="keyword"
              label="Cari approval flow"
              placeholder="Cari nama flow, role, keterangan..."
              prepend-inner-icon="tabler-search"
              clearable
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedDocumentType"
              :items="documentTypeOptions"
              label="Jenis Dokumen"
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedStatus"
              :items="statusOptions"
              label="Status"
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="2"
            class="d-flex align-center"
          >
            <VBtn
              block
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-filter-off"
              :disabled="!hasFilter"
              @click="resetFilter"
            >
              Reset
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard class="rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row align-md-center justify-space-between gap-3 mb-5">
          <div>
            <h3 class="text-h6 font-weight-bold mb-1">
              Daftar Approval Flow
            </h3>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Setiap flow menampilkan batas nilai dan alur approval efektif berdasarkan nominal PO.
            </p>
          </div>

          <VChip
            color="primary"
            variant="tonal"
          >
            {{ totalItems }} Flow
          </VChip>
        </div>

        <div
          v-if="isLoading"
          class="py-4"
        >
          <VSkeletonLoader
            v-for="n in 3"
            :key="n"
            type="article"
            class="mb-4"
          />
        </div>

        <div
          v-else-if="!approvalFlows.length"
          class="py-10 text-center"
        >
          <VAvatar
            color="secondary"
            variant="tonal"
            size="64"
            class="mb-4"
          >
            <VIcon
              icon="tabler-database-off"
              size="34"
            />
          </VAvatar>

          <div class="text-h6 font-weight-semibold mb-1">
            Approval flow belum tersedia
          </div>

          <div class="text-body-2 text-medium-emphasis mb-5">
            Silakan buat konfigurasi approval flow untuk Purchase Order.
          </div>

          <VBtn
            color="primary"
            prepend-icon="tabler-plus"
            @click="goToCreate"
          >
            Tambah Approval Flow
          </VBtn>
        </div>

        <VRow v-else>
          <VCol
            v-for="flow in approvalFlows"
            :key="flow.public_id"
            cols="12"
          >
            <VCard
              variant="outlined"
              class="rounded-lg approval-flow-card"
            >
              <VCardText>
                <div class="approval-flow-card-content">
                  <div class="flex-grow-1">
                    <div class="d-flex flex-wrap align-center gap-2 mb-3">
                      <VChip
                        color="primary"
                        variant="tonal"
                        size="small"
                      >
                        {{ getDocumentTypeLabel(flow) }}
                      </VChip>

                      <VChip
                        color="info"
                        variant="tonal"
                        size="small"
                      >
                        {{ getAmountRangeLabel(flow) }}
                      </VChip>

                      <VChip
                        :color="getStatusColor(flow)"
                        variant="tonal"
                        size="small"
                      >
                        {{ getStatusText(flow) }}
                      </VChip>

                      <VChip
                        color="warning"
                        variant="tonal"
                        size="small"
                      >
                        {{ getEffectiveStepCount(flow) }} Step
                      </VChip>
                    </div>

                    <div class="text-h6 font-weight-bold mb-1">
                      {{ getFlowName(flow) }}
                    </div>

                    <div class="text-body-2 text-medium-emphasis mb-4">
                      {{ getFlowDescription(flow) }}
                    </div>

                    <div class="mb-2 text-caption text-medium-emphasis">
                      Module: {{ toTitleCase(getModuleName(flow)) }}
                    </div>

                    <VDivider class="my-4" />

                    <div class="approval-step-wrapper">
                      <template
                        v-for="(step, index) in getEffectiveApprovalSteps(flow)"
                        :key="`${flow.public_id}-${step.public_id || step.id || index}`"
                      >
                        <div class="approval-step-item">
                          <VAvatar
                            color="primary"
                            variant="flat"
                            size="34"
                          >
                            <span class="text-caption font-weight-bold">
                              {{ getStepOrder(step) }}
                            </span>
                          </VAvatar>

                          <div>
                            <div class="font-weight-semibold">
                              {{ getStepName(step) }}
                            </div>

                            <div class="text-caption text-medium-emphasis">
                              {{ step.approver_type || 'ROLE' }}
                            </div>
                          </div>
                        </div>

                        <VIcon
                          v-if="index < getEffectiveApprovalSteps(flow).length - 1"
                          icon="tabler-arrow-right"
                          class="approval-step-arrow"
                          size="22"
                        />
                      </template>
                    </div>
                  </div>

                  <div class="approval-flow-actions">
                    <VBtn
                      variant="tonal"
                      color="primary"
                      prepend-icon="tabler-edit"
                      class="approval-flow-action-btn text-none"
                      @click="openEditDialog(flow)"
                    >
                      Edit
                    </VBtn>

                    <VBtn
                      variant="tonal"
                      :color="isFlowActive(flow) ? 'warning' : 'success'"
                      :prepend-icon="isFlowActive(flow) ? 'tabler-toggle-right' : 'tabler-toggle-left'"
                      :loading="isActionLoading"
                      class="approval-flow-action-btn text-none"
                      @click="toggleStatus(flow)"
                    >
                      {{ isFlowActive(flow) ? 'Nonaktifkan' : 'Aktifkan' }}
                    </VBtn>

                    <VBtn
                      variant="tonal"
                      color="error"
                      prepend-icon="tabler-trash"
                      :loading="isActionLoading"
                      class="approval-flow-action-btn text-none"
                      @click="deleteFlow(flow)"
                    >
                      Hapus
                    </VBtn>
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VDivider
          v-if="approvalFlows.length"
          class="my-5"
        />

        <div
          v-if="approvalFlows.length"
          class="d-flex flex-column flex-md-row justify-space-between align-md-center gap-3"
        >
          <div class="text-body-2 text-medium-emphasis">
            Halaman {{ page }} dari {{ lastPage }} • Total {{ totalItems }} data
          </div>

          <div class="d-flex align-center gap-2">
            <VSelect
              v-model="perPage"
              :items="[5, 10, 25, 50]"
              density="compact"
              hide-details
              style="max-width: 100px;"
              @update:model-value="reloadData"
            />

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-chevron-left"
              :disabled="page <= 1"
              @click="goToPreviousPage"
            >
              Prev
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              append-icon="tabler-chevron-right"
              :disabled="page >= lastPage"
              @click="goToNextPage"
            >
              Next
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>
    <VDialog
      v-model="isEditDialogOpen"
      max-width="720"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem class="pb-3">
          <template #prepend>
            <VAvatar
              color="primary"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-edit" />
            </VAvatar>
          </template>

          <VCardTitle class="text-h5 font-weight-bold">
            Edit Approval Flow
          </VCardTitle>

          <template #append>
            <VBtn
              icon
              variant="text"
              color="secondary"
              :disabled="isEditSubmitting"
              @click="closeEditDialog"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </template>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VAlert
            color="info"
            variant="tonal"
            class="mb-5"
          >
            Perubahan approval flow hanya berlaku untuk transaksi berikutnya. PO yang sudah disubmit tetap mengikuti history approval yang sudah tergenerate.
          </VAlert>

          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="editForm.name"
                label="Nama Approval Flow *"
                placeholder="Contoh: Approval PO 10 Juta sampai 50 Juta"
                :error="editSubmitted && !editForm.name"
                :error-messages="editSubmitted && !editForm.name ? ['Nama approval flow wajib diisi'] : []"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="editForm.min_amount"
                label="Minimal Nilai"
                placeholder="0"
                type="number"
                min="0"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="editForm.max_amount"
                label="Maksimal Nilai"
                placeholder="Kosongkan untuk tanpa batas"
                type="number"
                min="0"
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="editForm.description"
                label="Deskripsi"
                placeholder="Keterangan approval flow"
                rows="3"
                auto-grow
              />
            </VCol>
          </VRow>

          <VDivider class="my-5" />

            <div class="d-flex flex-column flex-sm-row align-sm-center justify-space-between gap-3 mb-3">
              <div>
                <div class="font-weight-bold">
                  Step Approval
                </div>
                <div class="text-caption text-medium-emphasis">
                  Atur urutan approver berdasarkan role atau user tertentu.
                </div>
              </div>

              <div class="d-flex align-center gap-2">
                <VChip
                  color="warning"
                  variant="tonal"
                  size="small"
                >
                  {{ editForm.steps.length }} Step
                </VChip>

                <VBtn
                  color="primary"
                  variant="tonal"
                  size="small"
                  prepend-icon="tabler-plus"
                  @click="addEditStep"
                  class="text-none"
                >
                  Tambah Step
                </VBtn>
              </div>
            </div>

            <div class="edit-step-wrapper">
              <VAlert
                v-if="!editForm.steps.length"
                color="warning"
                variant="tonal"
                density="compact"
              >
                Minimal harus ada 1 step approval.
              </VAlert>

              <VCard
                v-for="(step, index) in editForm.steps"
                :key="step.local_key"
                variant="outlined"
                class="edit-step-card"
              >
                <VCardText>
                  <div class="d-flex align-center justify-space-between gap-3 mb-4">
                    <div class="d-flex align-center gap-3">
                      <VAvatar
                        color="primary"
                        variant="flat"
                        size="34"
                      >
                        <span class="text-caption font-weight-bold">
                          {{ index + 1 }}
                        </span>
                      </VAvatar>

                      <div>
                        <div class="font-weight-bold">
                          Step {{ index + 1 }}
                        </div>
                        <div class="text-caption text-medium-emphasis">
                          Approval urutan ke-{{ index + 1 }}
                        </div>
                      </div>
                    </div>

                    <VBtn
                      icon
                      variant="tonal"
                      color="error"
                      size="small"
                      :disabled="editForm.steps.length <= 1"
                      @click="removeEditStep(index)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </div>

                  <VRow>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <VSelect
                        v-model="step.approver_type"
                        :items="approverTypeOptions"
                        label="Kategori Approver *"
                        density="comfortable"
                        @update:model-value="onEditStepTypeChange(index)"
                      />
                    </VCol>

                    <VCol
                      cols="12"
                      md="8"
                    >
                      <VSelect
                        v-if="step.approver_type === 'ROLE'"
                        v-model="step.approver_id"
                        :items="getAvailableRoleOptions(index)"
                        item-title="name"
                        item-value="id"
                        :return-object="false"
                        label="Pilih Role *"
                        density="comfortable"
                        clearable
                        :error="editSubmitted && !step.approver_id"
                        :error-messages="editSubmitted && !step.approver_id ? ['Role approver wajib dipilih'] : []"
                        @update:model-value="syncEditStepLabel(index)"
                      />

                      <VSelect
                        v-else
                        v-model="step.approver_id"
                        :items="getAvailableUserOptions(index)"
                        item-title="name"
                        item-value="id"
                        :return-object="false"
                        label="Pilih User *"
                        density="comfortable"
                        clearable
                        :error="editSubmitted && !step.approver_id"
                        :error-messages="editSubmitted && !step.approver_id ? ['User approver wajib dipilih'] : []"
                        @update:model-value="syncEditStepLabel(index)"
                      />
                    </VCol>

                    <VCol cols="12">
                      <VTextField
                        v-model="step.label"
                        label="Label Step"
                        placeholder="Contoh: GM Procurement / CFO / CEO"
                        density="comfortable"
                      />
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>
            </div>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-5 justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="isEditSubmitting"
            @click="closeEditDialog"
            class="text-none"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            variant="flat"
            prepend-icon="tabler-device-floppy"
            :loading="isEditSubmitting"
            @click="submitEditApprovalFlow"
            class="text-none"
          >
            Simpan Perubahan
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
.approval-flow-card {
  transition: all 0.2s ease;
}

.approval-flow-card:hover {
  border-color: rgba(var(--v-theme-primary), 0.45);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 8%);
}

.approval-step-wrapper {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}

.approval-step-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 1);
}

.approval-step-arrow {
  opacity: 0.5;
}

@media (max-width: 768px) {
  .approval-step-wrapper {
    align-items: stretch;
    flex-direction: column;
  }

  .approval-step-arrow {
    transform: rotate(90deg);
    align-self: center;
  }
}

.approval-flow-card-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.approval-flow-actions {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
  width: 100%;
}

.approval-flow-action-btn {
  width: 100%;
  min-width: 0;
}

.approval-step-wrapper {
  display: flex;
  align-items: center;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 4px;
}

.approval-step-item {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 170px;
  padding: 10px 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
}

.approval-step-arrow {
  flex: 0 0 auto;
  color: rgba(var(--v-theme-on-surface), 0.38);
}

@media (min-width: 1280px) {
  .approval-flow-card-content {
    flex-direction: row;
    justify-content: space-between;
    align-items: stretch;
  }

  .approval-flow-actions {
    width: 170px;
    flex: 0 0 170px;
    align-content: center;
  }
}

@media (max-width: 600px) {
  .approval-flow-card :deep(.v-card-text) {
    padding: 12px !important;
  }

  .approval-flow-actions {
    grid-template-columns: 1fr 1fr;
  }

  .approval-flow-actions .approval-flow-action-btn:first-child {
    grid-column: 1 / -1;
  }

  .approval-flow-action-btn {
    height: 36px;
    font-size: 11px;
    letter-spacing: 0.2px;
  }

  .approval-step-wrapper {
    flex-direction: column;
    align-items: stretch;
    overflow-x: visible;
    gap: 8px;
  }

  .approval-step-item {
    width: 100%;
    min-width: 0;
  }

  .approval-step-arrow {
    transform: rotate(90deg);
    align-self: center;
  }
}

.edit-step-wrapper {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.edit-step-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 14px;
  background: rgba(var(--v-theme-primary), 0.03);
}
</style>