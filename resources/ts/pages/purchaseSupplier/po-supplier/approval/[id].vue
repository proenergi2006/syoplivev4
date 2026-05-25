<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from '@axios'
import { useRoute } from 'vue-router'

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
  terminal: '',
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

  harga_tebus: 0,
  subtotal: 0,
  ppn: 0,
  total: 0,
})

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

const isApprovedCFO = computed(() => po.disposisi_po === 2)

const isRejectedCFO = computed(() => po.disposisi_po === 3)
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
        produk: data.produk.jenis_produk,
        terminal: data.terminal.nama_terminal,

        catatan_po: data.keterangan,
        internal_notes: data.internal_notes,
        harga_tebus: data.harga_tebus,
        subtotal: data.subtotal,
        ppn: data.ppn_12,
        total: data.total_order,
        disposisi_po: data.disposisi_po,
        cfo_result: data.cfo_result,
        cfo_summary: data.cfo_summary,
        cfo_pic: data.cfo_pic,
        cfo_tanggal: data.cfo_tanggal,
        ceo_result: data.ceo_result,
        ceo_pic: data.ceo_pic,
        ceo_tanggal: data.ceo_tanggal,
    })
  } catch (err) {
    console.error(err)
  } finally {
    loading.value = false
  }
}

const confirmDialog = ref(false)
const loadingSubmit = ref(false)

const submitApproval = () => {
  confirmDialog.value = true
}

const doSubmit = async () => {

  loadingSubmit.value = true

  try {

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

    confirmDialog.value = false

    alert('Approval berhasil')

    await fetchPO(id)

  } catch (err) {

    console.error(err)
  } finally {
    loadingSubmit.value = false
  }
}

// const noteLabel = computed(() => {
//   return po.result === '1'
//     ? 'Catatan Aprove'
//     : 'CEO Summary'
// })

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



