<script setup lang="ts">
import axios from '@axios'
import { computed, onMounted, ref, watch } from 'vue'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'

interface AxiosErrorShape {
  response?: {
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface RoleOption {
  id: number
  nama: string
  name?: string
  title?: string
}

interface CabangOption {
  id: number
  value?: number
  nama: string
  name?: string
  title?: string
}

interface DepartmentOption {
  id: number
  value?: number
  nama: string
  name?: string
  title?: string
  kode?: string | null
}

interface UserRole {
  id: number
  nama: string
  name?: string
}

interface UserCabang {
  id: number
  nama?: string
  nama_cabang?: string
  inisial_cabang?: string | null
}

interface UserDepartment {
  id: number
  nama?: string
  name?: string
  kode?: string | null
}

interface UserRow {
  id: number
  name: string
  username?: string | null
  email: string

  cabang_id?: number | null
  cabang?: UserCabang | null

  departemen_id?: number | null
  departemen?: UserDepartment | null

  roles?: UserRole[]
  role_id?: number | null
  role_ids?: number[]
  role_names?: string[]

  is_active: boolean

  signature_path?: string | null
  signature_uploaded_at?: string | null

  last_login_at?: string | null
  last_logout_at?: string | null

  created_at?: string | null
  updated_at?: string | null
}

interface UserForm {
  id?: number
  name: string
  username: string
  email: string
  is_active: boolean
  cabang_id: number | null
  departemen_id: number | null
  role_id: number | null
  password: string
  password_confirmation: string
}

const isLoading = ref(false)
const isActionLoading = ref(false)
const isDialogOpen = ref(false)
const isEdit = ref(false)
const isSubmitting = ref(false)
const isSubmitted = ref(false)

const keyword = ref('')
const selectedStatus = ref<'all' | 'active' | 'inactive'>('all')
const selectedRoleId = ref<number | null>(null)

const page = ref(1)
const perPage = ref(10)
const totalItems = ref(0)
const lastPage = ref(1)

const users = ref<UserRow[]>([])
const roleOptions = ref<RoleOption[]>([])
const cabangOptions = ref<CabangOption[]>([])
const departmentOptions = ref<DepartmentOption[]>([])

const formErrors = ref<Record<string, string>>({})

const form = ref<UserForm>({
  name: '',
  username: '',
  email: '',
  is_active: true,
  cabang_id: null,
  departemen_id: null,
  role_id: null,
  password: '',
  password_confirmation: '',
})

const statusOptions = [
  {
    title: 'Aktif',
    value: 'active',
  },
  {
    title: 'Nonaktif',
    value: 'inactive',
  },
  {
    title: 'Semua',
    value: 'all',
  },
]

const totalActiveUser = computed(() => {
  return users.value.filter(item => item.is_active).length
})

const totalInactiveUser = computed(() => {
  return users.value.filter(item => !item.is_active).length
})

const hasFilter = computed(() => {
  return Boolean(keyword.value || selectedRoleId.value || selectedStatus.value !== 'active')
})

const paginationText = computed(() => {
  const firstIndex = totalItems.value ? ((page.value - 1) * perPage.value) + 1 : 0
  const lastIndex = users.value.length + ((page.value - 1) * perPage.value)

  return `${firstIndex}-${lastIndex} dari ${totalItems.value}`
})

const normalizeDropdownItems = (payload: any): any[] => {
  const rawItems = payload?.data?.data
    ?? payload?.data
    ?? payload
    ?? []

  if (!Array.isArray(rawItems))
    return []

  return rawItems.map((item: any) => {
    const id = Number(item.id ?? item.value)
    const kode = item.kode ?? item.code ?? null

    const name = String(
      item.title
        ?? item.label
        ?? item.nama
        ?? item.nama_cabang
        ?? item.name
        ?? '-',
    )

    let title = name

    if (kode && !String(title).includes(String(kode)))
      title = `${kode} - ${name}`

    return {
      id,
      value: id,
      nama: name,
      name,
      title,
      kode,
    }
  })
}

const fetchOptions = async (): Promise<void> => {
  try {
    const [roleResponse, cabangResponse, departmentResponse] = await Promise.all([
      axios.get('/master/roles', {
        params: { per_page: 9999 },
        headers: { Accept: 'application/json' },
      }),
      axios.get('/master/cabang/dropdown-select', {
        headers: { Accept: 'application/json' },
      }),
      axios.get('/master/department/dropdown-select', {
        headers: { Accept: 'application/json' },
      }),
    ])

    roleOptions.value = normalizeDropdownItems(roleResponse.data) as RoleOption[]
    cabangOptions.value = normalizeDropdownItems(cabangResponse.data) as CabangOption[]
    departmentOptions.value = normalizeDropdownItems(departmentResponse.data) as DepartmentOption[]
  } catch (error: any) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Dropdown',
      text: getApiErrorMessage(err, 'Gagal memuat data role, cabang, atau department.'),
    })
  }
}

