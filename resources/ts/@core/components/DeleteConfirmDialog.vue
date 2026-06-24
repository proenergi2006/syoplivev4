<script setup lang="ts">
import { useDeleteConfirm } from '@core/composable/useDeleteConfirm'

const {
  deleteDialog,
  deleteLoading,
  deleteConfig,
  closeDeleteConfirm,
  confirmDelete,
} = useDeleteConfirm()
</script>

<template>
  <VDialog
    v-model="deleteDialog"
    max-width="460"
  >
    <VCard>
      <VCardTitle class="text-h6">
        {{ deleteConfig?.title ?? 'Hapus Data?' }}
      </VCardTitle>

      <VCardText>
        <div v-html="deleteConfig?.message ?? 'Apakah Anda yakin ingin menghapus data ini?'" />

        <div class="mt-2 text-error">
          Data yang dihapus tidak dapat dikembalikan.
        </div>
      </VCardText>

      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          color="secondary"
          :disabled="deleteLoading"
          @click="closeDeleteConfirm"
          class="text-none"
        >
          Batal
        </VBtn>

        <VBtn
          color="error"
          :loading="deleteLoading"
          @click="confirmDelete"
          class="text-none"
        >
          {{ deleteConfig?.confirmText ?? 'Ya, Hapus' }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>