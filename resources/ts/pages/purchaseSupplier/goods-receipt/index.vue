<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'
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
import { onlyNumberKeypress } from '@/utils/textFormatter'
import { usePermissionStore } from '@/stores/permission'

const pageLoading = ref(false)
const route = useRoute()
const router = useRouter()

const id = route.query.id
const search = ref('')

const items = ref<any[]>([])
const gainLossData = ref<any[]>([])
const loading = ref(false)
const grHistory = ref<Array<any>>([])
const showHistory = ref(false)
const gainLossDialog = ref(false)

//file
const fileDialog = ref(false)
const selectedFile = ref<string | undefined>(undefined)
const openFile = (path: string) => {
  selectedFile.value = `/storage/${path}`
  fileDialog.value = true
}

const po = reactive<any>({
  nomor_po: '',
  tanggal_inven: '',
  volume_po: 0,
  volume_bol: 0,

  vendor: '',
  produk: '',
  terminal: '',
  id_po_supplier:'',
  harga_tebus: 0,
  subtotal: 0,
  nama_pic: '',
  tgl_terima: '',
  volume_terima: 0,

  no_terima: '',

  nominal_migas: 0,
  total: 0,
})

const fileUpload = ref(null)
const dialog = ref(false)
const resetForm = () => {
    Object.assign(form, {
        nama_pic: '',
        tgl_terima: '',
        volume_bol: Number(po.volume_po || 0),
        volume_terima: Number(po.volume_po || 0),
        harga_tebus: '',
        no_terima: '',
        keterangan: '',
    })

    fileUpload.value = null
     dialog.value = false
}

const gainLossForm = reactive({
  id_po_supplier: String(id),
  jenis: null,
  volume: 0,
  file: [] as File[],
  ket: '',
})

const fetchGRHistory = async (id:any) => {
  try {
    const res = await axios.get(`/inventory/goods-receipt/history/${id}`)
    grHistory.value = res.data
    showHistory.value = true
  } catch (err) {
    console.error(err)
  }
}

const form = reactive({
  id_po_supplier: '',
  tgl_terima: '',
  no_terima: '',
  nama_pic: '',
  keterangan: '',
  volume_bol: 0,
  volume_terima: 0,
  harga_tebus: 0,
  file: [] as File[],

})

const summary = computed(() => ({
  po: po.volume_po,
  gainLoss: gainLoss.value,
  gainLossAbs: Math.abs(gainLoss.value),
  hargaDasar: po.harga_tebus,
  hargaRi: hargaRi.value,
  subtotal: subtotal.value,
  ppn: ppn.value,
  pph22: pph22.value,
  total: totalOrder.value,
}))
const isSaving = ref(false)

const fetchGR = async () => {
  if (!id) return

  loading.value = true

  try {
    const res = await axios.get(`/inventory/goods-receipt`, {
      params: {
        id_po_supplier: id,
      },
    })

    items.value = res.data.data ?? []
  } catch (err) {
    console.error(err)
    items.value = []
  } finally {
    loading.value = false
  }
}
const fetchGainLoss = async () => {
  if (!id) return

  loading.value = true

  try {
    const res = await axios.get(`/inventory/gain-loss`, {
      params: {
        id_po_supplier: id,
      },
    })

    gainLossData.value = res.data.data ?? []
  } catch (err) {
    console.error(err)
    gainLossData.value = []
  } finally {
    loading.value = false
  }
}
const fetchPO = async (id: any) => {

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
        terminal: data.terminal.nama_terminal,

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
        cfo_result: data.cfo_result,
        cfo_summary: data.cfo_summary,
        cfo_pic: data.cfo_pic,
        cfo_tanggal: data.cfo_tanggal,
        ceo_result: data.ceo_result,
        ceo_pic: data.ceo_pic,
        ceo_tanggal: data.ceo_tanggal,
        ceo_summary: data.ceo_summary,
        revert_ceo_summary: data.revert_ceo_summary,

    })
    form.volume_bol = Number(data.volume_po || 0)
    form.volume_terima = Number(data.volume_po || 0)
    form.harga_tebus = Number(data.harga_tebus || 0)

  } catch (err) {
    console.error(err)
  } finally {
  }
}

