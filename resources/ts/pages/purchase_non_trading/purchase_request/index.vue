<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@axios'
import { showErrorAlert } from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'

interface PurchaseRequestItem {
  id: number
  public_id: string
  nomor_pr: string | null
  tanggal_pr: string | null
  cabang: string | null
  department: string | null
  kategori: string | null
  status: string | null
}

interface PurchaseRequestApiResponse {
  success?: boolean
  status?: boolean
  data?: PurchaseRequestItem[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

const router = useRouter()

const loading = ref(false)
const rows = ref<PurchaseRequestItem[]>([])

const searchQuery = ref('')
const selectedStatus = ref('')
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalData = ref(0)
const totalPage = ref(1)

const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Draft', value: 'DRAFT' },
  { title: 'In Progress', value: 'IN PROGRESS' },
  { title: 'Approved', value: 'APPROVED' },
  { title: 'Rejected', value: 'REJECTED' },
]

const paginationData = computed(() => {
  if (!totalData.value) return '0-0 of 0'

  const firstIndex = (currentPage.value - 1) * rowPerPage.value + 1
  const lastIndex = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${firstIndex}-${lastIndex} of ${totalData.value}`
})

const formatDate = (value: string | null): string => {
  if (!value) return '-'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

const formatStatus = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'draft') return 'Draft'
  if (normalized === 'submitted') return 'Submitted'
  if (normalized === 'approved') return 'Approved'
  if (normalized === 'rejected') return 'Rejected'

  return status
}

const getStatusColor = (status: string | null): string => {
  const normalized = String(status || '').toLowerCase()

  if (normalized === 'draft') return 'secondary'
  if (normalized === 'submitted') return 'warning'
  if (normalized === 'approved') return 'success'
  if (normalized === 'rejected') return 'error'

  return 'secondary'
}

const fetchPurchaseRequests = async (): Promise<void> => {
  loading.value = true

  try {
    const response = await axios.get<PurchaseRequestApiResponse>(
      '/transaction/purchase-request',
      {
        headers: {
          Accept: 'application/json',
        },
        params: {
          search: searchQuery.value || undefined,
          status: selectedStatus.value || undefined,
          page: currentPage.value,
          per_page: rowPerPage.value,
        },
      },
    )

    const responseData = response.data

    rows.value = Array.isArray(responseData?.data)
      ? responseData.data
      : []

    const meta = responseData?.meta

    totalData.value = Number(meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(meta?.last_page ?? 1)
    currentPage.value = Number(meta?.current_page ?? 1)
  } catch (error: unknown) {
    const err = error as AxiosErrorShape

    console.error('[Purchase Request] FETCH ERROR:', err)

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal memuat data purchase request'),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = ''
  currentPage.value = 1

  await fetchPurchaseRequests()
}

const goToCreate = (): void => {
  router.push('/purchase_non_trading/purchase_request/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/purchase_non_trading/purchase_request/${publicId}/edit`)
}

const openDetail = (publicId: string): void => {
  router.push(`/purchase-request/${publicId}`)
}

watch(currentPage, async () => {
  await fetchPurchaseRequests()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchPurchaseRequests()
})

watch([searchQuery, selectedStatus], async () => {
  currentPage.value = 1
  await fetchPurchaseRequests()
})

onMounted(async () => {
  await fetchPurchaseRequests()
})
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="5">
            <VTextField
              v-model="searchQuery"
              label="Cari kode PR"
              placeholder="Cari purchase request..."
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
              density="compact"
            />
          </VCol>

          <VCol cols="12" sm="3" class="d-flex align-end">
            <VBtn
              variant="outlined"
              color="secondary"
              block
              @click="resetFilters"
            >
              Reset Filter
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="goToCreate">
          + Tambah Purchase Request
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
            <th scope="col">NO</th>
            <th scope="col">NOMOR PR</th>
            <th scope="col">TANGGAL</th>
            <th scope="col">CABANG</th>
            <th scope="col">DEPARTMENT</th>
            <th scope="col">KATEGORI</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id">
            <td class="text-medium-emphasis">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>
            <td class="text-medium-emphasis">{{ v.nomor_pr || '-' }}</td>
            <td class="text-medium-emphasis">{{ formatDate(v.tanggal_pr) }}</td>
            <td class="text-medium-emphasis">{{ v.cabang || '-' }}</td>
            <td class="text-medium-emphasis">{{ v.department || '-' }}</td>
            <td class="text-medium-emphasis">{{ v.kategori || '-' }}</td>
            <td>
              <VChip
                :color="getStatusColor(v.status)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatus(v.status) }}
              </VChip>
            </td>
            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openDetail(v.public_id)">
                      <template #prepend>
                        <VIcon icon="tabler-eye" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Lihat Detail</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="goToEdit(v.public_id)">
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

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="8" class="text-center">
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

<style lang="scss">
.text-capitalize { text-transform: capitalize; }
</style>

<style lang="scss" scoped>
.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}

.vendor-detail-content {
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.detail-section {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-top: 20px;
}

.detail-section-title {
  font-size: 1.05rem;
  font-weight: 700;
  margin-bottom: 18px;
}

.detail-item {
  margin-bottom: 16px;
}

.detail-label {
  font-size: 0.78rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 4px;
}

.detail-value {
  font-size: 0.98rem;
  font-weight: 500;
  word-break: break-word;
  line-height: 1.6;
}

.pkp-split-row {
  align-items: stretch;
}

.pkp-col {
  padding-top: 4px;
  padding-bottom: 4px;
}

.pkp-col-right {
  border-left: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

@media (max-width: 959px) {
  .pkp-col-right {
    border-left: none;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    margin-top: 16px;
    padding-top: 20px;
  }
}
</style>