const buildParams = (): Record<string, any> => {
  const params: Record<string, any> = {
    page: page.value,
    per_page: perPage.value,
  }

  if (keyword.value)
    params.search = keyword.value

  if (selectedRoleId.value)
    params.role_id = selectedRoleId.value

  if (selectedStatus.value !== 'all') {
    params.is_active = selectedStatus.value === 'active'
      ? 'true'
      : 'false'
  }

  return params
}

const assignResponseData = (responseData: any): void => {
  const paginator = responseData?.data?.data
    ? responseData.data
    : responseData?.data
      ? responseData
      : responseData

  if (Array.isArray(paginator)) {
    users.value = paginator
    totalItems.value = paginator.length
    lastPage.value = 1

    return
  }

  if (Array.isArray(paginator?.data)) {
    users.value = paginator.data
    page.value = Number(paginator.current_page ?? page.value)
    perPage.value = Number(paginator.per_page ?? perPage.value)
    totalItems.value = Number(paginator.total ?? paginator.data.length)
    lastPage.value = Number(paginator.last_page ?? 1)

    return
  }

  users.value = []
  totalItems.value = 0
  lastPage.value = 1
}

const fetchUsers = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get('/master/users', {
      params: buildParams(),
      headers: {
        Accept: 'application/json',
      },
    })

    assignResponseData(response.data)
  } catch (error: any) {
    const err = error as AxiosErrorShape

    users.value = []
    totalItems.value = 0
    lastPage.value = 1

    showErrorToast({
      title: 'Gagal Memuat Data',
      text: getApiErrorMessage(err, 'Gagal memuat data user.'),
    })
  } finally {
    isLoading.value = false
  }
}

const reloadData = async (): Promise<void> => {
  page.value = 1
  await fetchUsers()
}

const resetFilter = async (): Promise<void> => {
  keyword.value = ''
  selectedStatus.value = 'active'
  selectedRoleId.value = null
  page.value = 1

  await fetchUsers()
}

const getCabangText = (user: UserRow): string => {
  return user.cabang?.nama
    || user.cabang?.nama_cabang
    || user.cabang?.inisial_cabang
    || '-'
}

const getDepartmentText = (user: UserRow): string => {
  const kode = user.departemen?.kode || ''
  const nama = user.departemen?.nama || user.departemen?.name || ''

  if (kode && nama)
    return `${kode} - ${nama}`

  return nama || kode || '-'
}

const getStatusColor = (user: UserRow): string => {
  return user.is_active ? 'success' : 'secondary'
}

const getStatusText = (user: UserRow): string => {
  return user.is_active ? 'Aktif' : 'Nonaktif'
}

const getPrimaryRoleId = (user: UserRow): number | null => {
  if (user.role_id)
    return Number(user.role_id)

  if (Array.isArray(user.role_ids) && user.role_ids.length)
    return Number(user.role_ids[0])

  if (Array.isArray(user.roles) && user.roles.length)
    return Number(user.roles[0].id)

  return null
}

const getUserRoleIdsPayload = (): number[] => {
  return form.value.role_id ? [Number(form.value.role_id)] : []
}

const resetForm = (): void => {
  isSubmitted.value = false
  formErrors.value = {}

  form.value = {
    name: '',
    username: '',
    email: '',
    is_active: true,
    cabang_id: null,
    departemen_id: null,
    role_id: null,
    password: '',
    password_confirmation: '',
  }
}

const openCreate = (): void => {
  resetForm()
  isEdit.value = false
  isDialogOpen.value = true
}

const openEdit = (user: UserRow): void => {
  isSubmitted.value = false
  formErrors.value = {}

  form.value = {
    id: user.id,
    name: user.name || '',
    username: user.username || '',
    email: user.email || '',
    is_active: Boolean(user.is_active),
    cabang_id: user.cabang?.id ?? user.cabang_id ?? null,
    departemen_id: user.departemen?.id ?? user.departemen_id ?? null,
    role_id: getPrimaryRoleId(user),
    password: '',
    password_confirmation: '',
  }

  isEdit.value = true
  isDialogOpen.value = true
}

