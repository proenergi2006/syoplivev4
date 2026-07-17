<script setup lang="ts">
import axios from '@axios';
import { computed, ref, watch, watchEffect } from 'vue';

type CabangLite = { id: number; kode?: string; nama: string }
type AreaLite = { id: number; nama_area: string }

type Terminal = {
  id: number
  nama_terminal: string
  tanki_terminal?: string | null
  lokasi_terminal?: string | null
  kategori_terminal: 'Depo' | 'Dispenser' | 'Truck Gantung'
  batas_atas?: number | null
  batas_bawah?: number | null
  latitude?: number | null
  longitude?: number | null
  alamat_terminal?: string | null
  telp_terminal?: string | null
  fax_terminal?: string | null
  cc_terminal?: string | null
  catatan_terminal?: string | null
  att_terminal?: string | null

  inisial_terminal?: string | null
  id_cabang?: number | null
  id_area?: number | null
  is_active: boolean

  cabang?: CabangLite | null
  area?: AreaLite | null
}

type TerminalForm = {
  id?: number
  nama_terminal: string
  tanki_terminal: string
  lokasi_terminal: string
  kategori_terminal: 'Depo' | 'Dispenser' | 'Truck Gantung'
  batas_atas: number | null
  batas_bawah: number | null
  latitude: number | null
  longitude: number | null
  alamat_terminal: string
  telp_terminal: string
  fax_terminal: string
  cc_terminal: string
  catatan_terminal: string
  inisial_terminal: string
  id_cabang: number | null
  id_area: number | null
  is_active: boolean
}

// -------------------------
// List + filter + paging
// -------------------------
const loading = ref(false)
const rows = ref<Terminal[]>([])

const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)

const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// -------------------------
// Options dropdown Cabang & Area
// -------------------------
const optLoading = ref(false)
const cabangOptions = ref<CabangLite[]>([])
const areaOptions = ref<AreaLite[]>([])

const fetchCabangOptions = async () => {
  try {
    // ambil banyak supaya dropdown lengkap
    const { data } = await axios.get('/master/cabang', { params: { per_page: 9999 } })
    console.log(data)
    cabangOptions.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : [])
  } catch (e: any) {
    console.error('[Terminal] fetchCabangOptions error:', e?.response?.status, e?.response?.data || e)
    cabangOptions.value = []
  }
}

const fetchAreaOptions = async () => {
  try {
    const { data } = await axios.get('/master/area', { params: { per_page: 9999 } })
    areaOptions.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : [])
  } catch (e: any) {
    console.error('[Terminal] fetchAreaOptions error:', e?.response?.status, e?.response?.data || e)
    areaOptions.value = []
  }
}

// optional: kalau area tergantung cabang, aktifkan ini nanti
// const fetchAreaByCabang = async (cabangId: number | null) => {
//   if (!cabangId) { areaOptions.value = []; return }
//   const { data } = await axios.get('/master/area', { params: { per_page: 9999, id_cabang: cabangId } })
//   areaOptions.value = Array.isArray(data?.data) ? data.data : []
// }

// load options awal
const fetchOptions = async () => {
  optLoading.value = true
  try {
    await Promise.all([fetchCabangOptions(), fetchAreaOptions()])
  } finally {
    optLoading.value = false
  }
}
watchEffect(() => { fetchOptions() })

// -------------------------
// Dialog Create/Edit
// -------------------------
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<TerminalForm>({
  nama_terminal: '',
  tanki_terminal: '',
  lokasi_terminal: '',
  kategori_terminal: 'Depo',
  batas_atas: null,
  batas_bawah: null,
  latitude: null,
  longitude: null,
  alamat_terminal: '',
  telp_terminal: '',
  fax_terminal: '',
  cc_terminal: '',
  catatan_terminal: '',
  inisial_terminal: '',
  id_cabang: null,
  id_area: null,
  is_active: true,
})

