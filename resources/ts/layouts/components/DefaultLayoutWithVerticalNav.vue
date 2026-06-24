<script lang="ts" setup>
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useWindowSize } from '@vueuse/core'

import { useNavigationStore } from '@/stores/navigation'
import { useThemeConfig } from '@core/composable/useThemeConfig'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavBarI18n from '@/layouts/components/NavBarI18n.vue'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import NavSearchBar from '@/layouts/components/NavSearchBar.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const navigationStore = useNavigationStore()

const {
  items: navItems,
  loaded: navigationLoaded,
} = storeToRefs(navigationStore)

const {
  appRouteTransition,
  isLessThanOverlayNavBreakpoint,
} = useThemeConfig()

const { width: windowWidth } = useWindowSize()

/*
|--------------------------------------------------------------------------
| Key untuk memaksa komponen navigation render ulang ketika menu berubah
|--------------------------------------------------------------------------
*/
const navigationRenderKey = computed(() => JSON.stringify(navItems.value))

onMounted(async () => {
  /*
  |--------------------------------------------------------------------------
  | Jika navigation belum pernah dimuat, ambil dari localStorage lebih dahulu
  |--------------------------------------------------------------------------
  */
  if (!navigationLoaded.value) {
    navigationStore.loadFromLocal()
  }

  /*
  |--------------------------------------------------------------------------
  | Jika localStorage kosong tetapi user sudah login, ambil dari API
  |--------------------------------------------------------------------------
  */
  if (navItems.value.length === 0 && localStorage.getItem('access_token')) {
    try {
      await navigationStore.fetchFromApi(true)
    }
    catch (error) {
      console.error(
        'Gagal memuat menu navigation:',
        error,
      )
    }
  }
})
</script>

<template>
  <VerticalNavLayout
    :key="navigationRenderKey"
    :nav-items="navItems"
  >
    <!-- 👉 navbar -->
    <template #navbar="{ toggleVerticalOverlayNavActive }">
      <div class="d-flex h-100 align-center">
        <VBtn
          v-if="isLessThanOverlayNavBreakpoint(windowWidth)"
          icon
          variant="text"
          color="default"
          class="ms-n3"
          size="small"
          @click="toggleVerticalOverlayNavActive(true)"
        >
          <VIcon
            icon="mdi-menu"
            size="24"
          />
        </VBtn>

        <NavSearchBar class="ms-lg-n3" />

        <VSpacer />

        <NavBarI18n />
        <NavbarThemeSwitcher />
        <NavbarShortcuts />
        <NavBarNotifications class="me-2" />
        <UserProfile />
      </div>
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
  </VerticalNavLayout>
</template>