const submitGR = async () => {
  const confirm = await showConfirmAlert({
    title: '<h5>Apakah yakin ingin menyimpan data?</h5>',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan data...', 'Mohon menunggu')

    const fd = new FormData()

    fd.append('id_po_supplier', form.id_po_supplier)
    fd.append('tgl_terima', form.tgl_terima)
    fd.append('no_terima', form.no_terima || '')
    fd.append('nama_pic', form.nama_pic)
    fd.append('volume_bol', String(form.volume_bol))
    fd.append('volume_terima', String(form.volume_terima))
    fd.append('harga_tebus', String(form.harga_tebus))
   

    if (form.file?.length > 0) {
      fd.append('file', form.file[0])
    }
      // await axios.post('/inventory/goods-receipt', fd)
      if (isEdit.value) {
        fd.append('keterangan', form.keterangan)
        fd.append('_method', 'PUT')
        await axios.post(`/inventory/goods-receipt/${editId.value}`, fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
      } else {
        await axios.post(
        '/inventory/goods-receipt',
        fd,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        }
        )
      }

      // 2. SUCCESS ALERT (INI POSISI BENAR)
      await showSuccessAlert({
        title: 'Berhasil',
        text: 'GR berhasil disimpan',
        timer: 1500,
      })

      // 3. REFRESH DATA
      await fetchGR()

      // 4. TUTUP DIALOG
      dialog.value = false

  } catch (err) {
    console.error(err)
     await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal menyimpan GR'),
    })

  } finally {
    isSaving.value = false
    closeAlert()
  }
}

const deleteGR = async (id : any) => {
    const confirm = await showConfirmAlert({
    title: '<h5>Apakah yakin ingin menghapus data?</h5>',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })
  if (!confirm.isConfirmed) return
  try {
    showLoadingAlert('Menyimpan data...', 'Mohon menunggu')
    await axios.delete(`/inventory/goods-receipt/${id}`)

     await showSuccessAlert({
      title: 'Berhasil',
      text: `GR berhasil dihapus`,
      timer: 1800,
    })
    await fetchGR()

  } catch (error) {
     await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal menghapus GR'),
    })
  }finally{
    closeAlert()
  }
}

const totalReceive = computed(() => {
  return items.value.reduce(
    (sum, item) => sum + Number(item.volume_terima || 0),
    0,
  )
})
const totalBL = computed(() => {
  return items.value.reduce(
    (sum, item) => sum + Number(item.volume_bol || 0),
    0,
  )
})

const hargaRi = computed(() => {
  const ri = totalReceive.value
  const bl = totalBL.value
  const harga = Number(po.harga_tebus || 0)

  return ri > bl ? Number(((bl * harga) / ri).toFixed(2)): harga
})

const subtotal = computed(() => {
  console.log(totalReceive.value)
  return totalReceive.value * hargaRi.value
})

const ppn = computed(() => subtotal.value * 0.11)

const pph22 = computed(() => subtotal.value * 0.02) // asumsi kalau ini 2%

const pbbkb = computed(() => 0) // kalau ada rumus kasih nanti

const totalOrder = computed(() => {
  return subtotal.value + ppn.value + pbbkb.value - pph22.value
})

const gainLoss = computed(() => {
  return totalReceive.value - totalBL.value
})

const showGainLoss = computed(() => {
  return gainLoss.value !== 0
})

 onMounted(async () =>  {
   pageLoading.value = true

  try {
    await Promise.all([
    fetchPO(id),
    fetchGR(),
    fetchGainLoss()
    ])

    form.id_po_supplier = String(id)
  } finally {
    pageLoading.value = false
  }
})
const formatMoney = (value: number | null): string => {
   if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}

const onlyNumber = (e: KeyboardEvent): void => {
  onlyNumberKeypress(e)
}

