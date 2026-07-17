<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from '@axios'
import { useRoute } from 'vue-router'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
const route = useRoute()
const router = useRouter()

const id = route.params.id


const po = reactive<any>({
  nomor_po: '',
  tanggal_inven: '',
  volume_po: null,
  volume_bol: null,

  vendor: '',
  produk: '',
  jenis_produk: '',
  terminal: '',
  lokasi: '',
  nilai_pbbkb: '',
  iuran_migas: false,
  kd_tax: '',
  ongkos_angkut: '',
  kategori_plat: '',
  terms: '',
  terms_day: '',
  catatan_po: '',
  internal_notes: '',
  disposisi_po: 0,
  cfo_result: 0,
  cfo_pic: '',
  cfo_tanggal: '',
  cfo_summary: '',
  revert_cfo: '',
  revert_cfo_summary: '',
  ceo_result: 0,
  ceo_pic: '',
  ceo_tanggal: '',
  ceo_summary: '',
  revert_ceo_summary: '',

  pbbkb_po: 0,
  harga_tebus: 0,
  subtotal: 0,
  ppn: 0,
  pph_22: 0,
  nominal_migas: 0,
  total: 0,
  is_price_changed: 0,
})

const showHistory = ref(false)
const latestHistory = ref<any>(null)
const histories = ref<any[]>([])

// ====== APPROVAL STATE ======
const approvalCEO = reactive({
  decision: '' as '' | 'approve' | 'reject',
  note: '',
})

const approvalCFO = reactive({
  decision: '',
  note: '',
})


const isWaitingCEO = computed(() => {
  return po.disposisi_po === 2
})

const isWaitingCFO = computed(() => po.disposisi_po === 1)

const isApprovedCFO = computed(() => po.cfo_result === 1)

const isRejectedCFO = computed(() => po.cfo_result === 2)
// ====== LOADING ======
const loading = ref(false)

// ====== FETCH PO ======
const fetchPO = async (id: any) => {
  loading.value = true

  try {
    const res = await axios.get(`/inventory/purchase-order/${id}`)
    const data = res.data
    Object.assign(po, {
        nomor_po: data.nomor_po,
        tanggal_inven: data.tanggal_inven,
        volume_po: data.volume_po,
        volume_bol: data.volume_bol,

        vendor: data.vendor.nama_vendor,
        produk: data.produk.merk_dagang,
        jenis_produk: data.produk.jenis_produk,
        terminal: data.terminal.nama_terminal,
        lokasi: data.terminal.lokasi_terminal,

        kategori_plat: data.kategori_plat,
        kd_tax: data.kd_tax,
        ongkos_kirim: data.ongkos_kirim,
        terms: data.terms,
        terms_day: data.terms_day,
        nilai_pbbkb: data.nilai_pbbkb,
        iuran_migas: data.iuran_migas,

        catatan_po: data.keterangan,
        internal_notes: data.internal_notes,
        harga_tebus: data.harga_tebus,
        pbbkb_po: data.pbbkb_po,
        nominal_migas: data.nominal_migas,
        subtotal: data.subtotal,
        ppn: data.ppn_12,
        pph_22: data.pph_22,
        total: data.total_order,
        disposisi_po: data.disposisi_po,
        is_price_changed: data.is_price_changed,
        cfo_result: data.cfo_result,
        cfo_summary: data.cfo_summary,
        cfo_pic: data.cfo_pic,
        cfo_tanggal: data.cfo_tanggal,
        ceo_result: data.ceo_result,
        ceo_pic: data.ceo_pic,
        ceo_tanggal: data.ceo_tanggal,
        ceo_summary: data.ceo_summary,
        revert_cfo: data.revert_cfo,
        revert_cfo_summary: data.revert_cfo_summary,
        revert_ceo: data.revert_ceo,
        revert_ceo_summary: data.revert_ceo_summary,

    })
  } catch (err) {
    console.error(err)
  } finally {
    loading.value = false
  }
}

const fetchHistory = async () => {
  const res = await axios.get(`/inventory/purchase-order/${id}/history`)
  latestHistory.value = res.data.latest || null
  histories.value = res.data.history || []
}

const confirmDialog = ref(false)
const loadingSubmit = ref(false)

const submitApproval = () => {
  confirmDialog.value = true
}

