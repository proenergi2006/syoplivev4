<script setup lang="ts">
import axios from '@axios'
import {
  computed,
  onMounted,
  ref,
  watch,
} from 'vue'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'
import { VAutocomplete } from 'vuetify/components'

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface RoleOption {
  id: number
  nama?: string
  name?: string
  title: string
}

interface PermissionItem {
  id: number
  module: string
  action: string
  code: string
  name: string
  description?: string | null
  is_active: boolean
}

type PermissionScope
  = | 'NONE'
    | 'OWN_DATA'
    | 'OWN_DEPARTMENT'
    | 'OWN_CABANG'
    | 'ALL'

interface RolePermissionItem {
  id?: number
  role_id?: number
  permission_id: number
  scope: PermissionScope
  is_active: boolean
  permission?: PermissionItem
}

interface PermissionFormRow {
  permission_id: number
  module: string
  action: string
  code: string
  name: string
  description?: string | null
  is_permission_active: boolean

  is_checked: boolean
  scope: PermissionScope
}

interface RolePermissionPayloadItem {
  permission_id: number
  is_active: boolean
  is_allowed: boolean
  scope: PermissionScope | null
}

interface BulkRolePermissionPayload {
  role_ids: number[]
  permissions: RolePermissionPayloadItem[]
}

const MultiAutocomplete = VAutocomplete as any

/*
|--------------------------------------------------------------------------
| Loading states
|--------------------------------------------------------------------------
*/

const isLoading = ref(false)
const isLoadingRole = ref(false)
const isLoadingPermission = ref(false)
const isLoadingRolePermission = ref(false)
const isSubmitting = ref(false)

/*
|--------------------------------------------------------------------------
| Filters and selected roles
|--------------------------------------------------------------------------
*/

const selectedRoleIds = ref<number[]>([])
const keyword = ref('')
const selectedModule = ref<string>('all')

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/

const roleOptions = ref<RoleOption[]>([])
const permissions = ref<PermissionItem[]>([])
const permissionRows = ref<PermissionFormRow[]>([])

const initialSnapshot = ref<string>('')

/*
|--------------------------------------------------------------------------
| Scope options
|--------------------------------------------------------------------------
*/

const scopeOptions: Array<{
  title: string
  value: PermissionScope
}> = [
  {
    title: 'Own Data',
    value: 'OWN_DATA',
  },
  {
    title: 'Own Department',
    value: 'OWN_DEPARTMENT',
  },
  {
    title: 'Own Cabang',
    value: 'OWN_CABANG',
  },
  {
    title: 'All Data',
    value: 'ALL',
  },
]

/*
|--------------------------------------------------------------------------
| Computed roles
|--------------------------------------------------------------------------
*/

/**
 * Role pertama menjadi sumber data permission yang ditampilkan.
 *
 * Jika user memilih:
 * - Staff IT
 * - Staff GA
 *
 * Maka permission Staff IT dimuat sebagai template,
 * lalu ketika disimpan diterapkan ke Staff IT dan Staff GA.
 */
const primarySelectedRoleId = computed<number | null>(() => {
  const firstRoleId = selectedRoleIds.value[0]

  return firstRoleId
    ? Number(firstRoleId)
    : null
})

const selectedRoles = computed<RoleOption[]>(() => {
  const selectedIds = new Set(
    selectedRoleIds.value.map(id => Number(id)),
  )

  return roleOptions.value.filter(role =>
    selectedIds.has(Number(role.id)),
  )
})

const primarySelectedRole = computed<RoleOption | null>(() => {
  if (!primarySelectedRoleId.value)
    return null

  return roleOptions.value.find(
    role => Number(role.id) === Number(primarySelectedRoleId.value),
  ) ?? null
})

const removeSelectedRole = (roleId: number): void => {
  selectedRoleIds.value = selectedRoleIds.value.filter(
    id => Number(id) !== Number(roleId),
  )
}

const selectedRoleNames = computed<string>(() => {
  return selectedRoles.value
    .map(role => role.title)
    .join(', ')
})

