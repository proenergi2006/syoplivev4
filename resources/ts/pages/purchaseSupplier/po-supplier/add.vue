<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@axios'
import { debounce } from 'lodash-es'
import {
  onlyNumberKeypress,
} from '@/utils/textFormatter'
import { reactive } from 'vue'
import { VForm } from 'vuetify/components/VForm'
import { useRoute } from 'vue-router'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'


const dialogSubmit = ref(false)
const route = useRoute()
const router = useRouter()


const id = computed(() => route.query.id)
const mode = computed(() => route.query.id ? 'edit' : 'add')
// console.log( route.query.id)
// const mode = computed(() => {
//   return route.params.id_master ? 'edit' : 'add'
// })


const step = ref('form') // form | review

const form = reactive({
 
  nomor_po: null,
  id_accurate: 0,
  vendor: null,
  terminal: null,
  produk: null,
  terms: '',
  terms_day: 0,
  kd_tax: '',
  volume_po: 0,
  tanggal_inven:'',
  jenis_harga: 0,
  jenis_kirim: 0,

  harga_tebus: 0,
  kategori_oa: 1,
  jenis_oa: 0,

  //item OA
  kode_item_oa: null,
  keterangan_item_oa: '',
  alokasi_item_oa: false,

  //kode Item
  kode_item: null,
  keterangan_item :'',
  alokasi_item :'',

  ongkos_angkut: 0,
  kategori_plat: '',

  //biaya oa
  biaya_oa: null,
  keterangan_biaya_oa: '',
  alokasi_biaya_oa: false,
  total_biaya_oa: 0,

  //biaya lain
  biaya_lain_oa: null,
  ket_biaya_lain_oa: '',
  alokasi_biaya_oa_lain: false,
  jumlah_biaya: 0,

  //biaya pph
  pph22: null,
  biaya_pph22: null,
  keterangan_pph22: '',
  alokasi_pph22: false,

  //biaya pbbkb
  pbbkb: 0,
  biaya_pbbkb: null,
  keterangan_pbbkb: '',
  alokasi_pbbkb: false,

  //biaya migas
  iuran_migas: false,
  nominal_iuran: 0,
  biaya_migas: null,
  keterangan_migas: '',
  alokasi_migas: false,

  total_order: 0,
  catatan_po: '',
  internal_notes: '',
  catatan_resubmit:'',

  // approval
  disposisi_po : 0,
  revert_cfo:0,
  revert_ceo:0,
  revert_cfo_summary:'',
  revert_ceo_summary:'',
})
const produkAccList = ref<any[]>([])
const akunAccList = ref<any[]>([])
const produkList = ref<any[]>([])
const vendorList = ref<any[]>([])
const terminalList = ref<any[]>([])
let controller: AbortController | null = null
const safe =  (val: any) => Number(val) || 0
const val = ''

// const showBiayaOA = ref(false)
// const showKodeItemOA = ref(false)
// const showKodeAkunOA = ref(false)
const showpph = computed(() => form.kd_tax === 'EC')
const showpbbkb = computed(() => Number(form.pbbkb) > 0)
const showNetInput = ref(false)
const showMigas = computed(() => form.iuran_migas)
const totalHD = computed(() => safe(form.harga_tebus) * safe(form.volume_po))
const nominalMigas = computed(() => {
  if (!showMigas.value) return 0

  return  Math.round(((form.harga_tebus * 0.25) / 100) * form.volume_po)
})
const oaValue = computed(() => {
  if (!form.ongkos_angkut) return 0

  return (form.ongkos_angkut)* form.volume_po
})
const subtotal = computed(() => {
  return totalHD.value +
    (form.kategori_oa === 2 ? oaValue.value : 0) +
    (nominalMigas.value ? nominalMigas.value : 0)
})
const subtotalPPN = computed(() => {
  return totalHD.value +
    (form.kategori_plat === 'Hitam'
      ? (form.kategori_oa === 2 ? oaValue.value : 0)
      : 0
    ) 
})
const dpp = computed(() => {
  return safe(subtotalPPN.value)* (11/12)
})

const ppn = computed(() => {
  return safe(dpp.value)* (12/100)
})

const pph22 = computed(() => {
  if (form.kd_tax !== 'EC') return 0

  return totalHD.value * (0.3 / 100)
})

const pbbkbval = computed(() => {
  return totalHD.value* (form.pbbkb/100)
})
const totalPO = computed(() => {
  return safe(subtotal.value)+safe(ppn.value)+safe(pph22.value)+safe(pbbkbval.value)
})

watch(
  oaValue,
  value => {
    form.total_biaya_oa = value
  },
  { immediate: true },
)
const isSaving = ref(false)
//API AOL Produk
const page = ref(1)
const loading = ref(false)
const pageLoading = ref(false)
const hasMore = ref(true)
const search = ref('')

const getAccProduk = async (
  keyword = '',
  reset = false,
) => {
  loading.value = true

  try {
    const res = await axios.get('/accurate/products', {
      params: {
        q: keyword,
        page: page.value,
      },
    })

    const items = res.data.data || []
    // console.log(res)
    if (reset) {
      produkAccList.value = items
    } else {
      produkAccList.value.push(...items)
    }

    page.value++
  } finally {
    loading.value = false
  }
}

//API AOL AKUN

const pageAkun = ref(1)
const loadingAkun = ref(false)
const hasMoreAkun = ref(true)
const searchAkun = ref('')
const getAccAkun = async (
  keyword = '',
  reset = false,
) => {
  loadingAkun.value = true

  try {
    
      const res = await axios.get('/accurate/accounts', {
        params: {
          q: keyword,
          page: pageAkun.value,
        },
      })

      const items = res.data.data || []

      if (items.length === 0) {
        hasMoreAkun.value = false
      }

      if (reset) {
        akunAccList.value = items
      } else {
        akunAccList.value.push(...items)
      }

      pageAkun.value++
  } finally {
   loadingAkun.value = false
  }
}

const onSearchAkun = debounce((val: string) => {
  searchAkun.value = val

  pageAkun.value = 1
  hasMoreAkun.value = true

  getAccAkun(val, true)
}, 300)

const getProduk = async () => {
  const res = await axios.get('/produk')
  produkList.value = res.data.map((p: any) => ({
    id: p.id,
    label: `${p.merk_dagang} - ${p.jenis_produk}`,
  }))
}

const getTerminal = async () => {
  const res = await axios.get('/terminal')
  terminalList.value = res.data.map((p: any) => ({
    id: p.id,
    nama_terminal: p.nama_terminal,
    lokasi_terminal: p.lokasi_terminal,
  }))
}
const getVendor = async () => {
  const res = await axios.get('/master/vendor/dropdown-select')
  vendorList.value = res.data.data.map((p: any) => ({
    id: p.id,
    nama_vendor: p.nama_vendor,
  }))
}

const onScroll = (e: any) => {
  const el = e.target

  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
    if (hasMore.value) {
      getAccProduk()
    }
  }
}


// setTimeout(() => {
//   const el = document.querySelector('.v-overlay__content')

//   if (!el) return

