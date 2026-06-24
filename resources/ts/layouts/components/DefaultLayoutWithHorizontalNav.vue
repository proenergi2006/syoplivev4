<script lang="ts" setup>
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'

import type { HorizontalNavItems } from '@/@layouts/types'
import { useNavigationStore } from '@/stores/navigation'
import { useThemeConfig } from '@core/composable/useThemeConfig'
import { themeConfig } from '@themeConfig'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavBarI18n from '@/layouts/components/NavBarI18n.vue'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'

// @layouts plugin
import { HorizontalNavLayout } from '@layouts'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'

const navigationStore = useNavigationStore()

const {
  items: storeNavItems,
  loaded: navigationLoaded,
} = storeToRefs(navigationStore)

const { appRouteTransition } = useThemeConfig()

/*
|--------------------------------------------------------------------------
| Adapter navigation store ke HorizontalNavItems
|--------------------------------------------------------------------------
|
| Navigation store menggunakan VerticalNavItems sebagai source utama.
| Karena struktur menu berasal dari API yang sama dan kompatibel,
| cast dilakukan hanya pada boundary HorizontalNavLayout.
|--------------------------------------------------------------------------
*/
const navItems = computed<HorizontalNavItems>(() => {
  return storeNavItems.value as unknown as HorizontalNavItems
})

/*
|--------------------------------------------------------------------------
| Memaksa HorizontalNavLayout dirender ulang ketika menu berubah
|--------------------------------------------------------------------------
|
| Ini penting ketika:
| - logout dari akun lama;
| - login dengan akun baru;
| - menu berdasarkan role/permission berubah.
|--------------------------------------------------------------------------
*/
const navigationRenderKey = computed<string>(() => {
  return JSON.stringify(storeNavItems.value)
})

/*
|--------------------------------------------------------------------------
| Memuat menu ketika layout pertama kali dibuka
|--------------------------------------------------------------------------
*/
onMounted(async () => {
  /*
  | Gunakan cache localStorage lebih dahulu bila store belum pernah dimuat.
  */
  if (!navigationLoaded.value)
    navigationStore.loadFromLocal()

  /*
  | Jika cache kosong tetapi user sudah login, ambil ulang dari API.
  */
  if (
    storeNavItems.value.length === 0
    && localStorage.getItem('access_token')
  ) {
    try {
      await navigationStore.fetchFromApi(true)
    }
    catch (error: unknown) {
      console.error(
        'Gagal memuat horizontal navigation:',
        error,
      )
    }
  }
})
</script>

<template>
  <HorizontalNavLayout
    :key="navigationRenderKey"
    :nav-items="navItems"
  >
    <!-- 👉 Navbar -->
    <template #navbar>
      <RouterLink
        to="/"
        class="d-flex align-center gap-x-3"
      >
        <VNodeRenderer :nodes="themeConfig.app.logo" />

        <h1 class="font-weight-medium leading-normal text-xl text-uppercase">
          {{ themeConfig.app.title }}
        </h1>
      </RouterLink>

      <VSpacer />

      <NavSearchBar trigger-btn-class="ms-lg-n3" />

      <NavBarI18n />
      <NavbarThemeSwitcher />
      <NavbarShortcuts />
      <NavBarNotifications class="me-2" />
      <UserProfile />
    </template>

    <!-- 👉 Pages -->
    <RouterView v-slot="{ Component, route }">
      <Transition
        :name="appRouteTransition"
        mode="out-in"
      >
        <Component
          :is="Component"
          :key="route.path"
        />
      </Transition>
    </RouterView>

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

    <!-- 👉 Customizer -->
    <TheCustomizer />
  </HorizontalNavLayout>
</template>