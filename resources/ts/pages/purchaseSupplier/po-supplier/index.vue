<script setup lang="ts">
import axios from '@axios'
import { ref, computed, onMounted, watch } from 'vue'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'
import { useRouter } from 'vue-router'

const router = useRouter()
const onEdit = (item: any) => {
  router.push({
  path: '/purchaseSupplier/po-supplier/add',
  query: { id: item }
})
}

type InventoryPO = {
  id_master: number
  nomor_po: string
  tanggal_inven: string
  volume_po: number
  harga_tebus: number
  disposisi_po?: number
  status_label?: string

  vendor?: { nama_vendor: string }
  produk?: { merk_dagang: string, jenis_produk: string }
  terminal?: { nama_terminal: string, lokasi_terminal:string }
}

// STATE
const rows = ref<InventoryPO[]>([])
const loading = ref(false)

const searchQuery = ref('')
const selectedStatus = ref('')

const rowPerPage = ref(10)
const currentPage = ref(1)

const totalData = ref(0)
const totalPage = ref(1)

// PAGINATION TEXT
const paginationData = computed(() => {
  const start = (currentPage.value - 1) * rowPerPage.value + 1
  const end = Math.min(currentPage.value * rowPerPage.value, totalData.value)
  return `${start}-${end} of ${totalData.value}`
})

// FORMAT DATE
const formatDate = (date: string) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('id-ID')
}

// FETCH DATA
const getData = async () => {
  try {
    loading.value = true

    const res = await axios.get('/inventory/purchase-order', {
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        keyword: searchQuery.value,
        status: selectedStatus.value,
      },
    })

    // 🔥 SAFE MAPPING (anti undefined error)
    rows.value = res.data?.data ?? []
    totalData.value = res.data?.total ?? 0
    totalPage.value = res.data?.last_page ?? 1

  } catch (err) {
    console.error('FETCH ERROR:', err)
    rows.value = []
  } finally {
    loading.value = false
  }
}


const vendorList = ref<any[]>([])
const terminalList = ref<any[]>([])


const getVendor = async (): Promise<void> => {
  try {
    const res = await axios.get('/master/vendor/dropdown-select')

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    vendorList.value = data
  } catch (error: unknown) {
    vendorList.value = []

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat data vendor.'),
    })
  }
}

const getTerminal = async () => {
  const res = await axios.get('/terminal')
  terminalList.value = res.data.map((p: any) => ({
    id: p.id,
    nama_terminal: p.nama_terminal,
    lokasi_terminal: p.lokasi_terminal,
  }))
}

const formatMoney = (value: any) => {
  if (value === null || value === undefined || value === '') return '0,0000'

  const num = Number(String(value))

  if (isNaN(num)) return '0,0000'

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 4,
    maximumFractionDigits: 4,
  }).format(num)
}

// AUTO FETCH
onMounted(() => {
  getData()
  getVendor()
  getTerminal()
})

// kalau page berubah
watch(currentPage, () => {
  getData()
})

// kalau per page berubah
watch(rowPerPage, () => {
  currentPage.value = 1
  getData()
})

const getStatusLabel = (val: unknown) => {
  const map: Record<number, string> = {
    1: 'Verifikasi CFO',
    2: 'Verifikasi CEO',
    3: 'Ditolak CFO',
    4: 'Terverifikasi',
    5: 'Ditolak CEO',
  }

  return map[Number(val)] ?? '-'
}
</script>
<template>
  <section>
    <!-- Filters -->
    

    <VCard class="mb-4 pa-4">
      <h3 class="mb-3">PENCARIAN</h3>

      <VRow>
        <VCol cols="12" md="6">
          <VTextField
            label="Kata Kunci"
            density="comfortable"
          />
        </VCol>
      
        <VCol cols="12" md="6">
          <VSelect
            label="Terminal/Depot"
            :items="terminalList"
            item-title="nama_terminal"
            item-value="id"
            density="comfortable"
          />
        </VCol>

        <VCol cols="12" md="6">
         <VAutocomplete
          label="Vendor *"
          :items="vendorList"
          item-title="nama_vendor"
          item-value="id"
          clearable
          density="comfortable"
          :menu-props="{ maxHeight: 300 }"
        ></VAutocomplete>
        </VCol>

        <VCol cols="12" md="3">
          <VTextField
            label="Tanggal Awal"
            type="date"
            density="comfortable"
          />
        </VCol>

        <VCol cols="12" md="3">
          <VTextField
            label="Tanggal Akhir"
            type="date"
            density="comfortable"
          />
        </VCol>
      </VRow>

      <div class="d-flex gap-2 mt-4">
        <VBtn color="info">
          Cari
        </VBtn>

        <VBtn color="success">
          Export Data
        </VBtn>
      </div>
    </VCard>
    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
       <VBtn
        to="po-supplier/add"
        >
        <VIcon start icon="ri-add-circle-line"/> Tambah Data
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">
          Loading...
        </VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
         <tr>
           <th>No </th>
           <th>No PO</th>
           <th>Tanggal</th>
           <th>Vendor / Terminal</th>
           <th>Produk</th>
           <th>Volume</th>
           <th>Harga Tebus</th>
           <th>Disposisi</th>
           <th>Aksi</th>
         </tr>
       </thead>
        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id_master">
            <td>
              {{ (currentPage - 1) * rowPerPage + index + 1 }}
            </td>

            <td>{{ v.nomor_po || '-' }}</td>

            <td>{{ formatDate(v.tanggal_inven) }}</td>

            <td> 
              <div><strong>{{ v.vendor?.nama_vendor || '-' }}</strong></div>
              <div class="text-caption text-grey">
                {{ v.terminal?.nama_terminal+' - '+ v.terminal?.lokasi_terminal|| '-' }}
              </div>
            </td>

            <td>{{ v.produk?.jenis_produk +' - '+v.produk?.merk_dagang  || '-' }}</td>

            <td>{{ formatMoney(v.volume_po) }}</td>

            <td>{{ formatMoney(v.harga_tebus) }}</td>

            <td>
              <VChip size="small" color="info">
               {{ getStatusLabel(v.disposisi_po) }}
              </VChip>
            </td>

              <td class="text-center" style="width: 5rem;">
                <VBtn size="x-small" color="default" variant="plain" icon>
                  <VIcon size="24" icon="mdi-dots-vertical" />

                  <VMenu activator="parent">
                    <VList>
                      <VListItem href="javascript:void(0)" >
                        <template #prepend>
                          <VIcon icon="tabler-eye" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle>Lihat Detail</VListItemTitle>
                      </VListItem>

                      <VListItem @click="onEdit(v.id_master)">
                        <template #prepend>
                          <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle>Edit</VListItemTitle>
                      </VListItem>
                    </VList>
                  </VMenu>
                </VBtn>
              </td>
          </tr>
        </tbody>

       <tfoot v-if="!rows.length && !loading">
        <tr>
          <td colspan="9" class="text-center">
            No data available
          </td>
        </tr>
      </tfoot>
      </VTable>

      <VDivider />

      <!-- Footer pagination -->
      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 220px;">
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
  </section>
</template>