//   el.addEventListener('scroll', () => {
//     if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
//       if (hasMore.value) {
//         getAccProduk()
//       }
//     }
//   })
// }, 300)

  // EDIT
  const fetchPO = async (id: any) => {
  try {
    const res = await axios.get(`/inventory/purchase-order/${id.value}`)

    const data = res.data

    Object.assign(form, {
      id_accurate: data.id_accurate,
      tanggal_inven: data.tanggal_inven,
      nomor_po: data.nomor_po,
      produk: data.id_produk,
      kode_item: data.kode_item,
      vendor: data.id_vendor,
      jenis_harga: Number(data.jenis_harga),
      jenis_kirim: data.jenis_kirim,
      terminal: data.id_terminal,
      kategori_plat: data.kategori_plat,
      kd_tax: data.kd_tax,
      terms: data.terms,
      terms_day: data.terms_day,
      volume_po: data.volume_po, 
      kategori_oa: Number(data.kategori_oa), 
      jenis_oa: Number(data.is_biaya), 
      harga_tebus: data.harga_tebus,
      ongkos_angkut: data.ongkos_angkut,
      iuran_migas: data.iuran_migas,
      nominal_migas: data.nominal_migas,
      catatan_po: data.keterangan,
      internal_notes: data.internal_notes,
      disposisi_po: Number(data.disposisi_po),
      revert_cfo: data.revert_cfo,
      revert_ceo: data.revert_ceo,
      revert_ceo_summary: data.revert_ceo_summary,
      revert_cfo_summary: data.revert_cfo_summary,
    })

       // DETAIL ACCURATE
      if (data.id_accurate) {

        const acc = await axios.get('/accurate/detail-po', {
          params: {
            id_accurate: data.id_accurate,
          },
        })
      

        Object.assign(form, acc.data.data)
        console.log(form)
      }

  } catch (err) {
    console.error(err)
  }
  }

// onMounted(() => {
//   getAccProduk(val, true) 
//   getAccAkun(val, true)
//   getProduk()
//   getTerminal()
//   getVendor()
//   fetchPO(id)
// })

onMounted(async () => {
  pageLoading.value = true

  try {
    await Promise.all([
      getProduk(),
      getTerminal(),
      getVendor(),
    ])

    // minimal loading 700ms
    await new Promise(resolve => setTimeout(resolve, 700))

    await Promise.all([
      getAccProduk('', true),
      getAccAkun('', true),
    ])

    if (id.value) {
      await fetchPO(id)
    }
  } finally {
    pageLoading.value = false
  }
})

const onSearch = debounce((val: string) => {
  // console.log('SEARCH EVENT:', val)

  search.value = val

  page.value = 1

  getAccProduk(val, true)
}, 300)

const formatMoney = (value: number | null): string => {
   if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}

const onlyNumber = (e: KeyboardEvent): void => {
  onlyNumberKeypress(e)
}

const parse = (val: string) =>
  Number(val.replace(/[^\d]/g, ""))

const required = (label: string) => {
  return (v: any) =>
    v !== null &&
    v !== undefined &&
    v !== ''
      || `${label} wajib diisi`
}

const requiredNotZero = (label: string)=> {
  return (v: any) =>
    v !== null &&
    v !== undefined &&
    v !== '' && v !== '0' &&
    v !== 0
      || `${label} wajib diisi dan tidak boleh 0`
}

const showBiayaOA = computed(() => {
  return Number(form.kategori_oa) === 2
})

const showKodeItemOA = computed(() => {
  return Number(form.kategori_oa) === 2 && Number(form.jenis_oa) === 0
})

const showKodeAkunOA = computed(() => {
  return Number(form.kategori_oa) === 2 && Number(form.jenis_oa) === 1
})
// watch(
//   () => form.kategori_oa,
//   (val) => {
//     showBiayaOA.value = val === 2
//   }
// )

// watch(
//   () => form.jenis_oa,
//   (val) => {
//     showKodeItemOA.value = val === 0
//   }
// )
// watch(
//   () => form.jenis_oa,
//   (val) => {
//     showKodeAkunOA.value = val === 1
//   }
// )

watch(
  () => form.terms,
  value => {
    showNetInput.value = value === 'NET'
  },
  { immediate: true },
)

watch(() => form.iuran_migas, (val) => {
  if (!val) {
    form.nominal_iuran = 0
    form.biaya_migas = null
    form.alokasi_migas = false
    form.keterangan_migas = ''
  }
})
// watch(
//   () => [
//     form.iuran_migas,
//     form.harga_tebus,
//     form.volume_po
//   ],
//   () => {
//     if (form.iuran_migas) {
//       form.nominal_iuran = showMigas.value
//     } else {
//       form.nominal_iuran = 0
//       form.kode_item = null
//     }
//   },
//   { immediate: true }
// )
watch(
  () => form.kategori_oa,
  (val) => {
    if (Number(val) !== 2) {
      form.jenis_oa = 0
      form.kode_item_oa = null
      form.keterangan_item_oa = ''
      form.alokasi_item_oa = false

      form.biaya_oa = null
      form.keterangan_biaya_oa = ''
      form.alokasi_biaya_oa = false

      form.biaya_lain_oa = null
      form.ket_biaya_lain_oa = ''
      form.alokasi_biaya_oa_lain = false
      form.jumlah_biaya = 0

      form.ongkos_angkut = 0
      form.kategori_plat = ''
    }
  }
)

const selectedAccText = reactive({
  kode_item: '',
  kode_item_oa: '',

  biaya_oa: '',
  biaya_lain_oa: '',
  biaya_pph22: '',
  biaya_pbbkb: '',
  biaya_migas: '',
})
const setSelectedAccText = (
  list: any[],
  selectedId: any,
  targetKey: keyof typeof selectedAccText,
  labelKey = 'text',
) => {
  if (!selectedId) {
    selectedAccText[targetKey] = ''
    return
  }

  const item = list.find(i => String(i.id) === String(selectedId))

  selectedAccText[targetKey] = item?.[labelKey] || ''
}
const showAkun = computed(() => {
  return (
    showpph.value ||
    showpbbkb.value ||
    showMigas.value
  )
})


const resolveLabelById = (
  list: any[],
  id: any,
  labelKey: string = 'label',
) => {
  const item = list.find(i => i.id === id)
  return item ? item[labelKey] : '-'
}

const goToReview = async () => {
  const { valid } = await formRef.value.validate()

  if (!valid) return

  step.value = 'review'
}

// snackbar notif
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

const notify = (
  text: string,
  color: 'success' | 'error' | 'warning' | 'info' = 'success',
  timeout = 3000,
) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

const formRef = ref()

const submit = async () => {

  isSaving.value = true

  try {

    dialogSubmit.value = false

    const payload = {
      ...form,

      subtotal: subtotal.value,
      ppn12: ppn.value,
      pph22: pph22.value,
      dpp: dpp.value,
      pbbkb: pbbkbval.value,
      nominal_migas: nominalMigas.value,
      total_order: totalPO.value,
    }

    let response

    // EDIT
    if (mode.value === 'edit') {

      response = await axios.put(
        `/inventory/purchase-order/${route.query.id}`,
        payload,
      )

    // CREATE
    } else {

      response = await axios.post(
        '/inventory/purchase-order',
        payload,
      )
    }

    notify(
      response.data.message || 'Berhasil disimpan',
      'success',
    )

    router.push('/purchaseSupplier/po-supplier')

  } catch (err: any) {

    console.error(err)
    isSaving.value = false

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'gagal menyimpan data'),
    })

  } finally {

    isSaving.value = false
  }
}




