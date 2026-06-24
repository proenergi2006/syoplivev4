import { onBeforeUnmount, onMounted } from 'vue'

interface UsePollingOptions {
  interval?: number
  immediate?: boolean
}

export const usePolling = (
  callback: () => Promise<void> | void,
  options: UsePollingOptions = {},
) => {
  const interval = options.interval ?? 30000
  const immediate = options.immediate ?? true

  let pollingTimer: ReturnType<typeof setInterval> | null = null

  const startPolling = (): void => {
    if (pollingTimer) return

    pollingTimer = setInterval(async () => {
      if (document.hidden) return

      await callback()
    }, interval)
  }

  const stopPolling = (): void => {
    if (!pollingTimer) return

    clearInterval(pollingTimer)
    pollingTimer = null
  }

  const handleVisibilityChange = async (): Promise<void> => {
    if (document.hidden) {
      stopPolling()
    } else {
      await callback()
      startPolling()
    }
  }

  onMounted(async () => {
    if (immediate) {
      await callback()
    }

    startPolling()

    document.addEventListener(
      'visibilitychange',
      handleVisibilityChange,
    )
  })

  onBeforeUnmount(() => {
    stopPolling()

    document.removeEventListener(
      'visibilitychange',
      handleVisibilityChange,
    )
  })

  return {
    startPolling,
    stopPolling,
  }
}