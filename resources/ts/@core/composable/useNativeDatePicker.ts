import { computed, nextTick, ref, type Ref } from 'vue'

export const formatDateDisplay = (value?: string | null): string => {
  if (!value) return ''

  const [year, month, day] = value.split('-')
  if (!year || !month || !day) return value

  return `${day}/${month}/${year}`
}

export const useNativeDatePicker = (model: Ref<string | null | undefined>) => {
  const nativeDateRef = ref<HTMLInputElement | null>(null)

  const displayValue = computed(() => {
    return formatDateDisplay(model.value)
  })

  const openPicker = () => {
    nextTick(() => {
      const input = nativeDateRef.value

      if (!input) return

      if (typeof input.showPicker === 'function') {
        input.showPicker()
      } else {
        input.click()
      }
    })
  }

  const onDateChange = (event: Event) => {
    const input = event.target as HTMLInputElement
    model.value = input.value
  }

  return {
    nativeDateRef,
    displayValue,
    openPicker,
    onDateChange,
  }
}