</script>
<template>
  <VContainer fluid class="pa-3 bg-grey-lighten-4">
    <div v-if="step === 'form'">
        <!-- <div v-if="mode === 'add'"> -->
          <VCard
            v-if="pageLoading"
            class="mb-6"
            variant="tonal"
            color="primary"
          >
            <VCardText class="d-flex align-center py-4">

              <VProgressCircular
                indeterminate
                size="30"
                width="3"
                color="primary"
                class="me-4"
              />

              <div>
                <div class="text-h6 font-weight-medium">
                    {{ mode === 'add' ? 'Mohon menunggu' : ' Memuat data Purchase Order' }}
                </div>

                <div v-if="mode === 'edit'" class="text-body-2 text-medium-emphasis">
                  Mohon menunggu
                </div>
              </div>

            </VCardText>
          </VCard>
          <VForm  v-if="!pageLoading" ref="formRef">
    
            <!-- ===================================================== -->
            <!-- HEADER -->
            <!-- ===================================================== -->
              <section class="mb-6">
                <VCard>
                  <VCardText class="pa-6">
    
                    <div class="d-flex justify-space-between align-center flex-wrap ga-4">
    
                      <div>
                        <div class="d-flex align-center ga-3 mb-2">
                          
                          <VAvatar
                            color="primary"
                              rounded
                              class="me-3"
                              variant="tonal"
                              >
                            <VIcon icon="tabler-file-invoice" />
                          </VAvatar>
    
                          <div>
                                <div class="text-h6 text-weight-bold">
                                  {{ mode === 'add' ? 'Tambah PO Supplier' : 'Edit PO Supplier' }}
                                </div>
    
                            <div class="text-medium-emphasis text-body-2">
                              Silakan isi form dibawah ini
                            </div>
                          </div>
    
                        </div>
                      </div>
    
                      <VChip v-if="form.jenis_harga"
                        :color="form.jenis_harga == 1 ? 'primary' : 'warning'"
                        variant="tonal"
                        size="large"
                        :prepend-icon="form.jenis_harga == 1 ? 'tabler-circle-check' : 'tabler-alert-circle'"
                      >
                        {{ form.jenis_harga == 1 ? 'Harga Final' : 'Harga Sementara' }}
                      </VChip>
    
                    </div>
    
                  </VCardText>
                </VCard>
              </section>
    
            <!-- ===================================================== -->
            <!-- ALERT -->
            <!-- ===================================================== -->
          <section class="mb-6" v-if="form.jenis_harga==2">
            <VAlert
              type="warning"
              variant="tonal"
              border="start"
              prominent
            >
              <div class="d-flex justify-space-between align-center w-100">
                
                <div>
                  <div class="font-weight-bold mb-1">
                    Harga PO Memerlukan Update
                  </div>
    
                  Harga masih menggunakan harga sementara.
                  Silakan lakukan perubahan harga PO untuk melanjutkan proses transaksi.
                </div>
    
                <VBtn
                  color="warning"
                  variant="flat"
                  class="ml-4"
                >
                  Ubah Harga
                </VBtn>
    
              </div>
            </VAlert>
          </section>

          <section class="mb-6" v-if="form.revert_cfo==1 || form.revert_ceo==1">
          <VAlert
            v-if="form.revert_cfo == 1 || form.revert_ceo == 1"
            color="error"
            variant="tonal"
            border="start"
            class="mb-6"
          >
            <div class="d-flex align-center mb-3">
              <VAvatar
                color="error"
                size="32"
                class="me-3"
              >
                <VIcon size="18">mdi-close</VIcon>
              </VAvatar>

              <div>
                <div class="font-weight-bold">
                  Purchase Order Ditolak
                </div>

                <div class="text-caption ">
                  Oleh {{ form.revert_cfo == 1 ? 'CFO' : 'CEO' }}
                </div>
              </div>
            </div>

            <VSheet
              rounded="lg"
              class="pa-3 bg-white"
            >
              <div class="text-caption text-medium-emphasis mb-1">
                Alasan Pengembalian :
              </div>

              <div class="text-body-2">
                {{ form.revert_cfo_summary || form.revert_ceo_summary }}
              </div>
            </VSheet>
          </VAlert>
          </section>
    
            <!-- ===================================================== -->
            <!-- INFORMASI PO -->
            <!-- ===================================================== -->
            <section class="mb-6">
              <VCard>
    
                <VCardTitle class="px-6 pt-5 pb-2">
                  <div>
                    <div class="text-h6 font-weight-bold">
                      Informasi Purchase Order
                    </div>
    
                    <div class="text-caption text-medium-emphasis">
                      Data utama Purchase Order Vendor
                    </div>
                  </div>
                </VCardTitle>
    
                <VDivider />
    
                <VCardText class="pa-6">
    
                  <VRow>
    
                    <VCol cols="12" md="6">
                      <VTextField
                        label="Nomor PO"
                        v-model="form.nomor_po"
                        disabled
                        variant="outlined"
                      />
                    </VCol>
    
                  </VRow>
                  <VRow>
                    <VCol cols="12" md="6">
                      <VTextField
                        v-model="form.tanggal_inven"
                        label="Tanggal PO *"
                        type="date"
                        variant="outlined"
                        :rules="[required('Tanggal PO')]"
                      />
                    </VCol>
                    <VCol cols="12" md="6">
                      <VSelect
                        v-model="form.produk"
                        :items="produkList"
                        item-title="label"
                        item-value="id"
                        label="Produk *"
                        clearable
                        variant="outlined"
                        :rules="[required('Produk')]"
                      />
                    </VCol>
                    
                  </VRow>
                  <VRow>
                    <VCol cols="12">
                      <div class="border rounded pa-4 mb-2 border-primary border-opacity-100">
                        <div class="font-weight-bold mb-2">
                          kode Item Accurate *
                        </div>
                        <VRow>
                          <VCol cols="12" md="6" >
    
                            <VAutocomplete
                              v-model="form.kode_item"
                              label="Kode Item Accurate *"
                              :items="produkAccList"
                              item-title="text"
                              item-value="id"
                              clearable
                              no-filter
                              density="comfortable"
                              :loading="loading"
                              :menu-props="{  maxHeight: 300,
                              attach: 'body' }"
                              placeholder="Pilih Kode Item Accurate"
                              @update:search="onSearch"
                              :rules="[required('Kode Item Accurate')]"
                            >
                             <template #prepend-item>
                              <div v-if="loading" class="pa-2">
                                <VProgressLinear
                                  indeterminate
                                  color="primary"
                                />
                                <div class="text-caption mt-1 text-center">
                                  Loading...
                                </div>
                              </div>
                            </template>

                            <template #no-data>
                              <div class="pa-3 text-center">
                                <span v-if="loading">Sedang mengambil data...</span>
                                <span v-else>Tidak ada data</span>
                              </div>
                            </template>
                            
                              <template #item="{ props, item }">
                                <VListItem
                                  v-bind="props"
                                >
                                  <div class="text-caption text-medium-emphasis">
                                    {{ item.raw?.id || '-' }}
                                  </div>
                                </VListItem>
                              </template>
                            </VAutocomplete>
                          </VCol>
    
                          <VCol cols="12" md="4">
                            <VTextField
                              label="Keterangan Item"
                              variant="outlined"
                            />
                          </VCol>
    
                         <VCol cols="12" md="2" >
                          <VCheckbox
                            label="Alokasi ke Barang"
                            color="primary"
                            hide-details
                          />
                        </VCol>
                        </VRow>
    
                      </div>
                    </VCol>
                  </VRow>
                  <VRow>
                    <VCol cols="12" md="6">
                       <VAutocomplete
                          v-model="form.terminal"
                          label="Terminal *"
                          :items="terminalList"
                          item-title="nama_terminal"
                          item-value="id"
                          clearable
                          no-filter
                          density="comfortable"
                          :menu-props="{  maxHeight: 300,
                          attach: 'body' }"
                          action-refresh
                          @refresh="getTerminal"
                          placeholder="Pilih Terminal"
                          :rules="[required('Terminal')]"
                        >
                        
                          <template #item="{ props, item }">
                            <VListItem
                              v-bind="props"
                            >
                              <div class="text-caption text-medium-emphasis">
                                {{ item.raw?.lokasi_terminal || '-' }}
                              </div>
                            </VListItem>
                          </template>
                        </VAutocomplete>
                    </VCol>
          
                    <VCol cols="12" md="6">
                        <VSelect
                          v-model="form.vendor"
                          :items="vendorList"
                          item-title="nama_vendor"
                          item-value="id"
                          label="Vendor *"
                          clearable
                          variant="outlined"
                          :rules="[required('Vendor')]"
                        />
                    </VCol>
                  </VRow>
                  <VRow>
                    <VCol cols="12" md="2">
                      <label class="text-subtitle-2 font-weight-medium mb-1 d-block">
                        Jenis Kirim
                      </label>
    
                      <VRadioGroup 
                      inline
                      v-model="form.jenis_kirim"
                      :rules="[required('Jenis Kirim')]"
                      >
                        <VRadio label="Truck" :value="1" />
                        <VRadio label="Ship" :value="2" />
                      </VRadioGroup>
                    </VCol>
                  </VRow>
    
                </VCardText>
              </VCard>
            </section>
    
            <!-- ===================================================== -->
            <!-- HARGA -->
            <!-- ===================================================== -->
            <section class="mb-6">
              <VRow>
                <!-- LEFT -->
                <VCol cols="12" md="7">
    
                  <VCard class="h-100">
    
                    <VCardTitle class="px-6 pt-5 pb-2">
                      <div>
                        <div class="text-h6 font-weight-bold">
                          Informasi Harga
                        </div>
    
                        <div class="text-caption text-medium-emphasis">
                          Detail Harga Pembelian 
                        </div>
                      </div>
                    </VCardTitle>
    
                    <VDivider />
    
                    <VCardText class="pa-6">
    
                      <VRow>
                        <VCol cols="12" md="4">
                          <label class="text-subtitle-2 font-weight-medium d-block">
                            Jenis Harga PO
                          </label>
    
                          <VRadioGroup
                            v-model="form.jenis_harga"
                            inline
                            :rules="[requiredNotZero('Jenis Harga PO')]"
                          >
                            <VRadio label="Final" :value="1" />
                            <VRadio label="Sementara" :value="2" />
                          </VRadioGroup>
                          </VCol>
                          
                      </VRow>
                      <VRow>
                        <VCol cols="12" md="3">
                          <VSelect
                            label="Kode Tax *"
                            v-model="form.kd_tax"
                            :items="['E', 'EC']"
                            variant="outlined"
                            placeholder="Pilih Kode Tax"
                            :rules="[required('Kode Tax')]"
                          />
                        </VCol>
                        <VCol cols="12" md="3">
                          <VSelect
                            v-model="form.terms"
                            label="Terms *"
                            :items="[
                              { title: 'COD', value: 'COD' },
                              { title: 'NET', value: 'NET' },
                              { title: 'CBD', value: 'CBD' },
                            ]"
                            item-title="title"
                            item-value="value"
                            variant="outlined"
                            :rules="[required('Terms')]"
                          />
                          </VCol>
    
                          <VCol v-if="showNetInput" cols="12" md="3">
                            <VTextField
                              v-model="form.terms_day"
                              suffix="Hari"
                              label="Terms Day"
                              variant="outlined"
                              :rules="[requiredNotZero('Terms Days')]"
                            />
                          </VCol>
                      </VRow>
                      <VRow>
                        <VCol cols="12" md="3">
                          <VTextField
                            label="Volume PO"
                            @keypress="onlyNumber"
                            inputmode="numeric"
                            class="text-end"
                            :model-value="formatMoney(form.volume_po)"
                            @update:modelValue="(val: string) => form.volume_po = parse(val) || 0"
                            suffix="Liter"
                            :rules="[requiredNotZero('Volume PO')]"
                          />
                        </VCol>
                        <VCol cols="12" md="3">
                          <VTextField
                            label="Harga Dasar"
                            inputmode="numeric"
                            :input-style="{ textAlign: 'right' }"
                            @keypress="onlyNumber"
                            :model-value="formatMoney(form.harga_tebus)"
                            @update:modelValue="(val) => form.harga_tebus = parse(val) || 0"
                            prefix="Rp"
                            :rules="[requiredNotZero('Harga Dasar')]"
                          />
                        </VCol>
    
                        <VCol cols="12" md="4">
                          <VSelect
                            label="Kategori OA"
                            v-model="form.kategori_oa"
                            :items="[
                              { title: 'Tanpa OA', value: 1 },
                              { title: 'Dengan OA', value: 2 }
                            ]"
                            variant="outlined"
                            :rules="[required('Kategori OA')]"
                          />
                        </VCol>
                      </VRow>
                      <VRow>
                        <VCol cols="12" md="3">
                            <VSelect
                              v-model="form.pbbkb"
                              :items="[
                                { title: '0%', value: 0 },
                                { title: '7.5%', value: 7.5 },
                                { title: '10%', value: 10 }
                              ]"
                              item-title="title"
                              item-value="value"
                              label="PBBKB *"
                              clearable
                              :rules="[required('PBBKB')]"
                            />
                          </VCol>
                          <VCol cols="12" md="3">
                              <VCheckbox
                                v-model="form.iuran_migas"
                                label="Iuran Migas"
                              />
                            </VCol>
    
                            <VCol cols="12" md="4" v-if="showMigas" >
                              <VTextField
                                label="Nominal Migas"
                                @keypress="onlyNumber"
                                :model-value="formatMoney(nominalMigas)"
                                @update:modelValue="(val) => form.nominal_iuran = parse(val)"
                                prefix="Rp"
                                :rules="[requiredNotZero('Nominal Migas')]"
                              />
                            </VCol>
                      </VRow>
    
                    </VCardText>
    
                  </VCard>
    
                </VCol>
    
                <!-- RIGHT SUMMARY -->
                <VCol cols="12" md="5">
    
                  <VCard color="primary" theme="dark">
    
                    <VCardText class="pa-6">
    
                      <!-- HEADER -->
                      <div class="d-flex align-center mb-5">
                        <VAvatar
                          color="white"
                          rounded
                          size="42"
                          class="me-3"
                          variant="tonal"
                        >
                          <VIcon icon="mdi-receipt-text" />
                        </VAvatar>
    
                        <div>
                          <div class="text-h6 font-weight-bold text-white">
                            Rincian Perhitungan
                          </div>
    
                          <div class="text-caption text-white">
                            Detail Harga pembelian
                          </div>
                        </div>
                      </div>
    
                      <!-- DETAIL TRANSAKSI -->
                      <!-- <div class="text-subtitle-2 font-weight-bold mb-3 text-white">
                        Informasi Dasar
                      </div> -->
    
                      <div class="d-flex justify-space-between mb-3">
                        <span>Harga Dasar</span>
                        <strong>Rp {{ formatMoney(form.harga_tebus) }}</strong>
                      </div>
    
                      <div class="d-flex justify-space-between mb-4">
                        <span>Volume PO</span>
                        <strong>{{ formatMoney(form.volume_po) }} Liter</strong>
                      </div>
                      <div
                        v-if="showBiayaOA"
                        class="d-flex justify-space-between mb-3"
                      >
                        <span>Ongkos Angkut</span>
                        <strong>Rp {{ formatMoney(form.ongkos_angkut) }}</strong>
                      </div>
    
    
                      <VDivider class="mb-4 border-opacity-50" />
    
                      <!-- PERHITUNGAN -->
                      <!-- <div class="text-subtitle-2 font-weight-bold mb-3 text-white">
                        Komponen Biaya
                      </div> -->
    
                      <div class="d-flex justify-space-between mb-3">
                        <span>Subtotal</span>
                        <strong>Rp {{ formatMoney(subtotal) }}</strong>
                      </div>
    
                      <div class="d-flex justify-space-between mb-3">
                        <span>DPP 11/12</span>
                        <strong>Rp {{ formatMoney(dpp) }}</strong>
                      </div>
    
                      <div class="d-flex justify-space-between mb-3">
                        <span>PPN 12%</span>
                        <strong>Rp {{ formatMoney(ppn) }}</strong>
                      </div>
    
                      <div class="d-flex justify-space-between mb-3" v-if="showpph">
                        <span>PPH 22</span>
                        <strong>Rp {{ formatMoney(pph22) }}</strong>
                      </div>
    
                      <div
                        v-if="showpbbkb"
                        class="d-flex justify-space-between mb-3"
                      >
                        <span>PBBKB</span>
                        <strong>Rp {{ formatMoney(pbbkbval) }}</strong>
                      </div>
    
                      <div
                        v-if="showMigas"
                        class="d-flex justify-space-between mb-3"
                      >
                        <span>Iuran Migas</span>
                        <strong>Rp {{ formatMoney(nominalMigas) }}</strong>
                      </div>
    
    
                      <VDivider class="my-4 border-opacity-50" />
    
                      <!-- TOTAL -->
                      <div class="d-flex justify-space-between align-center">
                        <span class="text-h6 font-weight-bold text-white">
                          Total Order
                        </span>
    
                        <span class="text-h5 font-weight-bold text-white">
                          Rp {{ formatMoney(totalPO) }}
                        </span>
                      </div>
    
                    </VCardText>
    
                  </VCard>
    
                </VCol>
    
              </VRow>
    
            </section>
    
            <!-- OA SECTION -->
            <section v-if="showBiayaOA" class="mb-3">
    
              <VCard>
                <VCardTitle class="px-6 pt-5">
                  Biaya Ongkos Angkut
                </VCardTitle>
    
                <VDivider />
    
                <VCardText class="pa-6">
    
                  <VRow>
    
                    <VCol cols="12" md="3">
                        <VSelect
                          label="Jenis OA"
                          v-model="form.jenis_oa"
                          :items="[
                            { title: 'Sebagai Biaya', value: 1 },
                            { title: 'Sebagai Kode Item', value: 0 }
                          ]"
                          variant="outlined"
                          placeholder="Pilih Jenis OA"
                          :rules="[required('Jenis OA')]"
                        />
                      </VCol>
                  </VRow>
    
                  <!-- sebagai kode item -->
                  <VSheet v-if="showKodeItemOA" class="mt-3 pa-4 mb-4"
                  border rounded="lg" color="pink-lighten-5">
    
                    <div class="text-subtitle-2 font-weight-bold mb-3">
                      Akun OA Accurate *
                    </div>
                    <VRow>
          
                      <VCol cols="12" md="6">
                        <VAutocomplete
                              v-model="form.kode_item_oa"
                              label="Kode Item Accurate *"
                              :items="produkAccList"
                              item-title="text"
                              item-value="id"
                              clearable
                              no-filter
                              density="comfortable"
                              :loading="loading"
                              :menu-props="{  maxHeight: 300,
                              attach: 'body' }"
                              placeholder="Pilih Kode Item Accurate"
                              @update:search="onSearch"
                              :rules="[required('Kode Item Accurate')]"
                            >
                             <template #prepend-item>
                              <div v-if="loading" class="pa-2">
                                <VProgressLinear
                                  indeterminate
                                  color="primary"
                                />
                                <div class="text-caption mt-1 text-center">
                                  Loading...
                                </div>
                              </div>
                            </template>

                            <template #no-data>
                              <div class="pa-3 text-center">
                                <span v-if="loading">Sedang mengambil data...</span>
                                <span v-else>Tidak ada data</span>
                              </div>
                            </template>
                            
                              <template #item="{ props, item }">
                                <VListItem
                                  v-bind="props"
                                >
                                  <div class="text-caption text-medium-emphasis">
                                    {{ item.raw?.id || '-' }}
                                  </div>
                                </VListItem>
                              </template>
                            </VAutocomplete>
                      </VCol>
          
                      <VCol cols="12" md="4">
                        <VTextField
                          v-model="form.keterangan_item_oa"
                          label="Keterangan"
                          variant="outlined"
                        />
                      </VCol>
          
                      <VCol cols="12" md="2">
                        <VCheckbox
                          v-model="form.alokasi_item_oa"
                          label="Alokasi ke Barang"
                        />
                      </VCol>
          
                    </VRow>
                  </VSheet>
    
                  <VRow class="mb-3">
    
                    <VCol cols="12" md="3">
                      <VTextField
                        label="Ongkos Angkut"
                        class="text-end"
                        @keypress="onlyNumber"
                        :model-value="formatMoney(form.ongkos_angkut)"
                        @update:modelValue="(val) => form.ongkos_angkut = parse(val)"
                        prefix="Rp"
                      />
                      </VCol>
                    <VCol cols="12" md="3">
                      <VAutocomplete
                            label="Kategori Plat *"
                            v-model="form.kategori_plat"
                            :items="[
                              { title: 'Hitam', value: 'Hitam' },
                              { title: 'Kuning', value: 'Kuning' }
                            ]"
                            variant="outlined"
                            item-title="title"
                            item-value="value"
                            clearable
                            density="comfortable"
                            :menu-props="{ maxHeight: 300 }"
                            placeholder="Pilih Kategori Plat"
                          />
                      </VCol>
                  </VRow>
    
                  <!-- sebagai biaya -->
                <VSheet
                  v-if="showKodeAkunOA"
                  class="pa-4 mb-4"
                  border rounded="lg"
                  color="pink-lighten-5"
                >
                  
                  <!-- AKUN OA ACCURATE-->
                  <div class="font-weight-bold mb-3">
                    Akun OA Accurate
                  </div>
    
                  <VRow class="mb-3">
                    <VCol cols="12" md="4">
                        <VAutocomplete
                          v-model="form.biaya_oa"
                          v-model:search="search"
                          label="Akun OA Accurate *"
                          :items="akunAccList"
                          item-title="text"
                          item-value="id"
                          clearable
                          density="comfortable"
                          :menu-props="{  maxHeight: 300,
                          attach: 'body' }"
                          placeholder="Pilih akun OA Accurate"
                          @update:search="onSearchAkun"
                          :rules="[required('akun OA Accurate')]"
                        >
                          <template #prepend-item>
                              <div v-if="loadingAkun" class="pa-2">
                                <VProgressLinear
                                  indeterminate
                                  color="primary"
                                />
                                <div class="text-caption mt-1 text-center">
                                  Loading...
                                </div>
                              </div>
                            </template>

                            <template #no-data>
                              <div class="pa-3 text-center">
                                <span v-if="loadingAkun">Sedang mengambil data...</span>
                                <span v-else>Tidak ada data</span>
                              </div>
                            </template>
                            
                         <template #item="{ props, item }">
                          <VListItem v-bind="props">
                            <VListItemTitle>
                              {{ item.raw?.noWithIndent }}
                            </VListItemTitle>

                          </VListItem>
                        </template>
                        </VAutocomplete>
                    </VCol>
    
                    <VCol cols="12" md="4">
                      <VTextField
                        v-model="form.keterangan_biaya_oa"
                        label="Keterangan"
                        variant="outlined"
                        density="comfortable"
                      />
                    </VCol>
    
                    <VCol cols="12" md="4">
                      <div class="d-flex align-center h-100">
                        <VCheckbox
                          v-model="form.alokasi_biaya_oa"
                          label="Alokasi ke Barang"
                          hide-details
                        />
                      </div>
                    </VCol>
                  </VRow>
    
                  <!-- AKUN BIAYA LAIN AOL-->
                  <div class="font-weight-bold mb-3">
                    Akun Biaya Lain
                  </div>
    
                  <VRow>
                    <VCol cols="12" md="4">
                        <VAutocomplete
                          v-model="form.biaya_lain_oa"
                          v-model:search="search"
                          label="Akun Biaya Lain Accurate *"
                          :items="akunAccList"
                          item-title="text"
                          item-value="id"
                          clearable
                          density="comfortable"
                          :menu-props="{  maxHeight: 300,
                          attach: 'body' }"
                          placeholder="Pilih akun biaya lain Accurate"
                          @update:search="onSearchAkun"
                          @update:model-value="val => setSelectedAccText(akunAccList, val, 'biaya_lain_oa')"
                          :rules="[required('akun biaya lain Accurate')]"
                        >
                          <template #prepend-item>
                            <div v-if="loadingAkun" class="pa-2">
                              <VProgressLinear
                                indeterminate
                                color="primary"
                              />
                              <div class="text-caption mt-1 text-center">
                                Loading...
                              </div>
                            </div>
                          </template>

                          <template #no-data>
                            <div class="pa-3 text-center">
                              <span v-if="loadingAkun">Sedang mengambil data...</span>
                              <span v-else>Tidak ada data</span>
                            </div>
                          </template>
                         <template #item="{ props, item }">
                          <VListItem v-bind="props">
                            <VListItemTitle>
                              {{ item.raw?.noWithIndent }}
                            </VListItemTitle>

                          </VListItem>
                        </template>
                        </VAutocomplete>
                    </VCol>
    
                    <VCol cols="12" md="4">
                      <VTextField
                        label="Keterangan"
                        variant="outlined"
                        density="comfortable"
                      />
                    </VCol>
                    <VCol cols="12" md="2">
                    <VTextField
                        label="Jumlah"
                        inputmode="numeric"
                        :input-style="{ textAlign: 'right' }"
                        @keypress="onlyNumber"
                        :model-value="formatMoney(form.jumlah_biaya)"
                        @update:modelValue="(val) => form.jumlah_biaya = parse(val)"
                        prefix="Rp"
                      />
                    </VCol>
    
                    <VCol cols="12" md="2">
                      <div class="d-flex align-center h-100">
                        <VCheckbox
                         v-model="form.alokasi_biaya_oa_lain"
                          label="Alokasi ke Barang"
                          hide-details
                        />
                      </div>
                    </VCol>
                  </VRow>
    
                </VSheet>
    
                </VCardText>
    
              </VCard>
    
            </section>
    
            <section v-if="showAkun" class="mb-3">
    
              <VCard>
                <VCardTitle class="px-5">
                  Kode Akun Accurate
                </VCardTitle>
    
                <VDivider />
    
                <VCardText class="pa-5">
    
    
                  <!-- sebagai pph22 -->
                <VSheet v-if="showpph" class="mb-3">
    
                    <div class="font-weight-bold mb-3">
                    Akun PPH 22 Accurate *
                    </div>
                    <VRow class="mb-2">
          
                      <VCol cols="12" md="6">
                          <VAutocomplete
                            v-model="form.biaya_pph22"
                            v-model:search="search"
                            label="Akun PPH 22 Accurate *"
                            :items="akunAccList"
                            item-title="text"
                            item-value="id"
                            clearable
                            density="comfortable"
                            :menu-props="{  maxHeight: 300,
                            attach: 'body' }"
                            placeholder="Pilih akun pph 22 Accurate"
                            @update:search="onSearchAkun"
                            :rules="[required('akun pph 22 Accurate')]"
                          >
                          <template #prepend-item>
                            <div v-if="loadingAkun" class="pa-2">
                              <VProgressLinear
                                indeterminate
                                color="primary"
                              />
                              <div class="text-caption mt-1 text-center">
                                Loading...
                              </div>
                            </div>
                          </template>

                          <template #no-data>
                            <div class="pa-3 text-center">
                              <span v-if="loadingAkun">Sedang mengambil data...</span>
                              <span v-else>Tidak ada data</span>
                            </div>
                          </template>
                          <template #item="{ props, item }">
                            <VListItem v-bind="props">
                              <VListItemTitle>
                                {{ item.raw?.noWithIndent }}
                              </VListItemTitle>

                            </VListItem>
                          </template>
                          </VAutocomplete>
                      </VCol>
          
                      <VCol cols="12" md="4">
                        <VTextField
                          v-model="form.keterangan_pph22"
                          label="Keterangan"
                          variant="outlined"
                        />
                      </VCol>
          
                      <VCol cols="12" md="2">
                        <VCheckbox
                          v-model="form.alokasi_pph22"
                          label="Alokasi ke Barang"
                        />
                      </VCol>
          
                    </VRow>
                  <VDivider />
                </VSheet>
    
                <VSheet v-if="showpbbkb" class="mb-2"
                  >
                  
                    <div class="font-weight-bold mb-3 mt-2">
                    Akun PBBKB Accurate *
                    </div>
                    <VRow  class="mb-2">
          
                      <VCol cols="12" md="6">
                         <VAutocomplete
                          v-model="form.biaya_pbbkb"
                          v-model:search="search"
                          label="Akun PBBKB Accurate *"
                          :items="akunAccList"
                          item-title="text"
                          item-value="id"
                          clearable
                          density="comfortable"
                          :menu-props="{  maxHeight: 300,
                          attach: 'body' }"
                          placeholder="Pilih akun PBBKB Accurate"
                          @update:search="onSearchAkun"
                          :rules="[required('akun PBBKB Accurate')]"
                        >
                        <template #prepend-item>
                            <div v-if="loadingAkun" class="pa-2">
                              <VProgressLinear
                                indeterminate
                                color="primary"
                              />
                              <div class="text-caption mt-1 text-center">
                                Loading...
                              </div>
                            </div>
                          </template>

                          <template #no-data>
                            <div class="pa-3 text-center">
                              <span v-if="loadingAkun">Sedang mengambil data...</span>
                              <span v-else>Tidak ada data</span>
                            </div>
                          </template>
                         <template #item="{ props, item }">
                          <VListItem v-bind="props">
                            <VListItemTitle>
                              {{ item.raw?.noWithIndent }}
                            </VListItemTitle>

                          </VListItem>
                          </template>
                        </VAutocomplete>
                      </VCol>
          
                      <VCol cols="12" md="4">
                        <VTextField
                          v-model="form.keterangan_pbbkb"
                          label="Keterangan"
                          variant="outlined"
                        />
                      </VCol>
          
                      <VCol cols="12" md="2">
                        <VCheckbox
                          v-model="form.alokasi_pbbkb"
                          label="Alokasi ke Barang"
                        />
                      </VCol>
          
                    </VRow>
                    <VDivider />
                </VSheet>
                <VSheet v-if="showMigas" class="mb-2">
                  <div class="font-weight-bold mb-3">
                    Akun Iuran Migas Accurate *
                  </div>
                    <VRow>
                      <VCol cols="12" md="6">
                  
                        <VAutocomplete
                          v-model="form.biaya_migas"
                          v-model:search="search"
                          label="Akun Iuran Migas Accurate *"
                          :items="akunAccList"
                          item-title="text"
                          item-value="id"
                          clearable
                          density="comfortable"
                          :menu-props="{  maxHeight: 300,
                          attach: 'body' }"
                          placeholder="Pilih akun Iuran Migas Accurate"
                          @update:search="onSearchAkun"
                          :rules="[required('akun Iuran Migas Accurate')]"
                        >
                        <template #prepend-item>
                            <div v-if="loadingAkun" class="pa-2">
                              <VProgressLinear
                                indeterminate
                                color="primary"
                              />
                              <div class="text-caption mt-1 text-center">
                                Loading...
                              </div>
                            </div>
                          </template>

                          <template #no-data>
                            <div class="pa-3 text-center">
                              <span v-if="loadingAkun">Sedang mengambil data...</span>
                              <span v-else>Tidak ada data</span>
                            </div>
                          </template>
                        <template #item="{ props, item }">
                        <VListItem v-bind="props">
                          <VListItemTitle>
                            {{ item.raw?.noWithIndent }} - {{ item.raw?.name || item.raw?.text }}
                          </VListItemTitle>
                        </VListItem>
                      </template>
                          
                        </VAutocomplete>
                
                      
                      </VCol>
                
                      <VCol cols="12" md="4">
                        <VTextField 
                        v-model="form.keterangan_migas"
                        label="Keterangan Item" 
                        />
                      </VCol>
                      <VCol cols="12" md="2">
                        <div class="d-flex align-center h-100">
                            <VCheckbox
                            v-model="form.alokasi_migas"
                            label="Alokasi ke Barang "
                            color="primary"
                            hide-details
                            />
                        </div>
                      </VCol>
                    </VRow>
                </VSheet>
    
                </VCardText>
    
              </VCard>
    
            </section>
    
            <section class="mb-6">
    
              <VCard>
    
                <VCardTitle class="px-6 pt-3">
                  Catatan & Informasi Tambahan
                </VCardTitle>
    
                <VDivider />
    
                <VCardText class="pa-6">
    
                  <VTextarea
                    v-model="form.catatan_po"
                    label="Catatan PO"
                    rows="3"
                    variant="outlined"
                    class="mb-4"
                  />
    
                  <VTextarea
                    v-model="form.internal_notes"
                    label="Internal Notes"
                    rows="4"
                    variant="outlined"
                    class="mb-4"
                  />

                  <VTextarea
                    v-if="form.disposisi_po==4"
                    v-model="form.catatan_resubmit"
                    label="Catatan Pengajuan Ulang"
                    rows="4"
                    variant="outlined"
                  />
    
                </VCardText>
    
              </VCard>
    
            </section>
    
            <!-- ACTION -->
            <section>
    
              <div class="d-flex justify-end flex-wrap gap-3">
    
                <!-- <VBtn color="primary" size="large" @click="step = 'review'"> -->
                <VBtn color="primary" size="large" @click="goToReview">
                  Review
                </VBtn>
    
                <VBtn
                  variant="outlined"
                  size="large"  @click="router.back()"
                >
                  Kembali
                </VBtn>
    
              </div>
    
            </section>
          </VForm>
        <!-- </div> -->
        <!-- <div v-else>
     
        </div> -->
       
    </div>
    <div v-else>
      
    <VCard rounded="lg">

      <!-- HEADER -->
      <VCardTitle class="d-flex justify-space-between align-center">
        <div>
          <div class="text-h6 font-weight-bold">
            Review Purchase Order
          </div>
          <div class="text-caption text-medium-emphasis">
            Cek ulang sebelum submit
          </div>
        </div>

        <VChip
          :color="mode === 'add' ? 'primary' : 'warning'"
          variant="flat"
        >
          {{ mode === 'add' ? 'New PO' : 'Edit PO' }}
        </VChip>
      </VCardTitle>

      <VDivider />

      <VCardText class="pa-6">

        <!-- ALERT -->
        <VAlert
          type="info"
          variant="tonal"
          class="mb-6"
        >
          <div class="font-weight-bold mb-1">
            Silakan cek kembali data PO sebelum submit
          </div>
          Pastikan semua informasi sudah sesuai.
        </VAlert>

        <!-- ================= INFORMASI DASAR ================= -->
        <div class="text-h6 font-weight-bold">
          Informasi Dasar
        </div>
      <div class="bg-var-theme-background rounded pa-5 mt-2">
        <VRow>
            <VCol cols="12" md="6">

              <div class="mb-4">
                <div class="text-caption text-black">Tanggal PO</div>
                <div class="text-sm font-weight-semibold">
                  {{ form.tanggal_inven || '-' }}
                </div>
              </div>
    
              <div class="mb-4">
                <div class="text-caption text-black">Produk</div>
                <div class="text-black font-weight-semibold">
                  {{ resolveLabelById(produkList, form.produk, 'label')}}
                </div>
              </div>
    
              <div class="mb-4">
                <div class="text-caption text-black">Terminal</div>
                <div class="text-black font-weight-semibold">
                  {{ resolveLabelById(terminalList, form.terminal, 'nama_terminal') }} -
                  {{ resolveLabelById(terminalList, form.terminal, 'lokasi_terminal') }}
                </div>
              </div>
              <div class="mb-4">
                <div class="text-caption text-black">Vendor</div>
                <div class="text-black font-weight-semibold">
                  {{ resolveLabelById(vendorList, form.vendor, 'nama_vendor') }}
                </div>
              </div>
               <div class="mb-4">
                  <div class="text-caption text-black">Jenis Kirim</div>
                  <div class="text-black font-weight-semibold">
                    {{  form.jenis_kirim === 1 ? 'Truck'
                        : form.jenis_kirim === 2
                          ? 'Ship'
                          : '-' }}
                  </div>
                </div>
            </VCol>
            <VCol cols="12" md="6">
                <div class="mb-4">
                  <div class="text-caption text-black">Terms</div>
                  <div class="text-black font-weight-semibold">
                    {{ form.terms || '-' }}
                  </div>
                </div>

                <div v-if="form.terms === 'NET'" class="mb-4">
                  <div class="text-caption text-black">Terms Day</div>
                  <div class="text-black font-weight-semibold">
                    {{ form.terms_day || '-' }} Hari
                  </div>
                </div>
                <div class="mb-4">
                  <div class="text-caption text-black">Tax</div>
                  <div class="text-black font-weight-semibold">
                    {{ form.kd_tax || '-' }} 
                  </div>
                </div>
                <div class="mb-4" v-if="form.iuran_migas">
                  <div class="text-caption text-black">Iuran Migas</div>
                  <VChip
                    :color="form.iuran_migas ? 'success' : 'error'"
                    size="small"
                  >
                    {{ form.iuran_migas ? 'Ya' : 'Tidak' }}
                  </VChip>
                </div>

                <div class="mb-0">
                  <div class="text-caption text-black">Jenis Harga</div>
                  <div class="text-black font-weight-semibold">
                    {{
                      form.jenis_harga === 1
                        ? 'Final'
                        : form.jenis_harga === 2
                          ? 'Sementara'
                          : '-'
                    }}
                  </div>
                </div>
            </VCol>
        </VRow>
         

       

        </div>

        <VCard class="pa-4 mb-3 mt-2"  variant="outlined">

        <VRow dense>
          <VCol cols="12" md="4">
            <div class="text-caption text-medium-emphasis">Kode Item Accurate</div>
            <div class="text-body-2 font-weight-medium">
              {{ form.kode_item || '-' }}
            </div>
          </VCol>

          <VCol cols="12" md="4">
            <div class="text-caption text-medium-emphasis">Keterangan</div>
            <div class="text-body-2">
              {{ form.keterangan_item || '-' }}
            </div>
          </VCol>

          <VCol cols="12" md="2">
            <div class="text-caption text-medium-emphasis">Alokasi</div>
            <VChip
              :color="form.alokasi_item ? 'success' : 'error'"
              size="small"
            >
              {{ form.alokasi_item ? 'Yes' : 'No' }}
            </VChip>
          </VCol>
        </VRow>
        <VDivider class="mt-2" />
         <!-- OA -->
        <div v-if="showKodeItemOA" class="mt-2">
          <VRow dense>
            <VCol cols="12" md="4">
              <div class="text-caption">Kode Item OA Accurate</div>
              <div class="text-body-2">{{ form.kode_item_oa || '-' }}</div>
            </VCol>

            <VCol cols="12" md="4">
              <div class="text-caption">Keterangan</div>
              <div class="text-body-2">{{ form.keterangan_item_oa || '-' }}</div>
            </VCol>

            <VCol cols="12" md="2">
              <div class="text-caption">Alokasi</div>
              <VChip size="small" :color="form.alokasi_item_oa ? 'success' : 'error'">
                {{ form.alokasi_item_oa ? 'Yes' : 'No' }}
              </VChip>
            </VCol>
          </VRow>
          </div>
        <div v-if="showKodeAkunOA" class="mt-2">
          <VRow dense>
            <VCol cols="12" md="4">
              <div class="text-caption">Akun biaya OA Accurate</div>
              <div class="text-body-2">{{ form.biaya_oa || '-' }}</div>
            </VCol>

            <VCol cols="12" md="4">
              <div class="text-caption">Keterangan</div>
              <div class="text-body-2">{{ form.keterangan_biaya_oa || '-' }}</div>
            </VCol>

            <VCol cols="12" md="2">
              <div class="text-caption">Alokasi</div>
              <VChip size="small" :color="form.alokasi_biaya_oa ? 'success' : 'error'">
                {{ form.alokasi_biaya_oa ? 'Yes' : 'No' }}
              </VChip>
            </VCol>
          </VRow>

          <VDivider class="mt-2" />
          <VRow dense>
            <VCol cols="12" md="4">
              <div class="text-caption">Akun biaya lain Accurate</div>
              <div class="text-body-2">
                {{  form.biaya_lain_oa +' -'+selectedAccText.biaya_lain_oa ||'-' }}
              </div>
            </VCol>

            <VCol cols="12" md="4">
              <div class="text-caption">Keterangan</div>
              <div class="text-body-2">{{ form.ket_biaya_lain_oa || '-' }}</div>
            </VCol>

            <VCol cols="12" md="2">
              <div class="text-caption">Alokasi</div>
              <VChip size="small" :color="form.alokasi_biaya_oa_lain ? 'success' : 'error'">
                {{ form.alokasi_biaya_oa_lain ? 'Yes' : 'No' }}
              </VChip>
            </VCol>
          </VRow>

          <VDivider class="mt-2" />
          </div>
          <div v-if="showpph" class="mt-2">
            <VRow dense>
              <VCol cols="12" md="4">
                <div class="text-caption">Kode PPH 22 Accurate</div>
                <div class="text-body-2">{{ form.biaya_pph22 || '-' }}</div>
              </VCol>

              <VCol cols="12" md="4">
                <div class="text-caption">Keterangan</div>
                <div class="text-body-2">{{ form.keterangan_pph22 || '-' }}</div>
              </VCol>

              <VCol cols="12" md="2">
                <div class="text-caption">Alokasi</div>
                <VChip size="small" :color="form.alokasi_pph22 ? 'success' : 'error'">
                  {{ form.alokasi_pph22 ? 'Yes' : 'No' }}
                </VChip>
              </VCol>
            </VRow>

            <VDivider class="mt-2" />
          </div>
          <div v-if="showpbbkb" class="mt-2">
            <VRow dense>
              <VCol cols="12" md="4">
                <div class="text-caption">Kode PBBKB Accurate</div>
                <div class="text-body-2">{{ form.biaya_pbbkb || '-' }}</div>
              </VCol>

              <VCol cols="12" md="4">
                <div class="text-caption">Keterangan</div>
                <div class="text-body-2">{{ form.keterangan_pbbkb || '-' }}</div>
              </VCol>

              <VCol cols="12" md="2">
                <div class="text-caption">Alokasi</div>
                <VChip size="small" :color="form.alokasi_pbbkb ? 'success' : 'error'">
                  {{ form.alokasi_pbbkb ? 'Yes' : 'No' }}
                </VChip>
              </VCol>
            </VRow>

            <VDivider class="mt-2" />
          </div>
          <div v-if="showMigas" class="mt-2">
            <VRow dense>
              <VCol cols="12" md="4">
                <div class="text-caption">Kode PBBKB Accurate</div>
                <div class="text-body-2">{{ form.biaya_migas || '-' }}</div>
              </VCol>

              <VCol cols="12" md="4">
                <div class="text-caption">Keterangan</div>
                <div class="text-body-2">{{ form.keterangan_migas || '-' }}</div>
              </VCol>

              <VCol cols="12" md="2">
                <div class="text-caption">Alokasi</div>
                <VChip size="small" :color="form.alokasi_migas ? 'success' : 'error'">
                  {{ form.alokasi_migas ? 'Yes' : 'No' }}
                </VChip>
              </VCol>
            </VRow>

            <VDivider class="mt-2" />
          </div>
      </VCard>

        <!-- ================= HARGA ================= -->
        <div class="text-subtitle-1 font-weight-bold mb-2">
          Ringkasan Harga
        </div>

        <VCard class="pa-5 mb-6">

          <div class="mb-4">
            <div class="text-caption text-black">Volume PO</div>
            <div class="text-black font-weight-semibold">
              {{ formatMoney(form.volume_po) || 0 }} Liter
            </div>
          </div>

          <div class="mb-4">
            <div class="text-caption text-black">Harga Dasar</div>
            <div class="text-black font-weight-semibold">
              Rp {{ formatMoney(form.harga_tebus) || 0 }}
            </div>
          </div>

          <div v-if="showBiayaOA" class="mb-0">
            <div class="text-caption text-black">Ongkos Angkut</div>
            <div class="text-black font-weight-semibold">
              Rp {{ formatMoney(form.ongkos_angkut) || 0 }}
            </div>
          </div>

        </VCard>

        <!-- ================= PAJAK ================= -->
        <div class="text-subtitle-1 font-weight-bold mb-3">
          Pajak & Biaya
        </div>

        <VCard class="pa-5 mb-2">

          <div class="mb-3 d-flex justify-space-between">
            <span class="text-black">Subtotal</span>
            <strong class="text-success">Rp {{ formatMoney(subtotal) }}</strong>
          </div>
          <div class="mb-3 d-flex justify-space-between">
            <span class="text-black">PPN</span>
            <strong>Rp {{ formatMoney(ppn) }}</strong>
          </div>

          <div class="mb-3 d-flex justify-space-between">
            <span class="text-black">DPP 11/12</span>
            <strong>Rp {{ formatMoney(dpp) }}</strong>
          </div>

          <div v-if="showpph" class="mb-3 d-flex justify-space-between">
            <span class="text-black">PPH 22</span>
            <strong class="text-red">Rp {{ formatMoney(pph22) }}</strong>
          </div>

          <div v-if="showpbbkb" class="mb-3 d-flex justify-space-between">
            <span class="text-black">PBBKB</span>
            <strong>Rp {{ formatMoney(pbbkbval) }}</strong>
          </div>

          <div v-if="showMigas" class="d-flex justify-space-between">
            <span class="text-black">Iuran Migas</span>
            <strong>Rp {{ formatMoney(nominalMigas) }}</strong>
          </div>

        </VCard>

        <!-- ================= TOTAL (HIGHLIGHT) ================= -->
        <VCard
          class="pa-5"
          color="primary"
        >
          <div class="d-flex justify-space-between align-center">
            <div class="text-white text-h6 font-weight-bold">
              Total 
            </div>

          <div class="d-flex align-center">
            <span class="text-white text-body-2 text-md-h5 font-weight-bold">
              Rp {{ formatMoney(totalPO ?? 0) }}
            </span>
          </div>
          </div>
        </VCard>

        <!-- ================= CATATAN ================= -->
        <div class="text-subtitle-1 font-weight-bold mt-4 mb-2">
          Catatan
        </div>
        <VCard variant="outlined" class="pa-5">

          <VRow>
            <VCol cols="12" md="6">
             <div class="text-black">Catatan PO :</div>
            <div class="text-black font-weight-semibold">
              {{ form.catatan_po || '-' }}
            </div>
            </VCol>

            <VDivider vertical />

            <VCol cols="12" md="6">
              <div class="text-black">Internal Notes :</div>
            <div class="text-black font-weight-semibold">
              {{ form.internal_notes || '-' }}
            </div>
            </VCol>
            <VCol cols="12" md="6" v-if="form.disposisi_po==4">
              <div class="text-black">Catatan Pengajuan Ulang :</div>
             <div class="text-black font-weight-semibold">
               {{ form.catatan_resubmit || '-' }}
             </div>
             </VCol>
          </VRow>

        </VCard>

      </VCardText>

      <!-- ACTION -->
      <VDivider />

      <VCardActions class="pa-4 d-flex justify-end">

        <VBtn variant="tonal" color="secondary" @click="step = 'form'">
          Revisi PO
        </VBtn>

        <!-- <VBtn color="primary" variant="flat" @click="submit">
          Submit
        </VBtn> -->
        <VBtn
          color="primary" variant="flat"
          @click="dialogSubmit = true"
        >
          Simpan
        </VBtn>

      </VCardActions>

    </VCard>
    </div>

    <VDialog v-model="dialogSubmit" width="400">
      <VCard>
        
        <VCardTitle>
          Konfirmasi
        </VCardTitle>

        <VCardText>
          Apakah yakin ingin menyimpan Purchase Order?
        </VCardText>

        <VCardActions>
          <VSpacer />

          <VBtn
            variant="text"
            @click="dialogSubmit = false"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            variant="flat"
            @click="submit"
          >
            Ya, Simpan
          </VBtn>

        </VCardActions>

      </VCard>
    </VDialog>
    <VDialog
        v-model="isSaving"
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
  </VContainer>
  
</template>

<style>
.custom-select-menu .v-list-item-title {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>