const hasSelectedRole = computed<boolean>(() => {
  return selectedRoleIds.value.length > 0
})

const isMultipleRoleSelection = computed<boolean>(() => {
  return selectedRoleIds.value.length > 1
})

/*
|--------------------------------------------------------------------------
| Module and permission computed
|--------------------------------------------------------------------------
*/

const moduleOptions = computed(() => {
  const modules = Array.from(
    new Set(
      permissions.value
        .map(item => item.module)
        .filter(Boolean),
    ),
  )

  return [
    {
      title: 'Semua Module',
      value: 'all',
    },
    ...modules.map(module => ({
      title: formatModuleName(module),
      value: module,
    })),
  ]
})

const filteredPermissionRows = computed(() => {
  const search = keyword.value
    .trim()
    .toLowerCase()

  return permissionRows.value.filter(row => {
    const matchModule
      = selectedModule.value === 'all'
        || row.module === selectedModule.value

    const matchKeyword
      = !search
        || row.name.toLowerCase().includes(search)
        || row.code.toLowerCase().includes(search)
        || row.action.toLowerCase().includes(search)
        || row.module.toLowerCase().includes(search)

    return matchModule && matchKeyword
  })
})

const groupedPermissionRows = computed(() => {
  const groupMap = new Map<
    string,
    PermissionFormRow[]
  >()

  filteredPermissionRows.value.forEach(row => {
    if (!groupMap.has(row.module))
      groupMap.set(row.module, [])

    groupMap.get(row.module)?.push(row)
  })

  return Array.from(
    groupMap.entries(),
  ).map(([module, items]) => ({
    module,
    title: formatModuleName(module),
    items,
  }))
})

const totalPermission = computed<number>(() => {
  return permissionRows.value.length
})

const totalCheckedPermission = computed<number>(() => {
  return permissionRows.value.filter(
    item => item.is_checked,
  ).length
})

const totalViewAllPermission = computed<number>(() => {
  return permissionRows.value.filter(
    item =>
      item.is_checked
      && item.scope === 'ALL',
  ).length
})

const hasChanges = computed<boolean>(() => {
  return buildSnapshot()
    !== initialSnapshot.value
})

/*
|--------------------------------------------------------------------------
| Normalizers
|--------------------------------------------------------------------------
*/

const normalizeBoolean = (
  value: unknown,
): boolean => {
  return value === true
    || value === 1
    || value === '1'
    || String(value).toLowerCase() === 'true'
}

const normalizeArrayPayload = (
  payload: any,
): any[] => {
  const rawItems
    = payload?.data?.data
      ?? payload?.data
      ?? payload
      ?? []

  return Array.isArray(rawItems)
    ? rawItems
    : []
}

const normalizeRoleItems = (
  payload: any,
): RoleOption[] => {
  return normalizeArrayPayload(payload)
    .map((item: any) => {
      const id = Number(
        item.id
        ?? item.value
        ?? 0,
      )

      const name = String(
        item.title
        ?? item.label
        ?? item.nama
        ?? item.name
        ?? '-',
      )

      return {
        id,
        nama: item.nama ?? name,
        name: item.name ?? name,
        title: name,
      }
    })
    .filter(item => item.id > 0)
}

const normalizePermissionItems = (
  payload: any,
): PermissionItem[] => {
  return normalizeArrayPayload(payload)
    .map((item: any) => ({
      id: Number(item.id ?? 0),

      module: String(
        item.module ?? '',
      ),

      action: String(
        item.action ?? '',
      ),

      code: String(
        item.code ?? '',
      ),

      name: String(
        item.name
        ?? item.code
        ?? '-',
      ),

      description:
        item.description
        ?? null,

      is_active: normalizeBoolean(
        item.is_active ?? true,
      ),
    }))
    .filter(item => item.id > 0)
}

const normalizeScope = (
  value: unknown,
): PermissionScope => {
  const scope = String(
    value || 'NONE',
  )
    .trim()
    .toUpperCase()

  const allowedScopes: PermissionScope[] = [
    'NONE',
    'OWN_DATA',
    'OWN_DEPARTMENT',
    'OWN_CABANG',
    'ALL',
  ]

  return allowedScopes.includes(
    scope as PermissionScope,
  )
    ? scope as PermissionScope
    : 'NONE'
}