// kalau area tergantung cabang, aktifkan watch ini:
// watch(() => form.value.id_cabang, (val) => {
//   form.value.id_area = null
//   fetchAreaByCabang(val)
// })

// -------------------------
// Snackbar
// -------------------------
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

const notify = (text: string, color: 'success' | 'error' | 'warning' | 'info' = 'success', timeout = 3000) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// -------------------------
// Delete dialog
// -------------------------
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Terminal | null>(null)

const openDelete = (row: Terminal) => {
  deleteTarget.value = row
  deleteDialog.value = true
}
const closeDelete = () => {
  deleteDialog.value = false
  deleteTarget.value = null
}
const confirmDelete = async () => {
  if (!deleteTarget.value) return
  deleteLoading.value = true
  try {
    await axios.delete(`/master/terminal/${deleteTarget.value.id}`)
    notify(`Terminal "${deleteTarget.value.nama_terminal}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    notify(e?.response?.data?.message || 'Gagal menghapus terminal', 'error')
  } finally {
    deleteLoading.value = false
  }
}

// -------------------------
// Detail dialog
// -------------------------
const detailDialog = ref(false)
const detailLoading = ref(false)
const detailRow = ref<Terminal | null>(null)

const openDetail = async (row: Terminal) => {
  detailDialog.value = true
  detailLoading.value = true
  detailRow.value = null
  try {
    const { data } = await axios.get(`/master/terminal/${row.id}`)
    detailRow.value = data
  } catch {
    notify('Gagal mengambil detail terminal', 'error')
    detailDialog.value = false
  } finally {
    detailLoading.value = false
  }
}

// -------------------------
// Fetch list
// -------------------------
const statusItems = [
  { title: 'Semua', value: null },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

const kategoriItems: Array<TerminalForm['kategori_terminal']> = ['Depo', 'Dispenser', 'Truck Gantung']

const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value

    const { data } = await axios.get('/master/terminal', { params })
    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)
    totalPage.value = Math.max(Number(data?.last_page ?? 1), 1)
    if (currentPage.value > totalPage.value) currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Terminal] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

// debounce search
let t: any = null
watch(searchQuery, () => {
  clearTimeout(t)
  t = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
})

watch([selectedStatus, rowPerPage, currentPage], () => fetchRows())
watchEffect(() => { fetchRows() })

const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// -------------------------
// Create/Edit actions
// -------------------------
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = {
    nama_terminal: '',
    tanki_terminal: '',
    lokasi_terminal: '',
    kategori_terminal: 'Depo',
    batas_atas: null,
    batas_bawah: null,
    latitude: null,
    longitude: null,
    alamat_terminal: '',
    telp_terminal: '',
    fax_terminal: '',
    cc_terminal: '',
    catatan_terminal: '',
    inisial_terminal: '',
    id_cabang: null,
    id_area: null,
    is_active: true,
  }
  isDialogOpen.value = true
}

const openEdit = (row: Terminal) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = {
    id: row.id,
    nama_terminal: row.nama_terminal ?? '',
    tanki_terminal: row.tanki_terminal ?? '',
    lokasi_terminal: row.lokasi_terminal ?? '',
    kategori_terminal: row.kategori_terminal ?? 'Depo',
    batas_atas: row.batas_atas ?? null,
    batas_bawah: row.batas_bawah ?? null,
    latitude: row.latitude ?? null,
    longitude: row.longitude ?? null,
    alamat_terminal: row.alamat_terminal ?? '',
    telp_terminal: row.telp_terminal ?? '',
    fax_terminal: row.fax_terminal ?? '',
    cc_terminal: row.cc_terminal ?? '',
    catatan_terminal: row.catatan_terminal ?? '',
    inisial_terminal: row.inisial_terminal ?? '',
    id_cabang: row.id_cabang ?? null,
    id_area: row.id_area ?? null,
    is_active: !!row.is_active,
  }
  isDialogOpen.value = true
}

const closeDialog = () => { isDialogOpen.value = false }

const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  const payload = {
    nama_terminal: form.value.nama_terminal,
    tanki_terminal: form.value.tanki_terminal,
    lokasi_terminal: form.value.lokasi_terminal,
    kategori_terminal: form.value.kategori_terminal,
    batas_atas: form.value.batas_atas,
    batas_bawah: form.value.batas_bawah,
    latitude: form.value.latitude,
    longitude: form.value.longitude,
    alamat_terminal: form.value.alamat_terminal,
    telp_terminal: form.value.telp_terminal,
    fax_terminal: form.value.fax_terminal,
    cc_terminal: form.value.cc_terminal,
    catatan_terminal: form.value.catatan_terminal,
    inisial_terminal: form.value.inisial_terminal,
    id_cabang: form.value.id_cabang,
    id_area: form.value.id_area,
    is_active: form.value.is_active,
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/terminal/${form.value.id}`, payload)
      notify('Terminal berhasil diupdate', 'success')
    } else {
      await axios.post('/master/terminal', payload)
      notify('Terminal berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal. Cek field yang merah.', 'warning')
    } else {
      notify(res?.data?.message || 'Gagal menyimpan data', 'error')
    }
  } finally {
    formLoading.value = false
  }
}
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="8">
            <VTextField
              v-model="searchQuery"
              label="Cari (nama/inisial/lokasi)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="openCreate">
          + Tambah Terminal
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th>NAMA</th>
            <th>KATEGORI</th>
            <th>CABANG</th>
            <th>AREA</th>
            <th>STATUS</th>
            <th class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="t in rows" :key="t.id">
            <td>
              <div class="d-flex flex-column">
                <a
                  href="javascript:void(0)"
                  class="text-primary font-weight-medium"
                  style="text-decoration: none;"
                  @click="openDetail(t)"
                >
                  {{ t.nama_terminal }}
                </a>
                <span class="text-caption text-medium-emphasis">
                  {{ t.inisial_terminal || '-' }} • {{ t.lokasi_terminal || '-' }}
                </span>
              </div>
            </td>

            <td class="text-medium-emphasis">{{ t.kategori_terminal }}</td>
            <td class="text-medium-emphasis">{{ t.cabang?.nama_cabang ?? '-' }}</td>
            <td class="text-medium-emphasis">{{ t.area?.nama_area ?? '-' }}</td>

            <td>
              <VChip :color="t.is_active ? 'success' : 'secondary'" size="small">
                {{ t.is_active ? 'Active' : 'Inactive' }}
              </VChip>
            </td>

            <td class="text-center">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />
                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openDetail(t)">
                      <template #prepend>
                        <VIcon icon="mdi-eye-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Detail</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openEdit(t)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(t)">
                      <template #prepend>
                        <VIcon icon="mdi-delete-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Delete</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="6" class="text-center">No data available</td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 200px;">
          <span class="text-no-wrap me-3">Rows per page:</span>
          <VSelect v-model="rowPerPage" density="compact" variant="plain" :items="[10, 20, 30, 50]" />
        </div>

        <div class="d-flex align-center">
          <h6 class="text-sm font-weight-regular">{{ paginationData }}</h6>
          <VPagination v-model="currentPage" size="small" :total-visible="1" :length="totalPage" />
        </div>
      </VCardText>
    </VCard>

    <!-- Dialog Create/Edit -->
    <VDialog v-model="isDialogOpen" max-width="900">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Terminal' : 'Tambah Terminal' }}
        </VCardTitle>

        <VCardText>
          <VRow>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.nama_terminal" label="Nama Terminal" :error-messages="formErrors.nama_terminal" />
            </VCol>

            <VCol cols="12" sm="6">
              <VTextField v-model="form.inisial_terminal" label="Inisial Terminal" :error-messages="formErrors.inisial_terminal" />
            </VCol>

            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.kategori_terminal"
                label="Kategori"
                :items="kategoriItems"
                :error-messages="formErrors.kategori_terminal"
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VTextField v-model="form.lokasi_terminal" label="Lokasi Terminal" :error-messages="formErrors.lokasi_terminal" />
            </VCol>

            <!-- ✅ Cabang select -->
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.id_cabang"
                label="Cabang"
                :items="cabangOptions"
                item-title="nama_cabang"
                item-value="id"
                :loading="optLoading"
                clearable
                :error-messages="formErrors.id_cabang"
              />
            </VCol>

            <!-- ✅ Area select -->
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.id_area"
                label="Area"
                :items="areaOptions"
                item-title="nama_area"
                item-value="id"
                :loading="optLoading"
                clearable
                :error-messages="formErrors.id_area"
              />
            </VCol>

            <VCol cols="12">
              <VTextField v-model="form.alamat_terminal" label="Alamat Terminal" :error-messages="formErrors.alamat_terminal" />
            </VCol>

            <VCol cols="12" sm="6">
              <VTextField v-model.number="form.latitude" type="number" label="Latitude" :error-messages="formErrors.latitude" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model.number="form.longitude" type="number" label="Longitude" :error-messages="formErrors.longitude" />
            </VCol>

            <VCol cols="12" sm="4">
              <VTextField v-model="form.telp_terminal" label="Telp" :error-messages="formErrors.telp_terminal" />
            </VCol>
            <VCol cols="12" sm="4">
              <VTextField v-model="form.fax_terminal" label="Fax" :error-messages="formErrors.fax_terminal" />
            </VCol>
            <VCol cols="12" sm="4">
              <VTextField v-model="form.cc_terminal" label="CC Terminal" :error-messages="formErrors.cc_terminal" />
            </VCol>

            <VCol cols="12">
              <VTextarea v-model="form.catatan_terminal" label="Catatan" rows="3" :error-messages="formErrors.catatan_terminal" />
            </VCol>

            <VCol cols="12">
              <VSwitch v-model="form.is_active" label="Aktif" inset />
            </VCol>
          </VRow>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" @click="closeDialog">Batal</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">Simpan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Detail dialog (singkat) -->
    <VDialog v-model="detailDialog" max-width="820">
      <VCard>
        <VCardTitle class="text-h6">Detail Terminal</VCardTitle>
        <VCardText>
          <div v-if="detailLoading" class="d-flex align-center gap-2">
            <VProgressCircular indeterminate size="20" />
            <span>Loading...</span>
          </div>

          <div v-else-if="detailRow">
            <div class="text-caption text-medium-emphasis">Nama</div>
            <div class="text-body-1 font-weight-medium mb-3">{{ detailRow.nama_terminal }}</div>

            <div class="d-flex flex-wrap gap-6">
              <div>
                <div class="text-caption text-medium-emphasis">Cabang</div>
                <div class="text-body-2">{{ detailRow.cabang?.nama ?? '-' }}</div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis">Area</div>
                <div class="text-body-2">{{ detailRow.area?.nama_area ?? '-' }}</div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis">Kategori</div>
                <div class="text-body-2">{{ detailRow.kategori_terminal }}</div>
              </div>
            </div>

            <div class="mt-4">
              <div class="text-caption text-medium-emphasis">Alamat</div>
              <div class="text-body-2">{{ detailRow.alamat_terminal || '-' }}</div>
            </div>
          </div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" @click="detailDialog = false">Tutup</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Confirm delete -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>
        <VCardText>
          Kamu yakin ingin menghapus terminal <b>{{ deleteTarget?.nama_terminal }}</b>?
        </VCardText>
        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="deleteLoading" @click="closeDelete">Batal</VBtn>
          <VBtn color="error" :loading="deleteLoading" @click="confirmDelete">Hapus</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar v-model="snackbar" :timeout="snackTimeout" :color="snackColor" location="top end">
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Tutup</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>