const requiredNotZero = (label: string)=> {
  return (v: any) =>
    v !== null &&
    v !== undefined &&
    v !== '' &&
    v !== 0
    || `${label} wajib diisi dan tidak boleh 0`
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

/// edit GR
const isEdit = ref(false)
const editId = ref('')
const openEdit = (item: any) => {
  isEdit.value = true
  editId.value = item.id_po_receive

 
  Object.assign(form, {
    id_po_supplier: item.id_po_supplier,
    tgl_terima: item.tgl_terima,
    no_terima: item.no_terima,
    nama_pic: item.nama_pic,
    volume_bol: Number(item.volume_bol),
    volume_terima: Number(item.volume_terima),
    harga_tebus: Number(item.harga_tebus),
    file: [],
  })
   console.log(form)

  dialog.value = true
}

const MAX_SIZE = 3 * 1024 * 1024 // 3MB

function handleFile(files: File | File[]) {
  const f = Array.isArray(files) ? files[0] : files

  if (!f) return

  if (f.size > 3 * 1024 * 1024) {
    alert('Max 3MB')
    return
  }

 form.file = [f]
}

const submitGainLoss = async () => {
  const confirm = await showConfirmAlert({
    title: 'Simpan Gain / Loss?',
    confirmButtonText: 'Ya',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert('Menyimpan...', 'Mohon tunggu')

    const fd = new FormData()

    fd.append('id_po', String(id))
    fd.append('jenis', String(gainLossForm.jenis))
    fd.append('volume', String(gainLoss.value))
    fd.append('volume_po', String(po.volume_po))
    fd.append('volume_ri', String(totalReceive.value))
    fd.append('keterangan', gainLossForm.ket || '')

    //harga RI = harga tebus(db)
    //harga PO = harga dasar(db)
    fd.append('harga_tebus', String(hargaRi.value))
    fd.append('harga_po', String(po.harga_tebus))
    fd.append('subtotal', String(subtotal.value))
    fd.append('ppn_12', String(ppn.value))
    fd.append('pph_22', String(pph22.value))
    fd.append('total_order', String(totalOrder.value))
    fd.append('harga_po', String(po.harga_tebus))
    fd.append('pbbkb', String(pbbkb.value))
    if (gainLossForm.file?.length) {
      fd.append('file', gainLossForm.file[0])
    }

    await axios.post('/inventory/gain-loss', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    await showSuccessAlert({
      title: 'Berhasil',
      text: 'Gain/Loss tersimpan',
    })

    gainLossDialog.value = false

    // refresh kalau ada list gain loss
    await fetchGainLoss()

  } catch (err) {
    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err),
    })
  } finally {
    closeAlert()
  }
}

const permissionStore = usePermissionStore()
const isCheckingPermission = ref(true)

const canAddGR = computed(() => {
  return permissionStore.can('goods_receipt_trade.create')
})

const canUpdateGR = computed(() => {
  return permissionStore.can('goods_receipt_trade.update')
})

const canDeleteGR = computed(() => {
  return permissionStore.can('goods_receipt_trade.delete')
})
</script>