const normalizeRolePermissionItems = (
  payload: any,
): RolePermissionItem[] => {
  return normalizeArrayPayload(payload)
    .map((item: any) => {
      const permissionId = Number(
        item.permission_id
        ?? item.permission?.id
        ?? 0,
      )

      return {
        id: item.id
          ? Number(item.id)
          : undefined,

        role_id: item.role_id
          ? Number(item.role_id)
          : undefined,

        permission_id: permissionId,

        scope: normalizeScope(
          item.scope,
        ),

        is_active: normalizeBoolean(
          item.is_active
          ?? item.is_allowed
          ?? true,
        ),

        permission:
          item.permission,
      }
    })
    .filter(
      item => item.permission_id > 0,
    )
}

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

const formatModuleName = (
  module: string,
): string => {
  if (!module)
    return '-'

  return module
    .split('_')
    .map(
      word =>
        word.charAt(0).toUpperCase()
        + word.slice(1),
    )
    .join(' ')
}

const formatActionName = (
  action: string,
): string => {
  const normalizedAction = String(
    action || '',
  ).toLowerCase()

  const actionLabels: Record<string, string> = {
    view: 'View',
    create: 'Create',
    update: 'Update',
    delete: 'Delete',
    approve: 'Approve',
  }

  if (actionLabels[normalizedAction])
    return actionLabels[normalizedAction]

  return normalizedAction
    ? normalizedAction.charAt(0).toUpperCase()
      + normalizedAction.slice(1)
    : '-'
}

const getActionColor = (
  action: string,
): string => {
  const normalizedAction = String(
    action || '',
  ).toLowerCase()

  const actionColors: Record<string, string> = {
    view: 'info',
    create: 'success',
    update: 'warning',
    delete: 'error',
    approve: 'primary',
  }

  return actionColors[normalizedAction]
    ?? 'secondary'
}

/*
|--------------------------------------------------------------------------
| Permission helpers
|--------------------------------------------------------------------------
*/

const isViewPermission = (
  row: PermissionFormRow,
): boolean => {
  return String(
    row.action || '',
  ).toLowerCase() === 'view'
}

const getDefaultScope = (
  permission: PermissionItem,
): PermissionScope => {
  return String(
    permission.action || '',
  ).toLowerCase() === 'view'
    ? 'OWN_DEPARTMENT'
    : 'NONE'
}

const buildEmptyPermissionRows = (): void => {
  permissionRows.value = permissions.value.map(
    permission => ({
      permission_id:
        permission.id,

      module:
        permission.module,

      action:
        permission.action,

      code:
        permission.code,

      name:
        permission.name,

      description:
        permission.description,

      is_permission_active:
        permission.is_active,

      is_checked: false,

      scope:
        getDefaultScope(permission),
    }),
  )

  initialSnapshot.value = buildSnapshot()
}

const buildRowsFromRolePermissions = (
  rolePermissions: RolePermissionItem[],
): void => {
  const rolePermissionMap = new Map<
    number,
    RolePermissionItem
  >()

  rolePermissions.forEach(item => {
    rolePermissionMap.set(
      Number(item.permission_id),
      item,
    )
  })

  permissionRows.value = permissions.value.map(
    permission => {
      const assignedPermission
        = rolePermissionMap.get(
          Number(permission.id),
        )

      const isChecked = Boolean(
        assignedPermission?.is_active,
      )

      return {
        permission_id:
          permission.id,

        module:
          permission.module,

        action:
          permission.action,

        code:
          permission.code,

        name:
          permission.name,

        description:
          permission.description,

        is_permission_active:
          permission.is_active,

        is_checked:
          isChecked,

        scope:
          isChecked
            ? normalizeScope(
                assignedPermission?.scope,
              )
            : getDefaultScope(permission),
      }
    },
  )

  initialSnapshot.value = buildSnapshot()
}

/*
|--------------------------------------------------------------------------
| Snapshot and payload
|--------------------------------------------------------------------------
*/

