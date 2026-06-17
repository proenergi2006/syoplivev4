<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()

const requiredPermission = String(route.query.permission || '')
const previousPath = String(route.query.from || '')

const goBack = (): void => {
  if (window.history.length > 1) {
    router.back()

    return
  }

  router.push('/dashboards/crm')
}

const goDashboard = (): void => {
  router.push('/dashboards/crm')
}
</script>

<template>
  <section class="forbidden-page">
    <VCard class="forbidden-card rounded-lg">
      <VCardText class="pa-8 pa-md-12 text-center">
        <VAvatar
          color="error"
          variant="tonal"
          size="76"
          class="mb-5"
        >
          <VIcon
            icon="tabler-lock-access"
            size="38"
          />
        </VAvatar>

        <h1 class="text-h4 font-weight-bold mb-3">
          Akses Ditolak
        </h1>

        <p class="text-body-1 text-medium-emphasis mb-2">
          Anda tidak memiliki permission untuk membuka halaman ini.
        </p>

        <p
          v-if="requiredPermission"
          class="text-body-2 text-medium-emphasis mb-6"
        >
          Permission yang dibutuhkan:
          <VChip
            color="error"
            variant="tonal"
            size="small"
            class="ms-1"
          >
            {{ requiredPermission }}
          </VChip>
        </p>

        <div class="d-flex flex-wrap justify-center gap-3 mt-6">
          <VBtn
            variant="tonal"
            color="secondary"
            prepend-icon="tabler-arrow-left"
            class="text-none"
            @click="goBack"
          >
            Kembali
          </VBtn>

          <VBtn
            color="primary"
            prepend-icon="tabler-layout-dashboard"
            class="text-none"
            @click="goDashboard"
          >
            Dashboard
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </section>
</template>

<style scoped>
.forbidden-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - 180px);
  padding: 24px;
}

.forbidden-card {
  width: 100%;
  max-width: 760px;
}
</style>