// ====== INIT ======
onMounted(() => {
  fetchPO(id)
})
</script>
<template>
    <section>
         <VCard class="mb-6" rounded="md">

            <VCardText>
                <div class="d-flex justify-space-between align-center flex-wrap ga-3">
                
                <div>
                    <h2 class="text-h5 font-weight-bold">
                    Verifikasi PO Supplier
                    </h2>
                </div>

                <VChip color="primary" variant="tonal">
                    Status: Review
                </VChip>

                </div>
            </VCardText>

            <VDivider />

            <VCardText>
                <div class="d-grid flex-column ga-5">

                    <div class="d-flex">
                        <strong style="width:140px">Nomor PO</strong>:  {{ po.nomor_po || '-' }}
                    </div>

                    <div class="d-flex">
                        <strong style="width:140px">Tanggal</strong>: {{ po.tanggal_inven || '-' }}
                    </div>

                    <div class="d-flex">
                        <strong style="width:140px">Volume PO</strong>: {{ formatMoney(po.volume_po) || '-' }} Liter
                    </div>

                    <div class="d-flex">
                        <strong style="width:140px">Volume BOL</strong>: {{ formatMoney(po.volume_bol) || '-' }} Liter
                    </div>

                </div>
                <VRow class="mt-2">

                    <!-- DATA SAAT INI -->
                    <VCol cols="12" md="6">
                    <VCard variant="tonal" color="primary" rounded="md">

                    <VCardTitle color="primary" class="font-weight-bold">
                        Data Saat Ini
                    </VCardTitle>

                    <VDivider />

                    <VCardText class="pt-4">

                        <div class="d-flex flex-column ga-3">

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Vendor</span>
                            <strong> {{ po.vendor || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Produk</span>
                            <strong> {{ po.produk || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Terminal</span>
                            <strong> {{ po.terminal || '-' }}</strong>
                        </div>

                        <VDivider class="my-2" />

                        <div class="d-flex justify-space-between">
                            <span>Harga Dasar</span>
                            <strong>{{ formatMoney(po.harga_tebus) || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span>Subtotal</span>
                            <strong>{{ formatMoney(po.subtotal) || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span>PPN</span>
                            <strong>{{ formatMoney(po.ppn) || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between text-h6">
                            <span>Total</span>
                            <strong>{{ formatMoney(po.total) || '-' }}</strong>
                        </div>
                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Catatan</span>
                            <strong>{{ po.catatan_po || '-' }}</strong>
                        </div>
                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Internal Notes</span>
                            <strong>{{ po.internal_notes || '-' }}</strong>
                        </div>

                        </div>

                    </VCardText>

                    </VCard>
                </VCol>

                <!-- PERUBAHAN TERAKHIR -->
                <VCol cols="12" md="6">
                    <VCard rounded="md">

                    <VCardTitle class="d-flex justify-space-between align-center">
                        <span class="text-subtitle-1 font-weight-bold">
                        Perubahan Terakhir
                        </span>

                        <VChip size="small" color="warning" variant="tonal">
                        Updated
                        </VChip>
                    </VCardTitle>

                    <VDivider />

                    <VCardText class="pt-4">

                        <div class="d-flex flex-column ga-3">

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Vendor</span>
                            <strong>-</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Produk</span>
                            <strong>-</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Terminal</span>
                            <strong>-</strong>
                        </div>

                        <VDivider class="my-2" />

                        <!-- PRICE CHANGE BLOCK -->
                        <div class="pa-3 rounded bg-grey-lighten-4">

                            <div class="d-flex justify-space-between">
                            <span>Harga</span>

                            <div class="d-flex align-center ga-2">
                                <span class="text-red">-</span>
                                <VIcon size="16">tabler-arrow-right</VIcon>
                                <span class="text-success font-weight-bold">-</span>
                            </div>
                            </div>

                        </div>

                        <div class="pa-3 rounded bg-grey-lighten-4">

                            <div class="d-flex justify-space-between">
                            <span>Total</span>

                            <div class="d-flex align-center ga-2">
                                <span class="text-red">-</span>
                                <VIcon size="16">tabler-arrow-right</VIcon>
                                <span class="text-success font-weight-bold">-</span>
                            </div>
                            </div>

                        </div>

                        </div>

                    </VCardText>

                    </VCard>
                </VCol>

                </VRow>
            </VCardText>
        

        </VCard>
            <!-- 2 column comparison -->
     
        
            
        <VRow>
           <!-- TIMELINE -->
            <VCol cols="12" md="6">

                <VCard>

                    <VCardTitle>
                    Approval Timeline
                    </VCardTitle>

                    <VDivider />

                    <VCardText>

                    <VTimeline
                        side="end"
                        density="compact"
                    >

                        <!-- SUBMIT -->
                        <VTimelineItem
                        dot-color="primary"
                        icon="tabler-send"
                        >
                        <div class="font-weight-bold">
                            Pengajuan
                        </div>

                        <div class="text-caption">
                            User submit PO
                        </div>
                        </VTimelineItem>

                        <!-- CFO -->
                        <VTimelineItem
                        :dot-color="
                            isWaitingCFO
                            ? 'warning'
                            : po.disposisi_po === 2
                                ? 'success'
                                : po.disposisi_po === 3
                                ? 'error'
                                : 'grey'
                        "
                        :icon="
                            isWaitingCFO
                            ? 'tabler-clock'
                            :  po.disposisi_po === 2
                                ? 'tabler-check'
                                :  po.disposisi_po === 3
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
                            :color="
                                isWaitingCFO
                                ? 'warning'
                                : po.disposisi_po === 2
                                    ? 'success'
                                    : po.disposisi_po === 3
                                    ? 'error'
                                    : 'grey'
                            "
                            variant="tonal"
                            size="small"
                            >
                            {{
                                isWaitingCFO
                                ? 'Waiting'
                                : po.disposisi_po === 2
                                    ? 'Approved'
                                    : po.disposisi_po === 3
                                    ? 'Rejected'
                                    : 'Pending'
                            }}
                            </VChip>

                        </div>

                        <div
                            v-if="po.cfo_summary"
                        class="text-body-2 mt-2 border rounded pa-4 mb-2"
                        >
                        Catatan CFO : {{ po.cfo_summary }}
                        </div>
    <!-- 
                        <div
                            v-if="po.cfo_tanggal"
                            class="text-caption"
                        >
                        
                        </div> -->

                        </VTimelineItem>

                        <!-- CEO -->
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
                                Approved
                                </template>

                                <template v-else-if="po.ceo_result === 2">
                                Rejected
                                </template>

                                <template v-else>
                                Menunggu CFO
                                </template>

                            </div>

                            </div>

                            <VChip
                            :color="
                                isWaitingCEO
                                ? 'warning'
                                : po.ceo_result === 1
                                    ? 'success'
                                    : po.ceo_result === 2
                                    ? 'error'
                                    : 'grey'
                            "
                            variant="tonal"
                            size="small"
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

                        <div
                            v-if="po.ceo_pic"
                            class="text-caption mt-2"
                        >
                            By {{ po.ceo_pic }}
                        </div>

                        <div
                            v-if="po.ceo_tanggal"
                            class="text-caption"
                        >
                            {{ po.ceo_tanggal }}
                        </div>

                        </VTimelineItem>

                    </VTimeline>

                    </VCardText>

                </VCard>

            </VCol>
         

            <!-- FORM APPROVAL -->
            <VCol cols="12" md="6">

            <VCard
                v-if="po.disposisi_po === 1 || po.disposisi_po === 2"
            >

                <VCardTitle>
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
                    />

                    <VRadio
                    label="Reject"
                    value="2"
                    />
                </VRadioGroup>

                <VTextarea
                    v-model="po.note"
                    :label="noteLabel"
                    rows="3"
                    class="mt-3"
                />

                <VBtn
                    color="primary"
                    class="mt-4"
                    @click="submitApproval"
                >
                    Submit Approval
                </VBtn>

                </VCardText>

            </VCard>

            </VCol>
        </VRow>
           
    <VDialog v-model="confirmDialog" max-width="400">
        <VCard>
            <VCardTitle>
            Konfirmasi
            </VCardTitle>

            <VCardText>
            {{
                po.result === '1'
                ? 'apakah yakin ingin Approve PO ini?'
                : 'Apakah yakin ingin Reject PO ini?'
            }}
            </VCardText>

            <VCardActions>
            <VSpacer />

            <VBtn variant="text" @click="confirmDialog = false">
                Batal
            </VBtn>

            <VBtn color="primary" variant="flat" @click="doSubmit">
                Ya, Lanjut
            </VBtn>
            </VCardActions>
        </VCard>
    </VDialog>
    <VDialog
    v-model="loadingSubmit"
    width="300"
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
    <VBtn
        variant="tonal" color="secondary" size="large"  @click="router.back()"
        class="mt-2"
    >
        Kembali
    </VBtn>
    </section>
</template>