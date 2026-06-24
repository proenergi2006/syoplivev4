<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Anchor } from 'vuetify/lib/components'
import axios from '@axios'
import { initialAbility } from '@/plugins/casl/ability'
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import { showConfirmAlert, showErrorToast, showLoadingAlert, closeAlert } from '@/utils/alert'
import { usePermissionStore } from '@/stores/permission'
import { useNavigationStore } from '@/stores/navigation'

const router = useRouter()
const ability = useAppAbility()

const logoutLoading = ref(false)
const permissionStore = usePermissionStore()
const navigationStore = useNavigationStore()

const userData = computed(() => {
  try {
    return JSON.parse(localStorage.getItem('userData') || '{}')
  } catch {
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

const clearAuthStorageAndRedirect = async (): Promise<void> => {
  /*
  |--------------------------------------------------------------------------
  | Kosongkan menu dari memory dan localStorage
  |--------------------------------------------------------------------------
  */
  navigationStore.clearNavigation()

  /*
  |--------------------------------------------------------------------------
  | Bersihkan state permission jika ada
  |--------------------------------------------------------------------------
  */
  try {
    permissionStore.$reset()
  }
  catch (error) {
    console.warn('Gagal reset permission store:', error)
  }

  /*
  |--------------------------------------------------------------------------
  | Hapus data sesi akun lama
  |--------------------------------------------------------------------------
  */
  const keysToRemove = [
    'accessToken',
    'token',
    'userData',
    'userAbilities',
    'navItems',
  ]

  keysToRemove.forEach(key => {
    localStorage.removeItem(key)
    sessionStorage.removeItem(key)
  })

  delete axios.defaults.headers.common.Authorization

  await router.replace('/login')
}

const logout = async (): Promise<void> => {
  if (logoutLoading.value) return

  const confirm = await showConfirmAlert({
    title: 'Keluar dari sistem?',
    text: 'Anda yakin ingin keluar dari aplikasi?',
    confirmButtonText: 'Ya, keluar',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  logoutLoading.value = true

  try {
    showLoadingAlert('Sedang keluar..', 'Mohon tunggu sebentar')

    await axios.post('/auth/logout', {}, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    await clearAuthStorageAndRedirect()
  } catch (error) {
    console.error('LOGOUT ERROR:', error)

    showErrorToast({
      title: 'Logout gagal',
      text: 'Gagal menghubungi server. Anda tetap akan diarahkan ke halaman login.',
    })

    await clearAuthStorageAndRedirect()
  } finally {
    logoutLoading.value = false
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

          <!-- <VListItem :to="{ name: 'apps-user-view-id', params: { id: 21 } }">
            <template #prepend>
              <VIcon
                class="me-2"
                icon="mdi-account-outline"
                size="22"
              />
            </template>

            <VListItemTitle>Profile</VListItemTitle>
          </VListItem> -->

          <VListItem :to="{ name: 'pages-account-settings-tab', params: { tab: 'account' } }">
            <template #prepend>
              <VIcon
                class="me-2"
                icon="mdi-cog-outline"
                size="22"
              />
            </template>

            <VListItemTitle>Settings</VListItemTitle>
          </VListItem>

          <!-- <VListItem :to="{ name: 'pages-faq' }">
            <template #prepend>
              <VIcon
                class="me-2"
                icon="mdi-help-circle-outline"
                size="22"
              />
            </template>

            <VListItemTitle>FAQ</VListItemTitle>
          </VListItem> -->

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