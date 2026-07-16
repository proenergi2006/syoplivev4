<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch, watchEffect } from 'vue'

type Cabang = {
  id: number
  inisial_cabang: string
  nama_cabang: string
  grup_cabang_id: number
  is_active: boolean
}

type CabangForm = {
  id?: number
  inisial_cabang: string
  nama_cabang: string
  grup_cabang_id: number | null
  is_active: boolean
}

const loading = ref(false)
const rows = ref<Cabang[]>([])

// filters
const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)
const selectedWilayah = ref<number | null>(null)

// paging
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// dialog
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

// snackbar
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

// delete confirm
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Cabang | null>(null)

const openDelete = (row: Cabang) => {
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
    await axios.delete(`/master/cabang/${deleteTarget.value.id}`)
    notify(`Cabang "${deleteTarget.value.nama}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Cabang] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Gagal menghapus data', 'error')
  } finally {
    deleteLoading.value = false
  }
}

// form
const form = ref<CabangForm>({
  kode: '',
  nama: '',
  wilayah_id: null,
  is_active: true,
})

const statusItems = [
  { title: 'Semua', value: null },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

// (Optional) dropdown wilayah dari API wilayah
type WilayahOpt = { title: string; value: number }
const wilayahOptions = ref<WilayahOpt[]>([])

const fetchWilayahOptions = async () => {
  try {
    // ambil banyak biar buat dropdown, aman juga kalau wilayahnya tidak terlalu banyak
    const { data } = await axios.get('/master/wilayah', { params: { per_page: 1000 } })
    wilayahOptions.value = (data?.data || []).map((w: any) => ({
      title: `${w.kode} - ${w.nama}`,
      value: w.id,
    }))
  } catch (e) {
    console.warn('Fetch wilayah options failed:', e)
  }
}

// fetch cabang
const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value
    if (selectedWilayah.value !== null) params.wilayah_id = selectedWilayah.value

    const { data } = await axios.get('/master/cabang', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Cabang] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
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

watch([selectedStatus, selectedWilayah, rowPerPage, currentPage], () => {
  fetchRows()
})

watchEffect(() => {
  fetchRows()
})

// pagination text
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// actions
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = { kode: '', nama: '', wilayah_id: null, is_active: true }
  isDialogOpen.value = true
}
const openEdit = (row: Cabang) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = { id: row.id, kode: row.kode, nama: row.nama, wilayah_id: row.wilayah_id, is_active: row.is_active }
  isDialogOpen.value = true
}
const closeDialog = () => {
  isDialogOpen.value = false
}

const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  const payload = {
    kode: form.value.kode,
    nama: form.value.nama,
    wilayah_id: form.value.wilayah_id,
    is_active: form.value.is_active,
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/cabang/${form.value.id}`, payload)
      notify('Cabang berhasil diupdate', 'success')
    } else {
      await axios.post('/master/cabang', payload)
      notify('Cabang berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Cabang] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal, cek field input', 'warning')
      return
    }

    notify(res?.data?.message || 'Gagal menyimpan data', 'error')
  } finally {
    formLoading.value = false
  }
}

// init wilayah options untuk dropdown
fetchWilayahOptions()
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="4">
            <VTextField
              v-model="searchQuery"
              label="Cari (kode/nama)"
              placeholder="Cari (kode/nama)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedWilayah"
              label="Wilayah"
              :items="[{ title: 'Semua', value: null }, ...wilayahOptions]"
              item-title="title"
              item-value="value"
              density="compact"
              clearable
              clear-icon="mdi-close"
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
              clearable
              clear-icon="mdi-close"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="openCreate">
          + Tambah Cabang
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">KODE</th>
            <th scope="col">NAMA</th>
            <th scope="col">WILAYAH</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="c in rows" :key="c.id">
            <td class="text-medium-emphasis">{{ c.inisial_cabang }}</td>
            <td class="text-medium-emphasis">{{ c.nama_cabang }}</td>

            <td class="text-medium-emphasis">
              <!-- tampilkan label wilayah dari options -->
              {{
                wilayahOptions.find(w => w.value === c.grup_cabang_id)?.title
                || `#${c.grup_cabang_id}`
              }}
            </td>

            <td>
              <VChip :color="c.is_active ? 'success' : 'secondary'" size="small" class="text-capitalize">
                {{ c.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(c)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(c)">
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
            <td colspan="5" class="text-center">No data available</td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 200px;">
          <span class="text-no-wrap me-3">Rows per page:</span>

          <VSelect
            v-model="rowPerPage"
            density="compact"
            variant="plain"
            class="user-pagination-select"
            :items="[10, 20, 30, 50]"
          />
        </div>

        <div class="d-flex align-center">
          <h6 class="text-sm font-weight-regular">
            {{ paginationData }}
          </h6>

          <VPagination
            v-model="currentPage"
            size="small"
            :total-visible="1"
            :length="totalPage"
          />
        </div>
      </VCardText>
    </VCard>

    <!-- Dialog Form -->
    <VDialog v-model="isDialogOpen" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Cabang' : 'Tambah Cabang' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.kode" label="Kode" :error-messages="formErrors.kode" />
          <VTextField v-model="form.nama" label="Nama" :error-messages="formErrors.nama" />

          <VSelect
            v-model="form.wilayah_id"
            label="Wilayah"
            :items="wilayahOptions"
            item-title="title"
            item-value="value"
            :error-messages="formErrors.wilayah_id"
          />

          <VSwitch v-model="form.is_active" label="Aktif" inset />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" @click="closeDialog">Batal</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">Simpan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>
        <VCardText>
          Kamu yakin ingin menghapus cabang
          <b>{{ deleteTarget?.nama }}</b>
          (kode: <b>{{ deleteTarget?.kode }}</b>)?
          <div class="text-body-2 opacity-70 mt-2">
            Data yang sudah dihapus tidak bisa dikembalikan.
          </div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="deleteLoading" @click="closeDelete">Batal</VBtn>
          <VBtn color="error" :loading="deleteLoading" @click="confirmDelete">Hapus</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar"
      :timeout="snackTimeout"
      :color="snackColor"
      location="top end"
    >
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Tutup</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style lang="scss" scoped>
.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}
.text-capitalize { text-transform: capitalize; }
</style>