const isSaving = ref(false)
const doSubmit = async () => {

//   loadingSubmit.value = true
  const confirm = await showConfirmAlert({
    title: 'Apakah yakin ingin melakukan approval PO?',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })
    if (!confirm.isConfirmed) return

    isSaving.value = true

    try {
      showLoadingAlert('Menyimpan data...', 'Mohon menunggu')

    let url = ''

    // CFO
    if (po.disposisi_po === 1) {
      url = `/inventory/purchase-order/${id}/approve-cfo`
    }

    // CEO
    if (po.disposisi_po === 2) {
      url = `/inventory/purchase-order/${id}/approve-ceo`
    }

    await axios.post(url, {
      decision: po.result,
      note: po.note,
    })

    // confirmDialog.value = false
    await showSuccessAlert({
      title: 'Berhasil',
      text: `Approve PO berhasil`,
      timer: 1800,
    })


    await fetchPO(id)

  } catch (err) {
    closeAlert()
    console.error(err)

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal menghapus vendor'),
    })
  } finally {
    closeAlert()
    isSaving.value = false

    // loadingSubmit.value = false
  }
}
const userRoles = ref<string[]>([])

const getProfile = async () => {
  try {
    const res = await axios.get('/auth/me')

    userRoles.value = res.data.data.role

    console.log('ROLE:', res.data)
  } catch (err) {
    console.error(err)
  }
}
const canApprove = (item: any) => {
  if (userRoles.value.includes('CFO') && item.disposisi_po === 1) return true
  if (userRoles.value.includes('CEO') && item.disposisi_po === 2) return true
  return false
}

const statusMap: Record<number, {
  text: string
  color: string
  icon: string
}> = {
  1: {
    text: 'Waiting CFO',
    color: 'warning',
    icon: 'tabler-clock',
  },

  2: {
    text: 'Waiting CEO',
    color: 'info',
    icon: 'tabler-user-check',
  },

  3: {
    text: 'Rejected CFO',
    color: 'error',
    icon: 'tabler-x',
  },

  4: {
    text: 'Approved',
    color: 'success',
    icon: 'tabler-check',
  },

  5: {
    text: 'Rejected CEO',
    color: 'error',
    icon: 'tabler-x',
  },
}

const noteLabel = computed(() => {

  if (po.result === '1') {
    return 'Catatan Approval'
  }

  if (po.result === '2') {
    return 'Catatan Reject'
  }

  return 'Catatan'
})
const formatMoney = (value: number | null): string => {
   if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}


const toNumber = (value: any): number => {
  if (value === null || value === undefined || value === '') return 0

  return Number(value) || 0
}

const isPriceChanged = computed(() => {
  return Number(po.is_price_changed) === 1 && latestHistory.value !== null
})

const previousPrice = computed(() => {
  return toNumber(latestHistory.value?.harga_tebus)
})

const currentPrice = computed(() => {
  return toNumber(po.harga_tebus)
})

const priceDiff = computed(() => {
  return currentPrice.value - previousPrice.value
})

const absolutePriceDiff = computed(() => {
  return Math.abs(priceDiff.value)
})

const priceDiffPercent = computed(() => {
  if (previousPrice.value === 0) return 0

  return (priceDiff.value / previousPrice.value) * 100
})

const absolutePriceDiffPercent = computed(() => {
  return Math.abs(priceDiffPercent.value)
})

const previousTotal = computed(() => {
  return toNumber(latestHistory.value?.total_order)
})

const currentTotal = computed(() => {
  return toNumber(po.total)
})

const totalDiff = computed(() => {
  return currentTotal.value - previousTotal.value
})

const absoluteTotalDiff = computed(() => {
  return Math.abs(totalDiff.value)
})

const totalDiffPercent = computed(() => {
  if (previousTotal.value === 0) return 0

  return (totalDiff.value / previousTotal.value) * 100
})

const absoluteTotalDiffPercent = computed(() => {
  return Math.abs(totalDiffPercent.value)
})

const priceChangeLabel = computed(() => {
  if (priceDiff.value > 0) return 'Naik'
  if (priceDiff.value < 0) return 'Turun'

  return 'Tidak Berubah'
})

const priceChangeColor = computed(() => {
  if (priceDiff.value > 0) return 'error'
  if (priceDiff.value < 0) return 'success'

  return 'grey'
})

const priceChangeIcon = computed(() => {
  if (priceDiff.value > 0) return 'tabler-trending-up'
  if (priceDiff.value < 0) return 'tabler-trending-down'

  return 'tabler-minus'
})