const closeDialog = (): void => {
  if (isSubmitting.value)
    return

  isDialogOpen.value = false
  resetForm()
}

const validateForm = (): boolean => {
  isSubmitted.value = true
  formErrors.value = {}

  if (!form.value.name.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Nama user wajib diisi.',
    })

    return false
  }

  if (!form.value.email.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Email wajib diisi.',
    })

    return false
  }

  if (!form.value.role_id) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Role user wajib dipilih.',
    })

    return false
  }

  if (!isEdit.value && !form.value.password) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Password wajib diisi untuk user baru.',
    })

    return false
  }

  if (form.value.password && form.value.password !== form.value.password_confirmation) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Konfirmasi password tidak sesuai.',
    })

    return false
  }

  return true
}

const buildPayload = (): Record<string, any> => {
  const payload: Record<string, any> = {
    name: form.value.name.trim(),
    username: form.value.username?.trim() || null,
    email: form.value.email.trim(),
    is_active: Boolean(form.value.is_active),
    cabang_id: form.value.cabang_id,
    departemen_id: form.value.departemen_id,

    /*
    |--------------------------------------------------------------------------
    | Backend existing masih menerima role_ids.
    | UI sekarang hanya single role, jadi dikirim sebagai array 1 item.
    |--------------------------------------------------------------------------
    */
    role_ids: getUserRoleIdsPayload(),
  }

  if (!isEdit.value || form.value.password) {
    payload.password = form.value.password
    payload.password_confirmation = form.value.password_confirmation
  }

  return payload
}

const submitForm = async (): Promise<void> => {
  if (isSubmitting.value)
    return

  if (!validateForm())
    return

  /*
  |--------------------------------------------------------------------------
  | Tutup dialog form dulu agar tidak menimpa SweetAlert confirm
  |--------------------------------------------------------------------------
  */
  isDialogOpen.value = false

  const confirm = await showConfirmAlert({
    title: isEdit.value ? 'Simpan Perubahan?' : 'Tambah User?',
    text: isEdit.value
      ? 'Data user akan diperbarui.'
      : 'User baru akan ditambahkan.',
    confirmButtonText: isEdit.value ? 'Ya, simpan' : 'Ya, tambah',
    cancelButtonText: 'Batal',
  })

  /*
  |--------------------------------------------------------------------------
  | Kalau user batal, buka lagi dialog form dengan data yang sama
  |--------------------------------------------------------------------------
  */
  if (!confirm.isConfirmed) {
    isDialogOpen.value = true
    return
  }

  isSubmitting.value = true

  try {
    showLoadingAlert('Menyimpan User', 'Mohon tunggu sebentar')

    if (isEdit.value && form.value.id) {
      await axios.put(`/master/users/${form.value.id}`, buildPayload(), {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
    } else {
      await axios.post('/master/users', buildPayload(), {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: isEdit.value
        ? 'User berhasil diperbarui.'
        : 'User berhasil ditambahkan.',
    })

    isDialogOpen.value = false
    resetForm()
    await fetchUsers()
  } catch (error: any) {
    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Kalau gagal simpan, buka lagi dialog supaya user bisa koreksi
    |--------------------------------------------------------------------------
    */
    isDialogOpen.value = true

    const err = error as AxiosErrorShape

    if (error?.response?.status === 422 && error?.response?.data?.errors) {
      const errors = error.response.data.errors as Record<string, string[]>

      Object.keys(errors).forEach(key => {
        formErrors.value[key] = errors[key][0]
      })

      showErrorToast({
        title: 'Validasi Gagal',
        text: 'Silakan cek kembali input user.',
      })

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menyimpan user.'),
    })
  } finally {
    isSubmitting.value = false
  }
}

const buildToggleActivePayload = (user: UserRow, isActive: boolean): Record<string, any> => {
  const roleId = getPrimaryRoleId(user)

  return {
    name: user.name,
    username: user.username || null,
    email: user.email,
    is_active: isActive,
    cabang_id: user.cabang?.id ?? user.cabang_id ?? null,
    departemen_id: user.departemen?.id ?? user.departemen_id ?? null,
    role_ids: roleId ? [roleId] : [],
  }
}

const toggleUserActive = async (user: UserRow): Promise<void> => {
  if (isActionLoading.value)
    return

  const nextStatus = !user.is_active

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Aktifkan User?' : 'Nonaktifkan User?',
    text: nextStatus
      ? `User "${user.name}" akan diaktifkan kembali.`
      : `User "${user.name}" akan dinonaktifkan dan tidak dapat digunakan untuk approval.`,
    confirmButtonText: nextStatus ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert(
      nextStatus ? 'Mengaktifkan User' : 'Menonaktifkan User',
      'Mohon tunggu sebentar',
    )

    await axios.put(`/master/users/${user.id}`, buildToggleActivePayload(user, nextStatus), {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'User berhasil diaktifkan.'
        : 'User berhasil dinonaktifkan.',
    })

    await fetchUsers()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        nextStatus
          ? 'Gagal mengaktifkan user.'
          : 'Gagal menonaktifkan user.',
      ),
    })
  } finally {
    isActionLoading.value = false
  }
}