const buildSnapshot = (): string => {
  const payload = permissionRows.value
    .map(row => ({
      permission_id:
        row.permission_id,

      is_checked:
        row.is_checked,

      scope:
        row.scope,
    }))
    .sort(
      (a, b) =>
        a.permission_id
        - b.permission_id,
    )

  return JSON.stringify(payload)
}

const buildPayload = (): BulkRolePermissionPayload => {
  return {
    role_ids: selectedRoleIds.value,

    permissions: permissionRows.value.map(item => ({
      permission_id: item.permission_id,
      is_active: Boolean(item.is_checked),
      is_allowed: Boolean(item.is_checked),

      scope: item.action === 'view'
        ? (
            item.is_checked
              ? item.scope
              : 'NONE'
          )
        : null,
    })),
  }
}

/*
|--------------------------------------------------------------------------
| Fetch roles
|--------------------------------------------------------------------------
*/

const fetchRoles = async (): Promise<void> => {
  isLoadingRole.value = true

  try {
    const response = await axios.get(
      '/master/roles',
      {
        params: {
          per_page: 9999,
        },
        headers: {
          Accept: 'application/json',
        },
      },
    )

    roleOptions.value = normalizeRoleItems(
      response.data,
    )

    /*
    |--------------------------------------------------------------------------
    | Jangan otomatis memilih role pertama
    |--------------------------------------------------------------------------
    | Untuk bulk permission, user memilih role dengan sadar.
    |--------------------------------------------------------------------------
    */
    selectedRoleIds.value = selectedRoleIds.value
      .filter(selectedId =>
        roleOptions.value.some(
          role =>
            Number(role.id)
            === Number(selectedId),
        ),
      )
  }
  catch (error: unknown) {
    roleOptions.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Role',
      text: getApiErrorMessage(
        err,
        'Gagal memuat data role.',
      ),
    })
  }
  finally {
    isLoadingRole.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Fetch permissions
|--------------------------------------------------------------------------
*/

const fetchPermissions = async (): Promise<void> => {
  isLoadingPermission.value = true

  try {
    const response = await axios.get(
      '/master/permissions',
      {
        params: {
          per_page: 9999,
        },
        headers: {
          Accept: 'application/json',
        },
      },
    )

    permissions.value
      = normalizePermissionItems(
        response.data,
      )
  }
  catch (error: unknown) {
    permissions.value = []
    permissionRows.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Permission',
      text: getApiErrorMessage(
        err,
        'Gagal memuat data permission.',
      ),
    })
  }
  finally {
    isLoadingPermission.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Fetch role permissions
|--------------------------------------------------------------------------
*/

const fetchRolePermissions = async (): Promise<void> => {
  const roleId = primarySelectedRoleId.value

  if (!roleId) {
    buildEmptyPermissionRows()

    return
  }

  isLoadingRolePermission.value = true

  try {
    const response = await axios.get(
      '/master/role-permissions',
      {
        params: {
          /*
          |--------------------------------------------------------------------------
          | Role pertama dijadikan template
          |--------------------------------------------------------------------------
          */
          role_id: roleId,
        },
        headers: {
          Accept: 'application/json',
        },
      },
    )

    const rolePermissions
      = normalizeRolePermissionItems(
        response.data,
      )

    buildRowsFromRolePermissions(
      rolePermissions,
    )
  }
  catch (error: unknown) {
    buildEmptyPermissionRows()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Role Permission',
      text: getApiErrorMessage(
        err,
        'Gagal memuat setting permission role.',
      ),
    })
  }
  finally {
    isLoadingRolePermission.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Reload
|--------------------------------------------------------------------------
*/

const reloadData = async (): Promise<void> => {
  isLoading.value = true

  try {
    await Promise.all([
      fetchPermissions(),
      fetchRoles(),
    ])

    await fetchRolePermissions()
  }
  finally {
    isLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

const resetFilter = (): void => {
  keyword.value = ''
  selectedModule.value = 'all'
}

/*
|--------------------------------------------------------------------------
| Permission interactions
|--------------------------------------------------------------------------
*/

const onTogglePermission = (
  row: PermissionFormRow,
): void => {
  if (!row.is_checked) {
    row.scope = 'NONE'

    return
  }

  if (
    isViewPermission(row)
    && row.scope === 'NONE'
  ) {
    row.scope = 'OWN_DEPARTMENT'
  }

  if (!isViewPermission(row))
    row.scope = 'NONE'
}

const onScopeChange = (
  row: PermissionFormRow,
): void => {
  if (row.scope !== 'NONE')
    row.is_checked = true
}

const checkAllVisible = (): void => {
  filteredPermissionRows.value.forEach(row => {
    row.is_checked = true

    if (
      isViewPermission(row)
      && row.scope === 'NONE'
    ) {
      row.scope = 'OWN_DEPARTMENT'
    }

    if (!isViewPermission(row))
      row.scope = 'NONE'
  })
}

const uncheckAllVisible = (): void => {
  filteredPermissionRows.value.forEach(row => {
    row.is_checked = false
    row.scope = 'NONE'
  })
}

/*
|--------------------------------------------------------------------------
| Reset changes
|--------------------------------------------------------------------------
*/

const resetToInitial = async (): Promise<void> => {
  if (!hasSelectedRole.value)
    return

  const confirm = await showConfirmAlert({
    title: 'Reset Perubahan?',
    text: 'Perubahan yang belum disimpan akan dikembalikan ke konfigurasi role pertama.',
    confirmButtonText: 'Ya, reset',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  await fetchRolePermissions()
}

/*
|--------------------------------------------------------------------------
| Submit permissions
|--------------------------------------------------------------------------
*/

const submitPermission = async (): Promise<void> => {
  if (isSubmitting.value)
    return

  if (!hasSelectedRole.value) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Pilih minimal satu role terlebih dahulu.',
    })

    return
  }

  const roleCount = selectedRoleIds.value.length

  const confirmText = roleCount > 1
    ? `Konfigurasi permission ini akan diterapkan sama ke ${roleCount} role: ${selectedRoleNames.value}.`
    : primarySelectedRole.value
      ? `Setting permission untuk role "${primarySelectedRole.value.title}" akan disimpan.`
      : 'Setting permission role akan disimpan.'

  const confirm = await showConfirmAlert({
    title: roleCount > 1
      ? 'Terapkan ke Beberapa Role?'
      : 'Simpan Permission?',
    text: confirmText,
    confirmButtonText: roleCount > 1
      ? 'Ya, terapkan'
      : 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isSubmitting.value = true

  try {
    showLoadingAlert(
      'Menyimpan Permission',
      `Menerapkan permission ke ${roleCount} role. Mohon tunggu sebentar.`,
    )

    await axios.put(
      '/master/role-permissions/bulk',
      buildPayload(),
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    initialSnapshot.value = buildSnapshot()

    showSuccessToast({
      title: 'Berhasil',
      text: roleCount > 1
        ? `Permission berhasil diterapkan ke ${roleCount} role.`
        : 'Permission role berhasil disimpan.',
    })
  }
  catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal menyimpan permission role.',
      ),
    })
  }
  finally {
    isSubmitting.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Watch selected roles
|--------------------------------------------------------------------------
|
| Hanya reload permission jika role pertama berubah.
| Menambah role kedua/ketiga tidak mengubah template permission.
|--------------------------------------------------------------------------
*/

watch(
  primarySelectedRoleId,
  async (newRoleId, oldRoleId) => {
    if (newRoleId === oldRoleId)
      return

    await fetchRolePermissions()
  },
)

/*
|--------------------------------------------------------------------------
| Mounted
|--------------------------------------------------------------------------
*/

onMounted(async () => {
  isLoading.value = true

  try {
    await Promise.all([
      fetchPermissions(),
      fetchRoles(),
    ])

    /*
    |--------------------------------------------------------------------------
    | Belum memilih role: tampilkan semua permission dalam kondisi kosong
    |--------------------------------------------------------------------------
    */
    buildEmptyPermissionRows()
  }
  finally {
    isLoading.value = false
  }
})
</script>
<template>
  <section>
    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row justify-space-between gap-4">
          <div>
            <div class="text-overline text-primary font-weight-bold mb-1 text-none">
              Auth Permission
            </div>

            <h2 class="text-h5 font-weight-bold mb-1">
              Role Permission Setting
            </h2>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Atur hak akses role berdasarkan module, action, dan scope data.
            </p>
          </div>

          <div class="d-flex align-center gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-refresh"
              :loading="isLoading"
              class="text-none"
              @click="reloadData"
            >
              Refresh
            </VBtn>

            <!-- <VBtn
              color="primary"
              prepend-icon="tabler-device-floppy"
              :loading="isSubmitting"
              :disabled="!hasSelectedRole || !hasChanges"
              class="text-none"
              @click="submitPermission"
            >
              Simpan Permission
            </VBtn> -->
          </div>
        </div>
      </VCardText>
    </VCard>

    <VRow class="mb-6">
      <VCol cols="12" md="4">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Total Permission
                </div>

                <div class="text-h5 font-weight-bold">
                  {{ totalPermission }}
                </div>
              </div>

              <VAvatar color="primary" variant="tonal" rounded>
                <VIcon icon="tabler-shield-lock" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Permission Aktif
                </div>

                <div class="text-h5 font-weight-bold text-success">
                  {{ totalCheckedPermission }}
                </div>
              </div>

              <VAvatar color="success" variant="tonal" rounded>
                <VIcon icon="tabler-circle-check" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Scope All Data
                </div>

                <div class="text-h5 font-weight-bold text-info">
                  {{ totalViewAllPermission }}
                </div>
              </div>

              <VAvatar color="info" variant="tonal" rounded>
                <VIcon icon="tabler-database-search" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-6 rounded-lg permission-filter-card">
      <VCardText>
        <VRow class="align-center">
        <VCol cols="12" md="6" lg="3">
            <VTextField
              v-model="keyword"
              label="Cari Permission"
              placeholder="Cari nama, code, module..."
              prepend-inner-icon="tabler-search"
              clearable
              density="comfortable"
              hide-details
            />
          </VCol>
          <VCol cols="12" md="6" lg="3">
            <MultiAutocomplete
              v-model="selectedRoleIds"
              :items="roleOptions"
              item-title="title"
              item-value="id"
              label="Role"
              placeholder="Pilih satu atau beberapa role"
              :multiple="true"
              chips
              closable-chips
              clearable
              density="comfortable"
              :loading="isLoadingRole"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 350,
              }"
            >
              <template #selection="{ item, index }">
                <VChip
                  v-if="index < 2"
                  size="small"
                  color="primary"
                  variant="tonal"
                  closable
                  class="me-1"
                  @click:close.stop="removeSelectedRole(Number(item.raw.id))"
                >
                  {{ item.raw.title }}
                </VChip>

                <span
                  v-else-if="index === 2"
                  class="text-caption text-medium-emphasis ms-1"
                >
                  +{{ selectedRoleIds.length - 2 }} role lainnya
                </span>
              </template>
            </MultiAutocomplete>
          </VCol>

          <VCol cols="12" md="6" lg="3">
            <VSelect
              v-model="selectedModule"
              label="Module"
              :items="moduleOptions"
              item-title="title"
              item-value="value"
              :return-object="false"
              density="comfortable"
              hide-details
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
            />
          </VCol>

          <VCol cols="12" md="6" lg="3" class="d-flex gap-2">
            <VBtn
              block
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-filter-off"
              class="text-none permission-filter-btn"
              @click="resetFilter"
            >
              Reset Filter
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard class="rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row justify-space-between align-md-center gap-3 mb-5">
          <div>
            <h3 class="text-h6 font-weight-bold mb-1">
              Daftar Permission
            </h3>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Centang permission yang diberikan ke role, lalu atur scope khusus untuk permission view.
            </p>
          </div>

          <div class="d-flex flex-wrap align-center ga-2">
            <VChip
              v-if="selectedRoleIds.length === 1"
              color="primary"
              variant="tonal"
            >
              Role: {{ primarySelectedRole?.title || '-' }}
            </VChip>

            <VChip
              v-else-if="selectedRoleIds.length > 1"
              color="primary"
              variant="tonal"
            >
              {{ selectedRoleIds.length }} Role Dipilih
            </VChip>

            <VChip
              v-else
              color="secondary"
              variant="tonal"
            >
              Belum Ada Role Dipilih
            </VChip>

            <VChip
              :color="hasChanges ? 'warning' : 'secondary'"
              variant="tonal"
            >
              {{ hasChanges ? 'Ada Perubahan' : 'Tidak Ada Perubahan' }}
            </VChip>
            <VAlert
              v-if="selectedRoleIds.length > 1"
              type="warning"
              variant="tonal"
              density="comfortable"
              class="mt-4"
            >
              Permission yang tampil menggunakan konfigurasi role pertama,
              <strong>{{ primarySelectedRole?.title || '-' }}</strong>.

              Saat disimpan, konfigurasi yang sama akan diterapkan ke:

              <strong>
                {{ selectedRoles.map(role => role.title).join(', ') }}
              </strong>.
            </VAlert>
          </div>
        </div>

        <VAlert
          v-if="!hasSelectedRole"
          color="warning"
          variant="tonal"
          class="mb-5"
        >
          Pilih role terlebih dahulu untuk mengatur permission.
        </VAlert>

        <div
          v-if="isLoading || isLoadingPermission || isLoadingRolePermission"
          class="py-4"
        >
          <VSkeletonLoader
            v-for="n in 5"
            :key="n"
            type="list-item-two-line"
            class="mb-3"
          />
        </div>

        <div
          v-else-if="hasSelectedRole && !permissionRows.length"
          class="py-10 text-center"
        >
          <VAvatar color="secondary" variant="tonal" size="64" class="mb-4">
            <VIcon icon="tabler-shield-off" size="34" />
          </VAvatar>

          <div class="text-h6 font-weight-semibold mb-1">
            Permission belum tersedia
          </div>

          <div class="text-body-2 text-medium-emphasis mb-5">
            Silakan buat data permission terlebih dahulu.
          </div>

          <VBtn
            color="primary"
            variant="tonal"
            prepend-icon="tabler-refresh"
            class="text-none"
            @click="reloadData"
          >
            Muat Ulang
          </VBtn>
        </div>

        <template v-else-if="hasSelectedRole">
          <div class="d-flex flex-column flex-md-row justify-space-between gap-3 mb-4">
            <div class="d-flex flex-wrap gap-2">
              <VBtn
                variant="tonal"
                color="success"
                prepend-icon="tabler-checks"
                class="text-none"
                @click="checkAllVisible"
              >
                Check Visible
              </VBtn>

              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-square"
                class="text-none"
                @click="uncheckAllVisible"
              >
                Uncheck Visible
              </VBtn>
            </div>

            <div class="d-flex flex-wrap gap-2">
              <VBtn
                variant="tonal"
                color="warning"
                prepend-icon="tabler-rotate-clockwise"
                :disabled="!hasChanges"
                class="text-none"
                @click="resetToInitial"
              >
                Reset Perubahan
              </VBtn>

              <VBtn
                color="primary"
                prepend-icon="tabler-device-floppy"
                :loading="isSubmitting"
                :disabled="!hasChanges"
                class="text-none"
                @click="submitPermission"
              >
                Simpan
              </VBtn>
            </div>
          </div>

          <div
            v-if="!groupedPermissionRows.length"
            class="py-10 text-center"
          >
            <VAvatar color="secondary" variant="tonal" size="64" class="mb-4">
              <VIcon icon="tabler-search-off" size="34" />
            </VAvatar>

            <div class="text-h6 font-weight-semibold mb-1">
              Permission tidak ditemukan
            </div>

            <div class="text-body-2 text-medium-emphasis">
              Coba ubah keyword atau filter module.
            </div>
          </div>

          <div
            v-for="group in groupedPermissionRows"
            :key="group.module"
            class="permission-module-group mb-5"
          >
            <div class="d-flex align-center justify-space-between gap-3 mb-3">
              <div class="d-flex align-center gap-2">
                <VAvatar color="primary" variant="tonal" size="32" rounded>
                  <VIcon icon="tabler-settings-check" size="18" />
                </VAvatar>

                <div>
                  <div class="font-weight-bold">
                    {{ group.title }}
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    {{ group.items.length }} permission
                  </div>
                </div>
              </div>
            </div>

            <div class="permission-table-wrapper">
              <VTable class="permission-table">
                <thead>
                  <tr>
                    <th style="width: 80px;" class="text-center text-none">
                      Aktif
                    </th>
                    <th class="text-none">Permission</th>
                    <th style="width: 130px;" class="text-none">
                      Action
                    </th>
                    <th style="width: 230px;" class="text-none">
                      Scope View
                    </th>
                    <th style="width: 160px;" class="text-none">
                      Status
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="row in group.items"
                    :key="row.permission_id"
                  >
                    <td class="text-center">
                      <VCheckbox
                        v-model="row.is_checked"
                        color="primary"
                        hide-details
                        density="compact"
                        :disabled="!row.is_permission_active"
                        @update:model-value="onTogglePermission(row)"
                      />
                    </td>

                    <td>
                      <div class="permission-name">
                        {{ row.name }}
                      </div>

                      <div
                        v-if="row.description"
                        class="permission-description text-caption text-medium-emphasis mt-1"
                      >
                        {{ row.description }}
                      </div>
                    </td>

                    <td>
                      <VChip
                        :color="getActionColor(row.action)"
                        size="small"
                        variant="tonal"
                      >
                        {{ formatActionName(row.action) }}
                      </VChip>
                    </td>

                    <td>
                      <VSelect
                        v-if="isViewPermission(row)"
                        v-model="row.scope"
                        :items="scopeOptions"
                        item-title="title"
                        item-value="value"
                        :return-object="false"
                        density="compact"
                        hide-details
                        :disabled="!row.is_checked || !row.is_permission_active"
                        :menu-props="{
                          location: 'bottom',
                          offset: 8,
                          maxHeight: 250,
                        }"
                        @update:model-value="onScopeChange(row)"
                      />

                      <VChip
                        v-else
                        color="secondary"
                        size="small"
                        variant="tonal"
                      >
                        No Scope
                      </VChip>
                    </td>

                    <td>
                      <VChip
                        :color="row.is_permission_active ? 'success' : 'secondary'"
                        size="small"
                        variant="tonal"
                      >
                        {{ row.is_permission_active ? 'Aktif' : 'Nonaktif' }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </div>
        </template>
      </VCardText>
    </VCard>
  </section>
</template>
<style scoped>
.permission-filter-card :deep(.v-card-text) {
  padding: 20px;
}

.permission-filter-btn {
  min-height: 48px;
}

.permission-module-group {
  padding: 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 16px;
  background: rgba(var(--v-theme-surface), 0.8);
}

.permission-table-wrapper {
  overflow-x: auto;
  overflow-y: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
}

.permission-table-wrapper :deep(.v-table__wrapper) {
  overflow-x: visible !important;
  overflow-y: visible !important;
}

.permission-table {
  min-width: 980px;
}

.permission-table :deep(table) {
  inline-size: 100%;
  min-width: 980px;
}

.permission-table th {
  color: rgba(var(--v-theme-on-surface), 0.72);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  text-transform: uppercase;
  background: rgba(var(--v-theme-background), 0.55);
}

.permission-table td {
  padding-block: 14px;
  vertical-align: middle;
}

.permission-name {
  font-weight: 700;
  line-height: 1.3;
}

.permission-code {
  font-family: monospace;
  word-break: break-word;
}

.permission-description {
  max-width: 520px;
  line-height: 1.4;
}

@media (max-width: 960px) {
  .permission-filter-card :deep(.v-card-text) {
    padding: 16px;
  }

  .permission-module-group {
    padding: 12px;
  }

  .permission-table,
  .permission-table :deep(table) {
    min-width: 900px;
  }
}
</style>