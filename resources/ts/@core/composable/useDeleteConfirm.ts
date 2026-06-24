import { ref } from 'vue'
import axios from '@axios'
import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'

export const deleteDialog = ref(false)
export const deleteLoading = ref(false)
export const deleteConfig = ref<any>(null)

export interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

export const useDeleteConfirm = () => {
  const openDeleteConfirm = (config: any): void => {
    deleteConfig.value = config
    deleteDialog.value = true
  }

  const closeDeleteConfirm = (): void => {
    if (deleteLoading.value) return

    deleteDialog.value = false
    deleteConfig.value = null
  }

  const confirmDelete = async (): Promise<void> => {
    if (!deleteConfig.value || deleteLoading.value) return

    const config = deleteConfig.value

    deleteDialog.value = false
    deleteLoading.value = true

    try {
        showLoadingAlert(
        config.loadingTitle ?? 'Menghapus data...',
        config.loadingText ?? 'Mohon tunggu sebentar',
        )

        await axios.delete(config.url)

        closeAlert()

        showSuccessToast({
        title: config.successTitle ?? 'Berhasil',
        text: config.successText ?? 'Data berhasil dihapus',
        })

        if (typeof config.onSuccess === 'function') {
        await config.onSuccess()
        }

        deleteConfig.value = null
    } catch (error) {
        closeAlert()

        const err = error as AxiosErrorShape

        showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(
            err,
            config.errorText ?? 'Gagal menghapus data',
        ),
        })
    } finally {
        deleteLoading.value = false
    }
 }

  return {
    deleteDialog,
    deleteLoading,
    deleteConfig,
    openDeleteConfirm,
    closeDeleteConfirm,
    confirmDelete,
  }
}