const goToPreviousPage = async (): Promise<void> => {
  if (page.value <= 1)
    return

  page.value -= 1
  await fetchUsers()
}

const goToNextPage = async (): Promise<void> => {
  if (page.value >= lastPage.value)
    return

  page.value += 1
  await fetchUsers()
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(keyword, () => {
  if (searchTimeout)
    clearTimeout(searchTimeout)

  searchTimeout = setTimeout(async () => {
    page.value = 1
    await fetchUsers()
  }, 500)
})

watch([selectedRoleId, selectedStatus], async () => {
  page.value = 1
  await fetchUsers()
})

onMounted(async () => {
  await Promise.all([
    fetchOptions(),
    fetchUsers(),
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
              Master User
            </div>

            <h2 class="text-h5 font-weight-bold mb-1">
              Kelola Akun User
            </h2>
          </div>

          <div class="d-flex align-center gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-refresh"
              :loading="isLoading"
              class="text-none"
              @click="fetchUsers"
            >
              Refresh
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="tabler-user-plus"
              class="text-none"
              @click="openCreate"
            >
              Tambah User
            </VBtn>
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
                  Total User
                </div>
                <div class="text-h5 font-weight-bold">
                  {{ totalItems }}
                </div>
              </div>

              <VAvatar color="primary" variant="tonal" rounded>
                <VIcon icon="tabler-users" />
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
                  User Aktif
                </div>
                <div class="text-h5 font-weight-bold text-success">
                  {{ totalActiveUser }}
                </div>
              </div>

              <VAvatar color="success" variant="tonal" rounded>
                <VIcon icon="tabler-user-check" />
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
                  User Nonaktif
                </div>
                <div class="text-h5 font-weight-bold text-secondary">
                  {{ totalInactiveUser }}
                </div>
              </div>

              <VAvatar color="secondary" variant="tonal" rounded>
                <VIcon icon="tabler-user-off" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="6" lg="3">
            <VTextField
              v-model="keyword"
              label="Cari user"
              placeholder="Cari nama, username, atau email..."
              prepend-inner-icon="tabler-search"
              clearable
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="5">
            <VAutocomplete
              v-model="selectedRoleId"
              label="Role"
              :items="roleOptions"
              item-title="title"
              item-value="id"
              :return-object="false"
              clearable
              density="comfortable"
              no-data-text="Role tidak ditemukan"
              placeholder="Pilih role"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
                eager: true,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              :return-object="false"
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 250,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2" class="d-flex align-center">
            <VBtn
              block
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-filter-off"
              :disabled="!hasFilter"
              class="text-none"
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
              Daftar User
            </h3>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Setiap user memiliki satu role utama untuk kebutuhan approval.
            </p>
          </div>

          <VChip color="primary" variant="tonal">
            {{ totalItems }} User
          </VChip>
        </div>

        <div v-if="isLoading" class="py-4">
          <VSkeletonLoader
            v-for="n in 4"
            :key="n"
            type="list-item-avatar-two-line"
            class="mb-3"
          />
        </div>

        <div v-else-if="!users.length" class="py-10 text-center">
          <VAvatar color="secondary" variant="tonal" size="64" class="mb-4">
            <VIcon icon="tabler-user-off" size="34" />
          </VAvatar>

          <div class="text-h6 font-weight-semibold mb-1">
            User belum tersedia
          </div>

          <div class="text-body-2 text-medium-emphasis mb-5">
            Silakan tambahkan user untuk kebutuhan login dan approval.
          </div>

          <VBtn
            color="primary"
            prepend-icon="tabler-user-plus"
            class="text-none"
            @click="openCreate"
          >
            Tambah User
          </VBtn>
        </div>

        <div
          v-else
          class="user-table-wrapper"
        >
          <VTable class="user-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Cabang</th>
                <th>Department</th>
                <th>Status</th>
                <th>Last Login</th>
                <th class="text-center" style="width: 140px;">
                  Actions
                </th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="user in users" :key="user.id">
                <td>
                  <div class="user-cell">
                    <VAvatar color="primary" variant="tonal" size="40" class="user-avatar">
                      <VIcon icon="tabler-user" />
                    </VAvatar>

                    <div class="user-info">
                      <div class="font-weight-bold user-name">
                        {{ user.name }}
                      </div>

                      <div class="text-caption text-medium-emphasis user-email">
                        {{ user.email }}
                      </div>

                      <div v-if="user.username" class="text-caption text-medium-emphasis user-username">
                        Username: {{ user.username }}
                      </div>
                    </div>
                  </div>
                </td>

                <td>
                  <div class="role-chip-list">
                    <VChip
                      v-for="role in user.role_names || []"
                      :key="role"
                      size="x-small"
                      color="primary"
                      variant="tonal"
                      class="role-chip"
                    >
                      {{ role }}
                    </VChip>

                    <span v-if="!user.role_names?.length" class="text-medium-emphasis">
                      -
                    </span>
                  </div>
                </td>

                <td class="text-medium-emphasis">
                  <div class="table-text-wrap">
                    {{ getCabangText(user) }}
                  </div>
                </td>

                <td class="text-medium-emphasis">
                  <div class="table-text-wrap">
                    {{ getDepartmentText(user) }}
                  </div>
                </td>

                <td>
                  <VChip
                    :color="getStatusColor(user)"
                    size="small"
                    variant="tonal"
                  >
                    {{ getStatusText(user) }}
                  </VChip>
                </td>

                <td class="text-medium-emphasis">
                  <div class="table-text-wrap last-login-text">
                    {{ user.last_login_at || '-' }}
                  </div>
                </td>

                <td class="text-center">
                  <div class="d-flex justify-center gap-2">
                    <VBtn
                      icon
                      size="small"
                      color="primary"
                      variant="tonal"
                      @click="openEdit(user)"
                    >
                      <VIcon icon="tabler-edit" />
                      <VTooltip activator="parent" location="top">
                        Edit User
                      </VTooltip>
                    </VBtn>

                    <VBtn
                      icon
                      size="small"
                      :color="user.is_active ? 'warning' : 'success'"
                      variant="tonal"
                      :loading="isActionLoading"
                      @click="toggleUserActive(user)"
                    >
                      <VIcon :icon="user.is_active ? 'tabler-user-off' : 'tabler-user-check'" />
                      <VTooltip activator="parent" location="top">
                        {{ user.is_active ? 'Nonaktifkan User' : 'Aktifkan User' }}
                      </VTooltip>
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <VDivider v-if="users.length" class="my-5" />

        <div
          v-if="users.length"
          class="d-flex flex-column flex-md-row justify-space-between align-md-center gap-3"
        >
          <div class="text-body-2 text-medium-emphasis">
            {{ paginationText }}
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
              class="text-none"
              @click="goToPreviousPage"
            >
              Prev
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              append-icon="tabler-chevron-right"
              :disabled="page >= lastPage"
              class="text-none"
              @click="goToNextPage"
            >
              Next
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VDialog
      v-model="isDialogOpen"
      max-width="720"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem>
          <template #prepend>
            <VAvatar color="primary" variant="tonal" rounded>
              <VIcon :icon="isEdit ? 'tabler-user-edit' : 'tabler-user-plus'" />
            </VAvatar>
          </template>

          <VCardTitle>
            {{ isEdit ? 'Edit User' : 'Tambah User' }}
          </VCardTitle>

          <VCardSubtitle>
            {{ isEdit ? 'Perbarui data akun user.' : 'Tambahkan akun user baru.' }}
          </VCardSubtitle>

          <template #append>
            <VBtn
              icon
              variant="text"
              color="secondary"
              :disabled="isSubmitting"
              @click="closeDialog"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </template>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.name"
                label="Nama User *"
                placeholder="Nama lengkap"
                density="comfortable"
                :disabled="isSubmitting"
                :error="isSubmitted && !form.name"
                :error-messages="formErrors.name || (isSubmitted && !form.name ? 'Nama user wajib diisi' : '')"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.username"
                label="Username"
                placeholder="Username login"
                density="comfortable"
                :disabled="isSubmitting"
                :error-messages="formErrors.username"
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model="form.email"
                label="Email *"
                placeholder="user@company.com"
                type="email"
                density="comfortable"
                :disabled="isSubmitting"
                :error="isSubmitted && !form.email"
                :error-messages="formErrors.email || (isSubmitted && !form.email ? 'Email wajib diisi' : '')"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.cabang_id"
                label="Cabang"
                :items="cabangOptions"
                item-title="title"
                item-value="id"
                :return-object="false"
                clearable
                density="comfortable"
                :disabled="isSubmitting"
                no-data-text="Cabang tidak ditemukan"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error-messages="formErrors.cabang_id"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.departemen_id"
                label="Department"
                :items="departmentOptions"
                item-title="title"
                item-value="id"
                :return-object="false"
                clearable
                density="comfortable"
                :disabled="isSubmitting"
                no-data-text="Department tidak ditemukan"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error-messages="formErrors.departemen_id"
              />
            </VCol>

            <VCol cols="12">
              <VAutocomplete
                v-model="form.role_id"
                label="Role User *"
                :items="roleOptions"
                item-title="title"
                item-value="id"
                :return-object="false"
                clearable
                density="comfortable"
                :disabled="isSubmitting"
                no-data-text="Role tidak ditemukan"
                placeholder="Pilih role user"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error="isSubmitted && !form.role_id"
                :error-messages="formErrors.role_ids || formErrors.role_id || (isSubmitted && !form.role_id ? 'Role user wajib dipilih' : '')"
              />
            </VCol>

            <VCol cols="12">
              <VSwitch
                v-model="form.is_active"
                color="success"
                inset
                :label="form.is_active ? 'User Aktif' : 'User Nonaktif'"
                :disabled="isSubmitting"
              />
            </VCol>

            <VCol v-if="isEdit" cols="12">
              <VAlert color="info" variant="tonal">
                Kosongkan password jika tidak ingin mengubah password user.
              </VAlert>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.password"
                label="Password"
                placeholder="Password"
                type="password"
                density="comfortable"
                :disabled="isSubmitting"
                :error="isSubmitted && !isEdit && !form.password"
                :error-messages="formErrors.password || (isSubmitted && !isEdit && !form.password ? 'Password wajib diisi' : '')"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.password_confirmation"
                label="Konfirmasi Password"
                placeholder="Konfirmasi password"
                type="password"
                density="comfortable"
                :disabled="isSubmitting"
                :error-messages="formErrors.password_confirmation"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-5 justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="isSubmitting"
            class="text-none"
            @click="closeDialog"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            prepend-icon="tabler-device-floppy"
            :loading="isSubmitting"
            class="text-none"
            @click="submitForm"
          >
            {{ isEdit ? 'Simpan Perubahan' : 'Simpan User' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
.user-table-wrapper {
  overflow-x: auto;
  overflow-y: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
}

/* Matikan scrollbar bawaan VTable supaya tidak double */
.user-table-wrapper :deep(.v-table__wrapper) {
  overflow-x: visible !important;
  overflow-y: visible !important;
}

.user-table {
  min-width: 1080px;
}

/* Pastikan table tidak bikin scroll internal lagi */
.user-table :deep(table) {
  inline-size: 100%;
  min-width: 1080px;
}

.user-table th {
  color: rgba(var(--v-theme-on-surface), 0.72);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  text-transform: uppercase;
  background: rgba(var(--v-theme-background), 0.55);
}

.user-table td {
  padding-block: 14px;
  vertical-align: middle;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 250px;
}

.user-avatar {
  flex: 0 0 auto;
}

.user-info {
  min-width: 0;
}

.user-name {
  max-width: 210px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-email,
.user-username {
  max-width: 230px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.role-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 180px;
  max-width: 260px;
}

.role-chip {
  max-width: 220px;
}

.role-chip :deep(.v-chip__content) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.table-text-wrap {
  min-width: 120px;
  max-width: 180px;
  line-height: 1.35;
  white-space: normal;
  word-break: break-word;
}

.last-login-text {
  min-width: 130px;
}

@media (max-width: 960px) {
  .user-table-wrapper {
    margin-inline: -4px;
  }

  .user-table,
  .user-table :deep(table) {
    min-width: 980px;
  }

  .user-name {
    max-width: 180px;
  }

  .user-email,
  .user-username {
    max-width: 200px;
  }

  .role-chip-list {
    max-width: 220px;
  }
}

@media (max-width: 600px) {
  .user-table-wrapper {
    border-radius: 12px;
  }

  .user-table,
  .user-table :deep(table) {
    min-width: 920px;
  }
}
</style>