<template>
    <div>
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
                  Memuat data Good Receipt
            </div>
    
            <div class="text-body-2 text-medium-emphasis">
              Mohon menunggu
            </div>
          </div>
    
        </VCardText>
      </VCard>
      <VContainer v-if="!pageLoading">
    
        <!-- Header -->
        <VCard class="mb-4">
          <VCardText>
    
            <div class="d-flex align-center">
    
              <VAvatar
                color="info"
                rounded
                class="me-3"
                variant="tonal"
              >
                <VIcon icon="tabler-truck" />
              </VAvatar>
    
              <div>
                <h4 class="text-h5 font-weight-bold mb-1">
                  Goods Receipt
                </h4>
    
                <div class="text-medium-emphasis">
                  PO {{ po.nomor_po }}
                </div>
              </div>
    
            </div>
    
          </VCardText>
        </VCard>
        
    
        <!-- Detail PO -->
        <VCard class="mb-4">
          <VCardTitle>Detail Purchase Order</VCardTitle>
    
          <VDivider />
    
          <VCardText>
            <VRow>
              <VCol cols="12" md="6">
                <div class="mb-2">
                  <strong>Nomor PO</strong><br>
                  {{ po.nomor_po }}
                </div>
    
                <div class="mb-2">
                  <strong>Tanggal PO</strong><br>
                  {{ po.tanggal_inven }}
                </div>
    
                <div class="mb-2">
                  <strong>Produk</strong><br>
                  {{ po.produk }}
                </div>
              </VCol>
    
              <VCol cols="12" md="6">
                <div class="mb-2">
                  <strong>Vendor</strong><br>
                  {{ po.vendor }}
                </div>
    
                <div class="mb-2">
                  <strong>Volume PO</strong><br>
                  {{ formatMoney(po.volume_po) }} L
                </div>
    
                <div class="mb-2">
                  <strong>Harga Dasar</strong><br>
                  Rp {{ formatMoney(po.harga_tebus) }}
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
    
        <!-- Data Receive -->
      <VCard class="mb-4">
        <VCardTitle class="d-flex justify-space-between align-center">
    
          <span>List GR</span>
          <VBtn v-if="canAddGR" color="primary" prepend-icon="mdi-plus" size="small" variant="outlined" @click="dialog = true">
            Tambah Data
          </VBtn>
    
        </VCardTitle>
          <VDivider/>
    
        <VTable class="text-no-wrap">
          <thead>
            <tr>
              <th>No</th>
              <th>Tanggal Terima</th>
              <th>Nama PIC</th>
              <th>Harga Tebus</th>
              <th>Volume BL</th>
              <th>Volume Terima</th>
              <th>File</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
    
          <tbody>
             <tr v-if="items.length === 0">
                <td colspan="9" class="text-center py-6 text-medium-emphasis">
                  Data tidak ditemukan
                </td>
              </tr>
            <tr v-for="(item, i) in items" :key="item.id_po_receive">
    
              <td>{{ i + 1 }}</td>
    
              <td>{{ item.tgl_terima }}</td>
    
              <td>{{ item.nama_pic }}</td>
    
              <td>
                {{ new Intl.NumberFormat('id-ID').format(item.harga_tebus) }}
              </td>
    
              <td>{{ formatMoney(item.volume_bol) }}</td>
    
              <td>{{formatMoney(item.volume_terima) }}</td>
    
              <td>
               <VBtn
                v-if="item.file_upload"
                icon="mdi-file"
                size="small"
                variant="text"
                @click="openFile(item.file_upload)"
              />
              </td>
    
              <td>
                <VChip
                  :color="item.is_updated === 1 ? 'success' : 'warning'"
                  size="small"
                >
                 {{ item.lastupdate_by ? 'Last Updated by '+ item.lastupdate_by: '-' }}
                </VChip>
              </td>
    
              <td>
                <VBtn v-if="(Number(item.updated_count) < 3) && canUpdateGR" icon="mdi-file-document-edit-outline" size="small" variant="text" @click="openEdit(item)"/>
              <VBtn
                type="button"
                icon="mdi-delete"
                size="small"
                variant="text"
                color="error"
                @click.stop.prevent="deleteGR(item.id_po_receive)"
              />
                <VBtn v-if="item.is_updated && canDeleteGR" icon="mdi-information" size="small" color="info" variant="text"   @click="fetchGRHistory(item.id_po_receive)"></VBtn>
              </td>
    
            </tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard v-if="showGainLoss">
        <VCardTitle class="d-flex justify-space-between align-center">
          <span>Gain Loss PO</span>
          <VBtn color="primary" prepend-icon="mdi-plus" size="small" variant="outlined" @click="gainLossDialog = true">
            Add Data
          </VBtn>
        </VCardTitle>
    
        <VDivider />
          <VTable class="text-no-wrap">
            <thead>
              <tr>
                <th>No</th>
                <th>Jenis</th>
                <th>Volume</th>
                <th>Keterangan</th>
                <th>Status</th>
              </tr>
            </thead>
    
            <tbody>
              <tr v-for="(item, i) in gainLossData" :key="i">
    
                <td>{{ i + 1 }}</td>
    
                <td>{{ item.jenis == 1 ? 'gain' :'loss' }}</td>
    
                <td>{{ new Intl.NumberFormat('id-ID').format(item.volume) }} L</td>
    
                <td>
                  {{ item.ket }}
                </td>
    
                <td>{{ item.disposisi_gain_loss == 1?'Menunggu Verifikasi': 'Terverifikasi'}}</td>
    
              </tr>
            </tbody>
          </VTable>
      </VCard>
      <VCardActions class="pa-4 d-flex justify-end">
    
        <VBtn
          variant="tonal" color="secondary" @click="router.push('/purchaseSupplier/po-supplier')"
          class="mt-2"
          >
              Kembali
        </VBtn>
      </VCardActions>
      <VDialog v-model="dialog" max-width="900" persistent>
      <VCard>
    
        <!-- HEADER -->
        <VCardTitle class="d-flex align-center justify-space-between">
          <div>
            <div class="text-h6 font-weight-bold">
              {{ isEdit ? 'Edit Goods Receipt' : 'Goods Receipt' }}
            </div>
            <div class="text-caption text-medium-emphasis">
              harap mengisi dengan sesuai
            </div>
          </div>
    
          <VBtn icon="tabler-x" variant="text" @click="dialog = false" />
        </VCardTitle>
    
        <VDivider />
    
        <!-- FORM -->
        <VCardText>
          <VForm ref="formRef">
            <div class="mb-4">
              <!-- <div class="text-subtitle-2 mb-2">Informasi Penerimaan</div> -->
    
              <VRow>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.tgl_terima"
                    label="Tanggal Terima"
                    type="date"
                    density="comfortable"
                    variant="outlined"
                  />
                </VCol>
    
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.no_terima"
                    label="Nomor Terima"
                    prepend-inner-icon="tabler-receipt"
                    density="comfortable"
                    variant="outlined"
                  />
                </VCol>
    
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.nama_pic"
                    label="PIC"
                    placeholder="Nama penanggung jawab"
                    prepend-inner-icon="tabler-user"
                    density="comfortable"
                    variant="outlined"
                  />
                </VCol>
              </VRow>
            </div>
    
            <div class="mb-4">
              <VRow>
                <VCol cols="12" md="4">
                  <VTextField
                  label="Harga Tebus"
                  @keypress="onlyNumber"
                  class="text-end"
                  :model-value="formatMoney(form.harga_tebus)"
                  @update:modelValue="(val: string) => form.harga_tebus = parse(val) || 0"
                  prefix="Rp"
                  :rules="[requiredNotZero('Harga Tebus')]"
                  density="comfortable"
                  variant="outlined"
                />
                </VCol>
    
                <VCol cols="12" md="4">
                  <VTextField
                    label="Volume BL"
                    @keypress="onlyNumber"
                    class="text-end"
                    :model-value="formatMoney(form.volume_bol)"
                    @update:modelValue="(val: string) => form.volume_bol = parse(val) || 0"
                    suffix="Liter"
                    :rules="[requiredNotZero('Volume BL')]"
                    density="comfortable"
                    variant="outlined"
                  />
                </VCol>
    
                <VCol cols="12" md="4">
                   <VTextField
                    label="Volume BL"
                    @keypress="onlyNumber"
                    class="text-end"
                    :model-value="formatMoney(form.volume_terima)"
                    @update:modelValue="(val: string) => form.volume_terima = parse(val) || 0"
                    suffix="Liter"
                    :rules="[requiredNotZero('Volume Terima')]"
                    density="comfortable"
                    variant="outlined"
                  />
                </VCol>
              </VRow>
            </div>
    
            <!-- SECTION 3 -->
            <div>
              <div class="text-subtitle-2 mb-2">File</div>
    
              <VFileInput
                :model-value="form.file"
                @update:modelValue="handleFile"
                label="Upload Dokumen"
                accept=".pdf"
              />
              <!-- <VFileInput
                v-model="form.file"
                label="Upload Dokumen"
                accept=".pdf"
                show-size
                chips
                prepend-icon="tabler-upload"
                density="comfortable"
                variant="outlined"
                @update:modelValue="handleFile"
              /> -->
            </div>
    
            <div v-if="isEdit">
                 <VTextarea
                    v-model="form.keterangan"
                    label="Catatan edit "
                    rows="3"
                    class="mt-3"
                />
    
            </div>
          </VForm>
        </VCardText>
    
        <VDivider />
    
        <!-- ACTION -->
        <VCardActions class="px-6 py-4">
          <VSpacer />
    
          <VBtn
            variant="text"
            color="error"
            @click="resetForm"
          >
            Batal
          </VBtn>
    
          <VBtn
            color="primary"
            variant="flat"
            :loading="loading"
            @click="submitGR"
          >
            Simpan
          </VBtn>
        </VCardActions>
    
      </VCard>
      </VDialog>
      <VDialog v-model="fileDialog" max-width="900">
      <VCard>
    
        <VCardTitle class="d-flex justify-space-between">
          File Preview
    
          <VBtn icon="mdi-close" variant="text" @click="fileDialog = false" />
        </VCardTitle>
    
        <VDivider />
    
        <VCardText style="height: 80vh">
          
          <!-- PDF -->
          <iframe
            v-if="selectedFile?.endsWith('.pdf')"
            :src="selectedFile"
            width="100%"
            height="100%"
          ></iframe>
    
          <!-- IMAGE -->
          <img
            v-else
            :src="selectedFile"
            style="max-width: 100%; max-height: 100%; object-fit: contain"
          />
    
        </VCardText>
    
      </VCard>
      </VDialog>
      <VDialog v-model="showHistory" max-width="800">
        <VCard>
          <VCardTitle>History GR</VCardTitle>
      
          <VCardText>
            <VTable>
              <thead>
                <tr>
                  <th>No</th>
                  <th>Tanggal</th>
                  <th>Nama PIC</th>
                  <th>Volume BL</th>
                  <th>Volume Terima</th>
                  <th>Harga</th>
                  <th>Keterangan</th>
                </tr>
              </thead>
      
              <tbody>
                <tr v-for="(h, i) in grHistory" :key="h.id">
                  <td>{{ i + 1 }}</td>
                  <td>{{ h.tgl_terima }}</td>
                  <td>{{ h.nama_pic }}</td>
                  <td>{{ formatMoney(h.volume_bol) }}</td>
                  <td>{{ formatMoney(h.volume_terima) }}</td>
                  <td>{{ formatMoney(h.harga_tebus) }}</td>
                  <td>{{ h.keterangan_updated }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
      
          <VCardActions>
            <VSpacer />
            <VBtn @click="showHistory = false">Tutup</VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
      <VDialog v-model="gainLossDialog" max-width="1100" persistent>
        <VCard>

          <!-- HEADER -->
          <VCardTitle class="d-flex justify-space-between align-center">
            <div>
              <div class="text-h5 font-weight-bold">
                Add Gain / Loss
              </div>
              <div class="text-caption text-medium-emphasis">
                Input selisih volume penerimaan barang
              </div>
            </div>

            <VBtn icon="tabler-x" variant="text" @click="gainLossDialog = false" />
          </VCardTitle>

          <VDivider />

          <!-- BODY -->
          <VCardText>
            <VRow>

              <!-- ================= LEFT SIDE (SUMMARY FULL) ================= -->
              <VCol cols="12" md="5">

                <!-- PO -->
                <VCard class="mb-2" color="primary" variant="tonal" >
                  <VCardText>
                    <div class="text-caption">Volume PO</div>
                    <div class="text-h6">{{ formatMoney(po.volume_po) }} L</div>
                  </VCardText>
                </VCard>

                <VRow>
                  <VCol cols="12" md="6">
                     <!-- BL -->
                    <VCard class="mb-3" color="info" variant="tonal">
                      <VCardText>
                        <div class="text-caption">Total Volume BL</div>
                        <div class="text-h6">{{ formatMoney(totalBL) }} L</div>
                      </VCardText>
                    </VCard>
                  </VCol>
                  <VCol cols="12" md="6">
                      <!-- TERIMA -->
                      <VCard class="mb-3" color="success" variant="tonal">
                        <VCardText>
                          <div class="text-caption">Total Volume Terima</div>
                          <div class="text-h6">{{ formatMoney(totalReceive) }} L</div>
                        </VCardText>
                      </VCard>
                  </VCol>
                </VRow>
               

             

                <!-- GAIN / LOSS -->
           

                <VDivider class="my-1" />

                <!-- HARGA -->
                <!-- <div class="text-subtitle-2 mb-2">Pricing</div> -->

                <VRow>

                <VCol cols="12" md="6">
    
                  <VCard class="mb-2" variant="tonal">
                    <VCardText>
                      <div class="text-caption">Harga Dasar</div>
                      <div class="font-weight-bold">
                        Rp {{ formatMoney(po.harga_tebus) }}
                      </div>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol cols="12" md="6">
      
                  <VCard class="mb-2" variant="tonal">
                    <VCardText>
                      <div class="text-caption">Harga RI (Adjusted)</div>
                      <div class="font-weight-bold">
                        Rp {{ formatMoney(hargaRi) }}
                      </div>
                    </VCardText>
                  </VCard>
                </VCol>
                </VRow>

                <VDivider/>

                <!-- FINANCE -->
                <VList density="compact">

                  <VListItem>
                    <VListItemTitle>Subtotal</VListItemTitle>
                    <template #append>
                      Rp {{ formatMoney(subtotal) }}
                    </template>
                  </VListItem>

                  <VListItem>
                    <VListItemTitle>PPN (11%)</VListItemTitle>
                    <template #append>
                      Rp {{ formatMoney(ppn) }}
                    </template>
                  </VListItem>

                  <VListItem>
                    <VListItemTitle>PPH 22</VListItemTitle>
                    <template #append>
                      Rp {{ formatMoney(pph22) }}
                    </template>
                  </VListItem>

                  <VListItem v-if="po.pbbkb_po>0">
                    <VListItemTitle>PBBKB</VListItemTitle>
                    <template #append>
                      Rp {{ formatMoney(pbbkb) }}
                    </template>
                  </VListItem>

                  <VDivider class="my-1" />

                  <VListItem>
                    <VListItemTitle class="font-weight-bold">
                      Total Order
                    </VListItemTitle>
                    <template #append>
                      <strong>Rp {{ formatMoney(totalOrder) }}</strong>
                    </template>
                  </VListItem>

                </VList>

              </VCol>

              <!-- ================= RIGHT SIDE (FORM) ================= -->
              <VCol cols="12" md="7">
                <VAlert
                  :type="gainLoss >= 0 ? 'info' : 'error'"
                  variant="tonal"
                  class="mb-4"
                  density="compact"
                >
                  <strong>
                    {{ gainLoss >= 0 ? 'GAIN' : 'LOSS' }}
                  </strong>
                  <div class="text-caption">
                    Selisih: {{ formatMoney(Math.abs(gainLoss)) }} Liter
                  </div>
                </VAlert>
                <VRow>
                  <VCol cols="12" md="6">
                      <VSelect
                        v-model="gainLossForm.jenis"
                        label="Jenis Gain / Loss"
                        class="mb-4"
                        :items="[
                          { title: 'Bertambah / Gain (+)', value: 1 },
                          { title: 'Berkurang / Loss (-)', value: 2 }
                        ]"
                        prepend-inner-icon="tabler-arrows-diff"
                      />
                  </VCol>
                  <VCol cols="12" md="6">
                     <VTextField
                        label="Volume Gain / Loss"
                        class="mb-4"
                        suffix="Liter"
                        readonly
                        :model-value="formatMoney(Math.abs(gainLoss))"
                        prepend-inner-icon="tabler-droplet"
                      />
                  </VCol>

                </VRow>

                <VFileInput
                  label="Upload Dokumen Pendukung"
                  accept=".pdf"
                  class="mb-4"
                  prepend-icon="tabler-upload"
                  show-size
                  chips
                  hint="PDF maksimal 3 MB"
                  persistent-hint
                  v-model="gainLossForm.file"
                />

                <VTextarea
                 v-model="gainLossForm.ket"
                  label="Keterangan"
                  rows="4"
                  class="mb-4"
                  auto-grow
                />

              </VCol>

            </VRow>
          </VCardText>

          <VDivider />

          <!-- ACTION -->
          <VCardActions class="px-4 py-2">
            <VSpacer />

            <VBtn variant="tonal" color="secondary" @click="gainLossDialog = false">
              Batal
            </VBtn>

            <VBtn color="primary" prepend-icon="tabler-device-floppy" variant="flat"   @click="submitGainLoss">
              Simpan
            </VBtn>
          </VCardActions>

        </VCard>
      </VDialog>
      </VContainer>
    </div>

</template>
<style>
.swal2-container.swal2-center {
  z-index: 99999 !important;
}
</style>