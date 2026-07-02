<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Anchor } from 'vuetify/lib/components'

import axios from '@axios'
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import { clearAuthSession } from '@/router'
import { usePermissionStore } from '@/stores/permission'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
} from '@/utils/alert'

const ability = useAppAbility()
const permissionStore = usePermissionStore()

const logoutLoading = ref(false)

/*
|--------------------------------------------------------------------------
| URL SYOP lama
|--------------------------------------------------------------------------
| Local:
| VITE_SYOP_LEGACY_URL=http://localhost/proenergidemolive
|
| Production:
| VITE_SYOP_LEGACY_URL=https://syop.proenergi.com
|--------------------------------------------------------------------------
*/
const legacySyopUrl = String(
  import.meta.env.VITE_SYOP_LEGACY_URL || '',
).trim()

const userData = computed(() => {
  try {
    return JSON.parse(
      localStorage.getItem('userData') || '{}',
    )
  }
  catch {
    return {}
  }
})

const displayName = computed(() => {
  return userData.value?.name
    || userData.value?.fullName
    || userData.value?.full_name
    || userData.value?.username
    || 'User'
})

const displayRole = computed(() => {
  return userData.value?.role
    || userData.value?.role_name
    || userData.value?.role_code
    || '-'
})

const avatarUrl = computed(() => {
  return userData.value?.avatar || null
})

/*
|--------------------------------------------------------------------------
| Kembali ke SYOP lama
|--------------------------------------------------------------------------
| Tidak melakukan logout SYOP V4.
| Session kedua aplikasi tetap tersimpan secara terpisah.
|--------------------------------------------------------------------------
*/
const goToLegacySyop = (): void => {
  if (!legacySyopUrl) {
    showErrorToast({
      title: 'URL belum dikonfigurasi',
      text: 'Alamat SYOP lama belum tersedia pada konfigurasi aplikasi.',
    })

    return
  }

  try {
    /*
     * Mendukung URL absolut maupun path relatif.
     */
    const targetUrl = new URL(
      legacySyopUrl,
      window.location.origin,
    )

    if (
      targetUrl.protocol !== 'http:'
      && targetUrl.protocol !== 'https:'
    ) {
      throw new Error('Protocol URL tidak didukung.')
    }

    /*
     * Menggunakan full browser navigation karena berpindah aplikasi.
     */
    window.location.assign(targetUrl.toString())
  }
  catch (error) {
    console.error(
      'INVALID LEGACY SYOP URL:',
      error,
    )

    showErrorToast({
      title: 'Gagal membuka SYOP lama',
      text: 'Alamat SYOP lama tidak valid.',
    })
  }
}

const clearAuthStorageAndRedirect = (): void => {
  /*
  |--------------------------------------------------------------------------
  | Bersihkan Pinia permission
  |--------------------------------------------------------------------------
  */
  permissionStore.clearPermissions()

  /*
  |--------------------------------------------------------------------------
  | Bersihkan ability CASL yang masih aktif di memory
  |--------------------------------------------------------------------------
  */
  ability.update([])

  /*
  |--------------------------------------------------------------------------
  | Bersihkan cookie, localStorage, sessionStorage,
  | dan Authorization header Axios
  |--------------------------------------------------------------------------
  */
  clearAuthSession()

  /*
  |--------------------------------------------------------------------------
  | Hard redirect
  |--------------------------------------------------------------------------
  | Hard redirect menghentikan polling, watcher, dan request
  | dari halaman sebelumnya.
  |--------------------------------------------------------------------------
  */
  window.location.replace('/login?logged_out=1')
}

const logout = async (): Promise<void> => {
  if (logoutLoading.value)
    return

  const confirmResult = await showConfirmAlert({
    title: 'Keluar dari sistem?',
    text: 'Anda yakin ingin keluar dari aplikasi?',
    confirmButtonText: 'Ya, keluar',
    cancelButtonText: 'Batal',
  })

  if (!confirmResult.isConfirmed)
    return

  logoutLoading.value = true

  let logoutApiFailed = false

  try {
    showLoadingAlert(
      'Sedang keluar...',
      'Mohon tunggu sebentar',
    )

    /*
    |--------------------------------------------------------------------------
    | Cabut token Sanctum dari backend
    |--------------------------------------------------------------------------
    */
    await axios.post(
      '/auth/logout',
      {},
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )
  }
  catch (error) {
    logoutApiFailed = true

    console.error(
      'LOGOUT ERROR:',
      error,
    )
  }
  finally {
    /*
    |--------------------------------------------------------------------------
    | Tutup loading alert sebelum aplikasi di-reload
    |--------------------------------------------------------------------------
    */
    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Jika backend gagal, logout lokal tetap dilakukan
    |--------------------------------------------------------------------------
    */
    if (logoutApiFailed) {
      console.warn(
        'Logout backend gagal, tetapi session lokal tetap dibersihkan.',
      )
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup lokal dan hard redirect
    |--------------------------------------------------------------------------
    */
    clearAuthStorageAndRedirect()
  }
}

const avatarBadgeProps = {
  dot: true,
  location: 'bottom right' as Anchor,
  offsetX: 3,
  offsetY: 3,
  color: 'success',
  bordered: true,
}
</script>

<template>
  <VBadge v-bind="avatarBadgeProps">
    <VAvatar
      class="cursor-pointer"
      color="primary"
      variant="tonal"
    >
      <VImg
        v-if="avatarUrl"
        :src="avatarUrl"
      />

      <VIcon
        v-else
        icon="mdi-account-outline"
      />

      <VMenu
        activator="parent"
        width="260"
        location="bottom end"
        offset="14px"
      >
        <VList>
          <!-- User information -->
          <VListItem>
            <template #prepend>
              <VListItemAction start>
                <VBadge v-bind="avatarBadgeProps">
                  <VAvatar
                    color="primary"
                    size="42"
                    variant="tonal"
                  >
                    <VImg
                      v-if="avatarUrl"
                      :src="avatarUrl"
                    />

                    <VIcon
                      v-else
                      icon="mdi-account-outline"
                    />
                  </VAvatar>
                </VBadge>
              </VListItemAction>
            </template>

            <VListItemTitle class="font-weight-medium">
              {{ displayName }}
            </VListItemTitle>

            <VListItemSubtitle>
              {{ displayRole }}
            </VListItemSubtitle>
          </VListItem>

          <VDivider class="my-2" />

          <!-- Kembali ke SYOP lama -->
          <VListItem
            link
            :disabled="logoutLoading"
            @click="goToLegacySyop"
          >
            <template #prepend>
              <VIcon
                class="me-2"
                icon="mdi-arrow-left-circle-outline"
                size="22"
              />
            </template>

            <VListItemTitle>
              Kembali ke SYOP v3
            </VListItemTitle>
          </VListItem>

          <!-- Settings -->
          <VListItem
            :to="{
              name: 'pages-account-settings-tab',
              params: {
                tab: 'account',
              },
            }"
          >
            <template #prepend>
              <VIcon
                class="me-2"
                icon="mdi-cog-outline"
                size="22"
              />
            </template>

            <VListItemTitle>
              Settings
            </VListItemTitle>
          </VListItem>

          <!-- Logout -->
          <VListItem
            link
            :disabled="logoutLoading"
            @click="logout"
          >
            <template #prepend>
              <VIcon
                class="me-2"
                icon="mdi-logout-variant"
                size="22"
              />
            </template>

            <VListItemTitle>
              {{ logoutLoading ? 'Logging out...' : 'Logout' }}
            </VListItemTitle>
          </VListItem>
        </VList>
      </VMenu>
    </VAvatar>
  </VBadge>
</template>