// ====== INIT ======
onMounted(() => {
  fetchPO(id)
  getProfile()
  fetchHistory()
})
</script>
<template>
  <section>
    <!-- HEADER -->
    <VCard class="mb-6" rounded="lg">
      <VCardText>
        <div class="d-flex justify-space-between align-center flex-wrap ga-4">
          <div>
            <div class="text-h5 font-weight-bold">
              Verifikasi PO Supplier
            </div>

          </div>

          <div class="d-flex align-center gap-3">
            <VChip
              size="large"
              variant="tonal"
              :color="statusMap[po.disposisi_po]?.color || 'grey'"
            >
              <VIcon
                start
                :icon="statusMap[po.disposisi_po]?.icon || 'tabler-clock'"
              />

              {{ statusMap[po.disposisi_po]?.text || 'Pending' }}
            </VChip>

            <VBtn
              variant="tonal"
              color="secondary"
              size="large"
              @click="router.back()"
            >
              <VIcon start icon="tabler-arrow-left" />
              Kembali
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- EXECUTIVE SUMMARY -->
    <VRow class="mb-6">
      <VCol cols="12" md="3">
        <VCard rounded="lg" variant="tonal" color="primary" height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-1">
              Nomor PO
            </div>

            <div class="text-h6 font-weight-bold">
              {{ po.nomor_po || '-' }}
            </div>

            <div class="text-caption mt-2">
              Tanggal: {{ po.tanggal_inven || '-' }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="3">
        <VCard rounded="lg" variant="tonal" color="info" height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-1">
              Vendor
            </div>

            <div class="text-body-1 font-weight-bold text-high-emphasis">
              {{ po.vendor || '-' }}
            </div>

            <div class="text-caption mt-2">
              {{ po.produk || '-' }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="3">
        <VCard rounded="lg" variant="tonal" color="warning" height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-1">
              Volume PO
            </div>

            <div class="text-h6 font-weight-bold">
              {{ formatMoney(po.volume_po) }} Liter
            </div>

            <div class="text-caption mt-2">
              BOL: {{ formatMoney(po.volume_bol) }} Liter
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="3">
        <VCard rounded="lg" variant="flat" color="success" height="100%">
          <VCardText>
            <div class="text-caption mb-1">
              Total Nilai PO
            </div>

            <div class="text-h5 font-weight-bold">
              Rp {{ formatMoney(po.total) }}
            </div>

            <div class="text-caption mt-2">
              Harga dasar: Rp {{ formatMoney(po.harga_tebus) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- PRICE CHANGE ALERT -->
    <VCard
    v-if="isPriceChanged"
    class="mb-6"
    rounded="lg"
    :color="priceChangeColor"
    variant="tonal"
    >
    <VCardText>
        <div class="d-flex justify-space-between align-center flex-wrap ga-4">
        <div>
            <div class="d-flex align-center ga-2 mb-2">
            <VIcon
                size="28"
                :icon="priceChangeIcon"
            />

            <div class="text-h6 font-weight-bold">
                Perubahan Harga Dasar
            </div>

            <VChip
                size="small"
                variant="flat"
                :color="priceChangeColor"
            >
                {{ priceChangeLabel }}
            </VChip>
            </div>

            <div class="text-body-2 text-medium-emphasis">
            Harga dasar berubah dari
            <strong>Rp {{ formatMoney(previousPrice) }}</strong>
            menjadi
            <strong>Rp {{ formatMoney(currentPrice) }}</strong>.
            </div>
        </div>

        <div class="text-right">
            <div class="text-caption text-medium-emphasis">
            Selisih Harga
            </div>

            <div class="text-h5 font-weight-bold">
            {{ priceDiff > 0 ? '+' : priceDiff < 0 ? '-' : '' }}
            Rp {{ formatMoney(absolutePriceDiff) }}
            </div>

            <div class="text-body-2">
            {{ priceDiff > 0 ? '+' : priceDiff < 0 ? '-' : '' }}
            {{ absolutePriceDiffPercent.toFixed(2) }}%
            </div>
        </div>
        </div>

        <VDivider class="my-5" />

        <VRow>
        <VCol cols="12" md="4">
            <VCard variant="flat" rounded="lg" class="pa-4">
            <div class="text-caption text-medium-emphasis mb-1">
                Harga Sebelumnya
            </div>

            <div class="text-h6 font-weight-bold">
                Rp {{ formatMoney(previousPrice) }}
            </div>
            </VCard>
        </VCol>

        <VCol cols="12" md="4">
            <VCard variant="flat" rounded="lg" class="pa-4">
            <div class="text-caption text-medium-emphasis mb-1">
                Harga Saat Ini
            </div>

            <div class="text-h6 font-weight-bold">
                Rp {{ formatMoney(currentPrice) }}
            </div>
            </VCard>
        </VCol>

        <VCol cols="12" md="4">
            <VCard variant="flat" rounded="lg" class="pa-4">
            <div class="text-caption text-medium-emphasis mb-1">
                Selisih Harga
            </div>

            <div
                class="text-h6 font-weight-bold"
                :class="priceDiff > 0 ? 'text-error' : priceDiff < 0 ? 'text-success' : ''"
            >
                {{ priceDiff > 0 ? '+' : priceDiff < 0 ? '-' : '' }}
                Rp {{ formatMoney(absolutePriceDiff) }}
            </div>

            <div
                class="text-caption"
                :class="priceDiff > 0 ? 'text-error' : priceDiff < 0 ? 'text-success' : ''"
            >
                {{ priceDiff > 0 ? '+' : priceDiff < 0 ? '-' : '' }}
                {{ absolutePriceDiffPercent.toFixed(2) }}%
            </div>
            </VCard>
        </VCol>
        </VRow>

        <VAlert
        class="mt-5"
        variant="flat"
        color="surface"
        border="start"
        >
        <div class="d-flex justify-space-between align-center flex-wrap ga-4">
            <div>
            <div class="font-weight-bold mb-1">
                Dampak ke Total PO
            </div>

            <div class="text-body-2 text-medium-emphasis">
                Total PO berubah dari
                <strong>Rp {{ formatMoney(previousTotal) }}</strong>
                menjadi
                <strong>Rp {{ formatMoney(currentTotal) }}</strong>.
            </div>
            </div>

            <div class="text-right">
            <div class="text-caption text-medium-emphasis">
                Selisih Total
            </div>

            <div
                class="text-h6 font-weight-bold"
                :class="totalDiff > 0 ? 'text-error' : totalDiff < 0 ? 'text-success' : ''"
            >
                {{ totalDiff > 0 ? '+' : totalDiff < 0 ? '-' : '' }}
                Rp {{ formatMoney(absoluteTotalDiff) }}
            </div>

            <div
                class="text-caption"
                :class="totalDiff > 0 ? 'text-error' : totalDiff < 0 ? 'text-success' : ''"
            >
                {{ totalDiff > 0 ? '+' : totalDiff < 0 ? '-' : '' }}
                {{ absoluteTotalDiffPercent.toFixed(2) }}%
            </div>
            </div>
        </div>
        </VAlert>
    </VCardText>
    </VCard>
    <!-- DECISION AREA -->
    <VRow class="mb-6">
      <!-- LEFT: PO SUMMARY -->
      <VCol cols="12" md="8">
        <VCard rounded="lg">
          <VCardTitle class="d-flex align-center ga-2">
            <VIcon color="primary" icon="tabler-file-invoice" />
            Ringkasan Purchase Order
          </VCardTitle>

          <VDivider />

          <VCardText>
            <VRow>
              <VCol cols="12" md="6">
                <div class="text-subtitle-2 text-medium-emphasis mb-3">
                 Detail Informasi
                </div>

                <div class="d-flex flex-column ga-3">
                  <!-- <div class="d-flex justify-space-between">
                    <span>Vendor</span>
                    <strong class="text-right">{{ po.vendor || '-' }}</strong>
                  </div> -->

                  <div class="d-flex justify-space-between">
                    <span>Produk</span>
                    <strong class="text-right">{{ po.produk || '-' }}</strong>
                  </div>

                  <div class="d-flex justify-space-between">
                    <span>Terminal</span>
                    <strong class="text-right">{{ po.terminal +' - ' + po.lokasi || '-' }}</strong>
                  </div>

                  <div class="d-flex justify-space-between">
                    <span>Kode Tax</span>
                    <strong>{{ po.kd_tax || '-' }}</strong>
                  </div>

                  <div class="d-flex justify-space-between">
                    <span>Terms</span>
                    <strong>
                      {{ po.terms || '-' }}
                      <template v-if="po.terms === 'NET'">
                         {{ po.terms_day || 0 }} hari
                      </template>
                    </strong>
                  </div>

                  <div
                    v-if="po.ongkos_angkut"
                    class="d-flex justify-space-between"
                  >
                    <span>Ongkos Angkut</span>
                    <strong>Rp {{ formatMoney(po.ongkos_angkut) }}</strong>
                  </div>

                  <div
                    v-if="po.kategori_plat"
                    class="d-flex justify-space-between"
                  >
                    <span>Kategori Plat</span>
                    <strong>{{ po.kategori_plat }}</strong>
                  </div>
                </div>
              </VCol>

              <VCol cols="12" md="6">
                <div class="text-subtitle-2 text-medium-emphasis mb-3">
                  Ringkasan Nilai
                </div>

                <div class="d-flex flex-column ga-3">
                  <div class="d-flex justify-space-between">
                    <span>Harga Dasar</span>
                    <strong>Rp {{ formatMoney(po.harga_tebus) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between">
                    <span>Subtotal</span>
                    <strong>Rp {{ formatMoney(po.subtotal) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between">
                    <span>PPN</span>
                    <strong>Rp {{ formatMoney(po.ppn) }}</strong>
                  </div>

                  <div
                    v-if="Number(po.pbbkb_po) !== 0"
                    class="d-flex justify-space-between"
                  >
                    <span>PBBKB</span>
                    <strong>Rp {{ formatMoney(po.pbbkb_po) }}</strong>
                  </div>

                  <div
                    v-if="Number(po.pph_22) !== 0"
                    class="d-flex justify-space-between"
                  >
                    <span>PPH 22</span>
                    <strong>Rp {{ formatMoney(po.pph_22) }}</strong>
                  </div>

                  <div
                    v-if="po.iuran_migas"
                    class="d-flex justify-space-between"
                  >
                    <span>Iuran Migas</span>
                    <strong>Rp {{ formatMoney(po.nominal_migas) }}</strong>
                  </div>

                  <VDivider />

                  <div class="d-flex justify-space-between align-center">
                    <span class="text-h6">Total</span>
                    <strong class="text-h6">
                      Rp {{ formatMoney(po.total) }}
                    </strong>
                  </div>
                </div>
              </VCol>
            </VRow>

            <VAlert
              class="mt-6"
              variant="tonal"
              color="secondary"
              border="start"
            >
              <div class="font-weight-bold mb-2">
                Catatan PO
              </div>

              <div>
                {{ po.catatan_po || '-' }}
              </div>

              <VDivider class="my-3" />

              <div class="font-weight-bold mb-2">
                Internal Notes
              </div>

              <div>
                {{ po.internal_notes || '-' }}
              </div>
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <!-- RIGHT: APPROVAL ACTION -->
      <VCol cols="12" md="4">
        <VCard
          rounded="lg"
          :color="
            po.disposisi_po === 1 || po.disposisi_po === 2
              ? 'warning'
              : 'success'
          "
          variant="tonal"
          class="mb-4"
        >
          <VCardText>
            <div class="d-flex align-center gap-3">
              <VAvatar
                :color="
                  po.disposisi_po === 1 || po.disposisi_po === 2
                    ? 'warning'
                    : 'success'
                "
                variant="flat"
                size="small"
              >
                <VIcon
                  :icon="
                    po.disposisi_po === 1 || po.disposisi_po === 2
                      ? 'tabler-clock'
                      : 'tabler-check'
                  "
                />
              </VAvatar>

              <div>
                <div class="text-subtitle-1 font-weight-bold">
                  Status Approval
                </div>

                <div class="text-body-2">
                  {{ statusMap[po.disposisi_po]?.text || 'Pending' }}
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VCard
          v-if="
            (
              (userRoles.includes('CFO') || userRoles.includes('Chief Financial Officer'))
              && po.disposisi_po === 1
            ) ||
            (
              (userRoles.includes('CEO') || userRoles.includes('Chief Executive Officer'))
              && po.disposisi_po === 2
            )
          "
          rounded="lg"
        >
          <VCardTitle class="d-flex align-center ga-2">
            <VIcon color="primary" icon="tabler-checkup-list" />

            {{
              po.disposisi_po === 1
                ? 'Approval CFO'
                : 'Approval CEO'
            }}
          </VCardTitle>

          <VDivider />

          <VCardText>
            <VRadioGroup
              v-model="po.result"
              inline
            >
              <VRadio
                label="Approve"
                value="1"
                color="success"
              />

              <VRadio
                label="Reject"
                value="2"
                color="error"
              />
            </VRadioGroup>

            <VTextarea
              v-model="po.note"
              :label="noteLabel"
              rows="4"
              class="mt-3"
              placeholder="Tambahkan catatan approval/reject..."
            />

            <VBtn
              block
              size="large"
              color="primary"
              class="mt-4"
              :disabled="!po.result || isSaving"
              :loading="isSaving"
              @click="doSubmit"
            >
              Submit Approval
            </VBtn>
          </VCardText>
        </VCard>

        <VCard
          v-else
          rounded="lg"
          variant="outlined"
        >
          <VCardText>
            <div class="d-flex align-center ga-3">
              <VIcon color="grey" icon="tabler-lock" />

              <div>
                <div class="font-weight-bold">
                  Tidak ada action approval
                </div>

                <div class="text-body-2 text-medium-emphasis">
                  PO ini belum berada di tahap approval Anda atau sudah selesai.
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- APPROVAL TIMELINE & HISTORY -->
    <VRow>
      <!-- TIMELINE -->
      <VCol cols="12" md="4">
        <VCard rounded="lg">
          <VCardTitle class="d-flex align-center ga-2">
            <VIcon color="primary" icon="tabler-timeline" />
            Approval Timeline
          </VCardTitle>

          <VDivider />

          <VCardText>
            <VTimeline
              side="end"
              density="compact"
            >
              <VTimelineItem
                dot-color="primary"
                icon="tabler-send"
              >
                <div class="font-weight-bold">
                  Pengajuan
                </div>

                <div class="text-caption text-medium-emphasis">
                  User submit PO
                </div>
              </VTimelineItem>

              <VTimelineItem
                :dot-color="
                  isWaitingCFO
                    ? 'warning'
                    : po.cfo_result === 1
                      ? 'success'
                      : po.cfo_result === 2
                        ? 'error'
                        : 'grey'
                "
                :icon="
                  isWaitingCFO
                    ? 'tabler-clock'
                    : po.cfo_result === 1
                      ? 'tabler-check'
                      : po.cfo_result === 2
                        ? 'tabler-x'
                        : 'tabler-minus'
                "
              >
                <div class="d-flex justify-space-between align-center">
                  <div>
                    <div class="font-weight-bold">
                      CFO
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      <template v-if="isWaitingCFO">
                        Waiting Approval
                      </template>

                      <template v-else-if="isApprovedCFO">
                        Approved by {{ po.cfo_pic }} - {{ po.cfo_tanggal }}
                      </template>

                      <template v-else-if="isRejectedCFO">
                        Rejected by {{ po.cfo_pic }} - {{ po.cfo_tanggal }}
                      </template>

                      <template v-else>
                        Pending
                      </template>
                    </div>
                  </div>

                  <VChip
                    size="small"
                    variant="tonal"
                    :color="
                      isWaitingCFO
                        ? 'warning'
                        : isApprovedCFO
                          ? 'success'
                          : isRejectedCFO
                            ? 'error'
                            : 'grey'
                    "
                  >
                    {{
                      isWaitingCFO
                        ? 'Waiting'
                        : isApprovedCFO
                          ? 'Approved'
                          : isRejectedCFO
                            ? 'Rejected'
                            : 'Pending'
                    }}
                  </VChip>
                </div>

                <VAlert
                  v-if="po.cfo_result == 1 || po.revert_cfo == 1"
                  class="mt-3"
                  density="compact"
                  variant="tonal"
                >
                  <template v-if="po.revert_cfo == 1">
                    <strong>Catatan:</strong> {{ po.revert_cfo_summary || '-' }}
                  </template>

                  <template v-else>
                    <strong>Catatan:</strong> {{ po.cfo_summary || '-' }}
                  </template>
                </VAlert>
              </VTimelineItem>

              <VTimelineItem
                :dot-color="
                  isWaitingCEO
                    ? 'warning'
                    : po.ceo_result === 1
                      ? 'success'
                      : po.ceo_result === 2
                        ? 'error'
                        : 'grey'
                "
                :icon="
                  isWaitingCEO
                    ? 'tabler-clock'
                    : po.ceo_result === 1
                      ? 'tabler-check'
                      : po.ceo_result === 2
                        ? 'tabler-x'
                        : 'tabler-minus'
                "
              >
                <div class="d-flex justify-space-between align-center">
                  <div>
                    <div class="font-weight-bold">
                      CEO
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      <template v-if="isWaitingCEO">
                        Waiting Approval
                      </template>

                      <template v-else-if="po.ceo_result === 1">
                        Approved by {{ po.ceo_pic }} - {{ po.ceo_tanggal }}
                      </template>

                      <template v-else-if="po.ceo_result === 2">
                        Rejected by {{ po.ceo_pic }} - {{ po.ceo_tanggal }}
                      </template>

                      <template v-else>
                        Menunggu CFO
                      </template>
                    </div>
                  </div>

                  <VChip
                    size="small"
                    variant="tonal"
                    :color="
                      isWaitingCEO
                        ? 'warning'
                        : po.ceo_result === 1
                          ? 'success'
                          : po.ceo_result === 2
                            ? 'error'
                            : 'grey'
                    "
                  >
                    {{
                      isWaitingCEO
                        ? 'Waiting'
                        : po.ceo_result === 1
                          ? 'Approved'
                          : po.ceo_result === 2
                            ? 'Rejected'
                            : 'Pending'
                    }}
                  </VChip>
                </div>

                <VAlert
                  v-if="po.ceo_result == 1 || po.revert_ceo == 1"
                  class="mt-3"
                  density="compact"
                  variant="tonal"
                  :color="po.revert_ceo == 1 ? 'warning' : 'success'"
                >
                  <template v-if="po.revert_ceo == 1">
                    <strong>Catatan:</strong> {{ po.revert_ceo_summary || '-' }}
                  </template>

                  <template v-else>
                    <strong>Catatan:</strong> {{ po.ceo_summary || '-' }}
                  </template>
                </VAlert>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>

      <!-- HISTORY -->
      <!-- HISTORY -->
    <VCol cols="12" md="8">
    <VCard rounded="lg">
        <VCardTitle class="d-flex align-center ga-2">
        <VIcon color="primary" icon="tabler-history" />
        Riwayat Perubahan PO
        </VCardTitle>

        <VDivider />

        <VCardText>
        <template v-if="histories.length > 0">
            <VExpansionPanels variant="accordion">
            <VExpansionPanel
                v-for="(h, index) in histories"
                :key="index"
            >
                <VExpansionPanelTitle>
                <div class="d-flex justify-space-between align-center w-100 me-4 flex-wrap ga-3">
                    <div>
                    <div class="font-weight-bold">
                        Riwayat Pengajuan ke-{{ index + 1 }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                        {{ h.nomor_po || '-' }}
                    </div>
                    </div>

                    <div class="d-flex align-center ga-2">
                    <VChip size="small" variant="tonal" color="primary">
                        {{ h.lastupdate_time || h.tanggal_inven || '-' }}
                    </VChip>

                    <VChip size="small" variant="flat" color="success">
                        Rp {{ formatMoney(h.total_order) }}
                    </VChip>
                    </div>
                </div>
                </VExpansionPanelTitle>

                <VExpansionPanelText>
                <VRow>
                    <!-- INFORMASI PO -->
                    <VCol cols="12" md="6">
                    <VCard variant="tonal" color="primary" rounded="lg">
                        <VCardTitle class="text-subtitle-1 font-weight-bold">
                        <VIcon start icon="tabler-file-invoice" />
                        Informasi PO
                        </VCardTitle>

                        <VDivider />

                        <VCardText>
                        <div class="d-flex flex-column ga-3">
                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Nomor PO
                            </span>

                            <strong class="text-right">
                                {{ h.nomor_po || '-' }}
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Tanggal PO
                            </span>

                            <strong class="text-right">
                                {{ h.tanggal_inven || '-' }}
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Produk
                            </span>

                            <strong class="text-right">
                                {{ h.jenis_produk || '-' }}
                                <template v-if="h.merk_dagang">
                                - {{ h.merk_dagang }}
                                </template>
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Terminal
                            </span>

                            <strong class="text-right">
                                {{ h.nama_terminal || '-' }}

                                <template v-if="h.tanki_terminal">
                                ({{ h.tanki_terminal }})
                                </template>

                                <template v-if="h.lokasi_terminal">
                                - {{ h.lokasi_terminal }}
                                </template>
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Vendor
                            </span>

                            <strong class="text-right">
                                {{ h.nama_vendor || '-' }}
                            </strong>
                            </div>
                        </div>
                        </VCardText>
                    </VCard>
                    </VCol>

                    <!-- PENGIRIMAN & TERMS -->
                    <VCol cols="12" md="6">
                    <VCard variant="tonal" color="info" rounded="lg">
                        <VCardTitle class="text-subtitle-1 font-weight-bold">
                        <VIcon start icon="tabler-truck-delivery" />
                        Pengiriman & Terms
                        </VCardTitle>

                        <VDivider />

                        <VCardText>
                        <div class="d-flex flex-column ga-3">
                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Jenis Kirim
                            </span>

                            <strong>
                                {{ Number(h.jenis_kirim) === 1 ? 'Truck' : 'Ship' }}
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Terms
                            </span>

                            <strong>
                                {{ h.terms || '-' }}

                                <template v-if="h.terms === 'NET'">
                                ({{ h.terms_day || 0 }} Hari)
                                </template>
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Volume PO
                            </span>

                            <strong>
                                {{ formatMoney(h.volume_po) }} Liter
                            </strong>
                            </div>

                            <div class="d-flex justify-space-between ga-4">
                            <span class="text-medium-emphasis">
                                Jenis Harga
                            </span>

                            <VChip
                                size="small"
                                variant="tonal"
                                :color="Number(h.jenis_harga) === 1 ? 'success' : 'warning'"
                            >
                                {{ Number(h.jenis_harga) === 1 ? 'Final' : 'Sementara' }}
                            </VChip>
                            </div>
                        </div>
                        </VCardText>
                    </VCard>
                    </VCol>

                    <!-- NILAI PO -->
                    <VCol cols="12">
                    <VCard variant="outlined" rounded="lg">
                        <VCardTitle class="text-subtitle-1 font-weight-bold">
                        <VIcon start color="success" icon="tabler-cash" />
                        Ringkasan Nilai PO
                        </VCardTitle>

                        <VDivider />

                        <VCardText>
                        <VRow>
                            <VCol cols="12" md="4">
                            <VCard variant="tonal" color="warning" rounded="lg">
                                <VCardText>
                                <div class="text-caption text-medium-emphasis mb-1">
                                    Harga Dasar
                                </div>

                                <div class="text-h6 font-weight-bold">
                                    Rp {{ formatMoney(h.harga_tebus) }}
                                </div>
                                </VCardText>
                            </VCard>
                            </VCol>

                            <VCol cols="12" md="4">
                            <VCard variant="tonal" color="primary" rounded="lg">
                                <VCardText>
                                <div class="text-caption text-medium-emphasis mb-1">
                                    Subtotal
                                </div>

                                <div class="text-h6 font-weight-bold">
                                    Rp {{ formatMoney(h.subtotal) }}
                                </div>
                                </VCardText>
                            </VCard>
                            </VCol>

                            <VCol cols="12" md="4">
                            <VCard variant="flat" color="success" rounded="lg">
                                <VCardText>
                                <div class="text-caption mb-1">
                                    Total Order
                                </div>

                                <div class="text-h6 font-weight-bold">
                                    Rp {{ formatMoney(h.total_order) }}
                                </div>
                                </VCardText>
                            </VCard>
                            </VCol>
                        </VRow>

                        <VDivider class="my-4" />

                        <VRow>
                            <VCol cols="12" md="6">
                            <div class="d-flex flex-column ga-3">
                                <div class="d-flex justify-space-between">
                                <span>DPP 11/12</span>

                                <strong>
                                    Rp {{ formatMoney(h.dpp_11_12) }}
                                </strong>
                                </div>

                                <div class="d-flex justify-space-between">
                                <span>PPN 12%</span>

                                <strong>
                                    Rp {{ formatMoney(h.ppn_12) }}
                                </strong>
                                </div>

                                <div class="d-flex justify-space-between">
                                <span>PPH 22</span>

                                <strong>
                                    Rp {{ formatMoney(h.pph_22) }}
                                </strong>
                                </div>
                            </div>
                            </VCol>

                            <VCol cols="12" md="6">
                            <div class="d-flex flex-column ga-3">
                                <div class="d-flex justify-space-between">
                                <span>PBBKB</span>

                                <strong>
                                    {{ h.nilai_pbbkb ?? 0 }}%
                                </strong>
                                </div>

                                <div class="d-flex justify-space-between">
                                <span>Nominal PBBKB</span>

                                <strong>
                                    Rp {{ formatMoney(h.pbbkb_po) }}
                                </strong>
                                </div>

                                <div class="d-flex justify-space-between">
                                <span>Iuran Migas</span>

                                <strong>
                                    Rp {{ formatMoney(h.nominal_migas) }}
                                </strong>
                                </div>
                            </div>
                            </VCol>
                        </VRow>
                        </VCardText>
                    </VCard>
                    </VCol>

                    <!-- CATATAN -->
                    <VCol cols="12">
                    <VAlert
                        variant="tonal"
                        color="secondary"
                        border="start"
                    >
                        <div class="font-weight-bold mb-2">
                        Catatan PO
                        </div>

                        <div style="white-space: pre-line;">
                        {{ h.keterangan || '-' }}
                        </div>

                        <template v-if="h.keterangan_resubmission">
                        <VDivider class="my-3" />

                        <div class="font-weight-bold mb-2">
                            Catatan Pengajuan Ulang PO
                        </div>

                        <div style="white-space: pre-line;">
                            {{ h.keterangan_resubmission }}
                        </div>
                        </template>
                    </VAlert>
                    </VCol>

                    <!-- UPDATED INFO -->
                    <VCol cols="12">
                    <div class="d-flex justify-space-between align-center flex-wrap ga-3 text-caption text-medium-emphasis">
                        <div>
                        Updated by:
                        <strong>{{ h.lastupdate_by || '-' }}</strong>
                        </div>

                        <div>
                        Tanggal update:
                        <strong>{{ h.lastupdate_time || '-' }}</strong>
                        </div>
                    </div>
                    </VCol>
                </VRow>
                </VExpansionPanelText>
            </VExpansionPanel>
            </VExpansionPanels>
        </template>

        <VAlert
            v-else
            type="info"
            variant="tonal"
        >
            Belum ada riwayat perubahan PO.
        </VAlert>
        </VCardText>
    </VCard>
    </VCol>
    </VRow>

    <!-- CONFIRMATION DIALOG -->
    <VDialog
      v-model="confirmDialog"
      max-width="420"
    >
      <VCard rounded="lg">
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon color="warning" icon="tabler-alert-circle" />
          Konfirmasi Approval
        </VCardTitle>

        <VDivider />

        <VCardText>
          {{
            po.result === '1'
              ? 'Apakah Anda yakin ingin approve PO ini?'
              : 'Apakah Anda yakin ingin reject PO ini?'
          }}
        </VCardText>

        <VCardActions>
          <VSpacer />

          <VBtn
            variant="text"
            @click="confirmDialog = false"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            variant="flat"
            @click="doSubmit"
          >
            Ya, Lanjut
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- LOADING DIALOG -->
    <VDialog
      v-model="loadingSubmit"
      width="300"
      persistent
    >
      <VCard
        color="primary"
        width="300"
      >
        <VCardText class="pt-3 text-white">
          Menyimpan Data

          <VProgressLinear
            indeterminate
            class="mt-4"
            color="#fff"
          />
        </VCardText>
      </VCard>
    </VDialog>
  </section>
</template>