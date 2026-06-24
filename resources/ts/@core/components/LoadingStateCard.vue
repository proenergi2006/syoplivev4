<script setup lang="ts">
interface Props {
  title?: string
  subtitle?: string
  color?: string
}

withDefaults(defineProps<Props>(), {
  title: 'Memuat data...',
  subtitle: 'Mohon tunggu sebentar',
  color: 'primary',
})
</script>

<template>
  <VCard
    class="mb-6 rounded-lg"
    elevation="2"
  >
    <VCardText class="pa-6">
      <div class="d-flex align-center">
        <VProgressCircular
          indeterminate
          :color="color"
          size="28"
          width="3"
          class="me-4"
        />

        <div>
          <div class="text-h6 font-weight-medium">
            {{ title }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ subtitle }}
          </div>
        </div>
      </div>
    </VCardText>
  </VCard>
</template>