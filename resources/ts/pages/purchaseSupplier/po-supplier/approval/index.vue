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
  harga_po: number
  disposisi_po?: number
  status_label?: string
  cfo_result: 0,
  cfo_pic: '',
  cfo_tanggal: '',
  cfo_summary: '',
  revert_cfo: '',
  revert_cfo_summary: '',
  ceo_result: 0,
  ceo_pic: '',
  ceo_tanggal: '',
  is_resubmission:number,
  resubmission_count:number,
    total_bl: number,
  total_ri: number,


  vendor?: { nama_vendor: string }
  produk?: { merk_dagang: string, jenis_produk: string }
  terminal?: { nama_terminal: string, lokasi_terminal:string }
}

// STATE
const rows = ref<InventoryPO[]>([])
const loading = ref(false)

const rowPerPage = ref(10)
const currentPage = ref(1)

const totalData = ref(0)
const totalPage = ref(1)
const userRoles = ref<string[]>([])

const search = ref({
  search: '',
  status: '',
})

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
const getProfile = async () => {
  try {
    const res = await axios.get('/auth/me')

    userRoles.value = res.data.data.role

    // console.log('ROLE:', res.data.role)
  } catch (err) {
    console.error(err)
  }
}
const canApprove = (item: any) => {
  if (userRoles.value.includes('CFO') && item.disposisi_po === 1) return true
  if (userRoles.value.includes('CEO') && item.disposisi_po === 2) return true
  return false
}
// FETCH DATA
const getData = async () => {
  try {
    loading.value = true

    const res = await axios.get('/inventory/purchase-order', {
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        search: search.value.search,
        status: search.value.status,
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

const formatNumber = (value: number) => {
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 20,
  }).format(value)
}

const goToEdit = (public_id: number): void => {
  router.push(`/purchaseSupplier/po-supplier/approval/${public_id}`)
  // router.push({
  // path: `/purchaseSupplier/po-supplier/approve/${po_number}`,
  // query: {
  //   id: id_master
  // }
// })
}
const getRowClass = (item: any) => {
  // CFO role
  // if (userRoles.value.includes('CFO') && item.disposisi_po === 1 && item.cfo_result === 0) {
  //   return 'bg-grey-100'
  // }

  // // CEO role
  // if (userRoles.value.includes('CEO') &&item.disposisi_po === 2 && item.ceo_result === 0) {
  //   return 'bg-grey-100'
  // }

  const isCFO = ['CFO', 'Chief Financial Officer']
    .some(role =>userRoles.value.includes(role))

  const isCEO = ['CEO', 'Chief Executive Officer']
    .some(role => userRoles.value.includes(role))

  if (isCFO && item.disposisi_po === 1 && item.cfo_result === 0) {
    return 'bg-grey-100'
  }

  if (isCEO && item.disposisi_po === 2 && item.ceo_result === 0) {
    return 'bg-grey-100'
  }

  return ''
}

const refreshTable = async () => {
  loading.value = true
  try {
    await getData()
  } finally {
    loading.value = false
  }
}

// AUTO FETCH
onMounted(() => {
  getData()
  getVendor()
  getTerminal()
  getProfile()
})

// kalau page berubah
watch(
  [search, currentPage, rowPerPage],
  async () => {
    await getData()
  },
  { deep: true }
)


// kalau per page berubah
// watch(rowPerPage, () => {
//   currentPage.value = 1
//   getData()
// })

const getStatusLabel = (val: unknown) => {
  const map: Record<number, string> = {
    1: 'Menunggu CFO',
    2: 'Menunggu CEO',
    3: 'Ditolak CFO',
    4: 'Terverifikasi',
    5: 'Ditolak CEO',
  }

  return map[Number(val)] ?? '-'
}

const chipColor: Record<number, string> = {
  1: 'info',
  2: 'info',
  3: 'error',
  4: 'success',
  5: 'error',
}
const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Pending CFO', value: '1' },
  { title: 'Pending CEO', value: '2' },
  { title: 'Approved', value: '4' },
  { title: 'Reject CEO', value: '5' },
]

</script>
<template>
  <section>
    <!-- Filters -->
    

    <VCard class="mb-4 pa-4">
      <h3 class="mb-3">PENCARIAN</h3>

      <VRow>
        <VCol cols="12" md="6">
          <VTextField
            v-model="search.search"
            label="Kata Kunci"
            density="comfortable"
          />
        </VCol>
      
        <VCol cols="12" sm="3">
          <VSelect
            v-model="search.status"
            label="Status"
            :items="statusItems"
            item-title="title"
            item-value="value"
            density="comfortable"
          />
        </VCol>
      </VRow>

      <!-- <div class="d-flex gap-2 mt-4">
        <VBtn color="info">
          Cari
        </VBtn>
      </div> -->
    </VCard>
    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
      <VTooltip location="bottom">
          <template #activator="{ props }">
            <VBtn
              v-bind="props"
              icon="mdi-refresh"
              variant="tonal"
              size="small"
              :loading="loading"
              @click="refreshTable"
            />
          </template>

          Refresh Table
        </VTooltip>
        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">
          Loading...
        </VChip>
      </VCardText>

      <VDivider />

      <VTable >
        <thead>
         <tr>
           <th>No </th>
           <th>Nomor PO</th>
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
          <tr
            v-for="(v, index) in rows"
            :key="v.id_master"
            :class="getRowClass(v)"
          >
            <td>
              {{ (currentPage - 1) * rowPerPage + index + 1 }}
            </td>

            <td class="text-no-wrap">{{ v.nomor_po || '-' }}
              <br>
                <VChip v-if="v.is_resubmission == 1"
                  size="small"
                  color="warning"
                  class="mb-0"
                >
                   Pengajuan ulang ke - {{ v.resubmission_count }}
                </VChip>
            </td>

            <td>{{ formatDate(v.tanggal_inven) }}</td>

            <td> 
              <div><strong>{{ v.vendor?.nama_vendor || '-' }}</strong></div>
              <div class="text-caption text-grey">
                {{ v.terminal?.nama_terminal+' - '+ v.terminal?.lokasi_terminal|| '-' }}
              </div>
            </td>

            <td class="text-no-wrap">{{ v.produk?.jenis_produk +' - '+v.produk?.merk_dagang  || '-' }}</td>

             <td class="text-end text-no-wrap">
              PO : {{ formatNumber(v.volume_po ?? 0) }}
              <br>
              BL : {{ formatNumber(v.total_bl ?? 0) }}
              <br>
              RI : {{ formatNumber(v.total_ri ?? 0) }}
            </td>

            <td class="text-right text-no-wrap">PO: {{ formatNumber(v.harga_po) }}
              <br> RI: {{ formatNumber(v.harga_tebus) }}
            </td>

            <td>
              <VChip size="small"  :color="chipColor[v.disposisi_po??0]">
               {{ getStatusLabel(v.disposisi_po) }}
              </VChip>
            </td>

              <td class="text-center" style="width: 5rem;">
                <VBtn size="34" class="mr-1" variant="tonal" color="primary" @click="goToEdit(v.id_master)">
                  <VIcon icon="ri-information-2-line"/>
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
<style>
.row-pending {
  background-color: #f5f5f5 !important;
  opacity: 0.7;
}
</style>