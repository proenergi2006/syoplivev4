export const toUpper = (value: string | null | undefined): string => {
  return String(value ?? '').toUpperCase()
}

export const toLower = (value: string | null | undefined): string => {
  return String(value ?? '').toLowerCase()
}

export const toTitleCase = (value: string | null | undefined): string => {
  return String(value ?? '')
    .toLowerCase()
    .replace(/\b\w/g, char => char.toUpperCase())
}

export const onlyNumber = (value: string | null | undefined): string => {
  return String(value ?? '').replace(/[^0-9]/g, '')
}

export const onlyAlphaNumeric = (value: string | null | undefined): string => {
  return String(value ?? '').replace(/[^a-zA-Z0-9]/g, '')
}

export const onlyAlphaNumericUpper = (value: string | null | undefined): string => {
  return String(value ?? '')
    .replace(/[^a-zA-Z0-9]/g, '')
    .toUpperCase()
}

export const trimText = (value: string | null | undefined): string => {
  return String(value ?? '').trim()
}

export const formatEmail = (value: string | null | undefined): string => {
  return String(value ?? '')
    .trim()
    .toLowerCase()
}

/**
 * Validate email format
 */
export const validateEmail = (value: string | null | undefined): boolean => {
  const email = String(value ?? '').trim()

  const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

  return regex.test(email)
}

/**
 * Email validation message
 */
export const emailValidationMessage =
  "Format email tidak valid. Contoh penulisan yang benar: contoh@email.com"

export const formatStatusPKP = (value?: string | null): string => {
  if (!value) return '-'

  const normalized = value.toLowerCase()

  const map: Record<string, string> = {
    pkp: 'PKP',
    non_pkp: 'NON PKP',
  }

  return map[normalized] ?? value
}

export const formatKategoriVendor = (value?: string | null): string => {
  if (!value) return '-'

  const normalized = value.toLowerCase()

  const map: Record<string, string> = {
    trading: 'TRADING',
    non_trading: 'NON TRADING'
  }

  return map[normalized] ?? value
}

export const onlyNumberKeypress = (e: KeyboardEvent): void => {
  const allowedKeys = [
    'Backspace',
    'Delete',
    'ArrowLeft',
    'ArrowRight',
    'Tab',
    'Home',
    'End',
  ]

  if (allowedKeys.includes(e.key) || e.ctrlKey || e.metaKey) {
    return
  }

  if (!/^\d$/.test(e.key)) {
    e.preventDefault()
  }
}

export const sanitizeNumberInput = (
  value: string | number | null | undefined,
  options?: {
    maxLength?: number
  },
): string => {
  const maxLength = options?.maxLength ?? 12
  return String(value ?? '')
    .replace(/[^\d]/g, '')
    .slice(0, maxLength)
}

export const parseNumberInput = (
  value: string | number | null | undefined,
  options?: {
    maxLength?: number
    emptyAsZero?: boolean
  },
): number | null => {
  const maxLength = options?.maxLength ?? 12
  const emptyAsZero = options?.emptyAsZero ?? true

  const raw = sanitizeNumberInput(value, { maxLength })

  if (!raw) {
    return emptyAsZero ? 0 : null
  }

  return Number(raw)
}

export const formatSanitizedNumberInput = (
  value: string | number | null | undefined,
  formatter: (value: number) => string,
  options?: {
    maxLength?: number
    emptyAsZero?: boolean
  },
): {
  raw: string
  numeric: number | null
  formatted: string
} => {
  const maxLength = options?.maxLength ?? 12
  const emptyAsZero = options?.emptyAsZero ?? true

  const raw = sanitizeNumberInput(value, { maxLength })

  if (!raw) {
    return {
      raw: '',
      numeric: emptyAsZero ? 0 : null,
      formatted: '',
    }
  }

  const numeric = Number(raw)

  return {
    raw,
    numeric,
    formatted: formatter(numeric),
  }
}

export const getClipboardText = (event: ClipboardEvent): string => {
  return event.clipboardData?.getData('text') || ''
}

export const formatCurrency = (value: number | string | null | undefined): string => {
  const numberValue = Number(value || 0)

  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(numberValue)
}

export const formatNumberWithoutRp = (
  value: number | string | null | undefined,
): string => {
  const numberValue = Number(value || 0)

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(numberValue)
}

export const unformatMoney = (value: string | number | null): number => {
  if (!value) return 0

  return Number(String(value).replace(/[^\d]/g, '')) || 0
}

export const formatDate = (value: string | null): string => {
  if (!value) return '-'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

export const sanitizeDecimalInput = (
  value: string | number | null | undefined,
  options: {
    maxIntegerLength?: number
    maxDecimalLength?: number
    allowZero?: boolean
  } = {},
): string => {
  const {
    maxIntegerLength = 12,
    maxDecimalLength = 2,
    allowZero = false,
  } = options

  let raw = String(value ?? '')

  raw = raw.replace(',', '.')
  raw = raw.replace(/[^\d.]/g, '')

  const parts = raw.split('.')
  let integerPart = parts[0] ?? ''
  let decimalPart = parts.slice(1).join('') ?? ''

  integerPart = integerPart.replace(/^0+(?=\d)/, '')
  integerPart = integerPart.slice(0, maxIntegerLength)
  decimalPart = decimalPart.slice(0, maxDecimalLength)

  if (!integerPart && decimalPart) {
    integerPart = '0'
  }

  if (!allowZero && integerPart === '0' && !decimalPart) {
    return '0'
  }

  return raw.includes('.')
    ? `${integerPart || '0'}.${decimalPart}`
    : integerPart
}

export const parseDecimalInput = (
  value: string | number | null | undefined,
): number => {
  const normalized = String(value ?? '')
    .replace(',', '.')
    .replace(/[^\d.]/g, '')

  const parsed = Number(normalized)

  return Number.isFinite(parsed) ? parsed : 0
}

export const formatDecimalQty = (
  value: string | number | null | undefined,
  maxDecimal = 2,
): string => {
  const numberValue = Number(value ?? 0)

  if (!Number.isFinite(numberValue)) return '0'

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: maxDecimal,
  }).format(numberValue)
}