<script lang="ts" setup>
import axios from '@axios';
import { computed, ref } from 'vue'
import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'

const isCurrentPasswordVisible = ref(false)
const isNewPasswordVisible = ref(false)
const isConfirmPasswordVisible = ref(false)

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')

const submitLoading = ref(false)
const isSubmitted = ref(false)

const isOneTimePasswordDialogVisible = ref(false)

const hasMinLength = computed(() => newPassword.value.length >= 8)
const hasLowercase = computed(() => /[a-z]/.test(newPassword.value))
const hasUppercase = computed(() => /[A-Z]/.test(newPassword.value))
const hasNumber = computed(() => /\d/.test(newPassword.value))
const hasSymbol = computed(() => /[!@#$%^&*(),.?":{}|<>_\-+=/\\[\]`;']/ .test(newPassword.value))

const isConfirmPasswordMatch = computed(() => {
  if (!confirmPassword.value)
    return false

  return newPassword.value === confirmPassword.value
})

const passwordRequirements = computed(() => [
  {
    text: 'Panjang minimal 8 karakter',
    valid: hasMinLength.value,
  },
  {
    text: 'Kombinasi huruf kecil',
    valid: hasLowercase.value,
  },
  {
    text: 'Kombinasi huruf besar',
    valid: hasUppercase.value,
  },
  {
    text: 'Setidaknya satu angka',
    valid: hasNumber.value,
  },
  {
    text: 'Setidaknya satu simbol',
    valid: hasSymbol.value,
  },
])

const isPasswordRequirementValid = computed(() => {
  return hasMinLength.value
    && hasLowercase.value
    && hasUppercase.value
    && hasNumber.value
    && hasSymbol.value
})

const passwordStrengthScore = computed(() => {
  let score = 0

  if (hasMinLength.value)
    score += 1

  if (hasLowercase.value)
    score += 1

  if (hasUppercase.value)
    score += 1

  if (hasNumber.value)
    score += 1

  if (hasSymbol.value)
    score += 1

  if (newPassword.value.length >= 12)
    score += 1

  return score
})

const passwordStrength = computed(() => {
  if (!newPassword.value) {
    return {
      label: 'Belum diisi',
      color: 'secondary',
      percent: 0,
      className: '',
    }
  }

  if (passwordStrengthScore.value <= 2) {
    return {
      label: 'Password Lemah',
      color: 'error',
      percent: 25,
      className: 'strength-weak',
    }
  }

  if (passwordStrengthScore.value === 3) {
    return {
      label: 'Password Sedang',
      color: 'warning',
      percent: 50,
      className: 'strength-medium',
    }
  }

  if (passwordStrengthScore.value === 4 || passwordStrengthScore.value === 5) {
    return {
      label: 'Password Kuat',
      color: 'info',
      percent: 75,
      className: 'strength-strong',
    }
  }

  return {
    label: 'Password Sangat Kuat',
    color: 'success',
    percent: 100,
    className: 'strength-very-strong',
  }
})

const isFormValid = computed(() => {
  return Boolean(currentPassword.value)
    && isPasswordRequirementValid.value
    && isConfirmPasswordMatch.value
})

const currentPasswordError = computed(() => {
  if (!isSubmitted.value)
    return ''

  if (!currentPassword.value)
    return 'Password lama wajib diisi.'

  return ''
})

const newPasswordError = computed(() => {
  if (!isSubmitted.value && !newPassword.value)
    return ''

  if (!newPassword.value)
    return 'Password baru wajib diisi.'

  if (!isPasswordRequirementValid.value)
    return 'Password baru belum memenuhi persyaratan.'

  if (currentPassword.value && newPassword.value === currentPassword.value)
    return 'Password baru tidak boleh sama dengan password lama.'

  return ''
})

const confirmPasswordError = computed(() => {
  if (!isSubmitted.value && !confirmPassword.value)
    return ''

  if (!confirmPassword.value)
    return 'Konfirmasi password baru wajib diisi.'

  if (!isConfirmPasswordMatch.value)
    return 'Konfirmasi password baru tidak sama dengan password baru.'

  return ''
})

const resetForm = (): void => {
  currentPassword.value = ''
  newPassword.value = ''
  confirmPassword.value = ''
  isSubmitted.value = false

  isCurrentPasswordVisible.value = false
  isNewPasswordVisible.value = false
  isConfirmPasswordVisible.value = false
}

const submitChangePassword = async (): Promise<void> => {
  isSubmitted.value = true

  if (!isFormValid.value) {
    showErrorToast({
      title: 'Validasi gagal',
      text: 'Mohon lengkapi form dan pastikan password baru sudah memenuhi persyaratan.',
    })

    return
  }

  if (newPassword.value === currentPassword.value) {
    showErrorToast({
      title: 'Password tidak valid',
      text: 'Password baru tidak boleh sama dengan password lama.',
    })

    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Ubah Password?',
    text: 'Pastikan password baru sudah benar. Anda perlu menggunakan password baru pada login berikutnya.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  submitLoading.value = true

  try {
    showLoadingAlert('Mengubah password...', 'Mohon tunggu sebentar.')

    const response = await axios.put('/account/change-password', {
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: confirmPassword.value,
    }, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    if (response.data?.success) {
      showSuccessToast({
        title: 'Berhasil',
        text: response.data?.message || 'Password berhasil diubah.',
      })

      resetForm()

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: response.data?.message || 'Gagal mengubah password.',
    })
  } catch (error: any) {
    closeAlert()

    const message = error.response?.data?.message
      || error.response?.data?.errors?.current_password?.[0]
      || error.response?.data?.errors?.password?.[0]
      || 'Gagal mengubah password.'

    showErrorToast({
      title: 'Gagal',
      text: message,
    })
  } finally {
    submitLoading.value = false
  }
}
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          Ubah Password
        </VCardTitle>

        <VForm @submit.prevent="submitChangePassword">
          <VCardText class="pt-0">
            <VRow class="mb-3">
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="currentPassword"
                  :type="isCurrentPasswordVisible ? 'text' : 'password'"
                  :append-inner-icon="isCurrentPasswordVisible ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                  label="Password lama"
                  placeholder="Masukkan password saat ini"
                  density="compact"
                  variant="outlined"
                  :error="Boolean(currentPasswordError)"
                  :error-messages="currentPasswordError"
                  autocomplete="current-password"
                  @click:append-inner="isCurrentPasswordVisible = !isCurrentPasswordVisible"
                />
              </VCol>
            </VRow>

            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="newPassword"
                  :type="isNewPasswordVisible ? 'text' : 'password'"
                  :append-inner-icon="isNewPasswordVisible ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                  label="Password baru"
                  placeholder="Masukkan password baru"
                  density="compact"
                  variant="outlined"
                  :error="Boolean(newPasswordError)"
                  :error-messages="newPasswordError"
                  autocomplete="new-password"
                  @click:append-inner="isNewPasswordVisible = !isNewPasswordVisible"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="confirmPassword"
                  :type="isConfirmPasswordVisible ? 'text' : 'password'"
                  :append-inner-icon="isConfirmPasswordVisible ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                  label="Konfirmasi password baru"
                  placeholder="Ulangi password baru"
                  density="compact"
                  variant="outlined"
                  :error="Boolean(confirmPasswordError)"
                  :error-messages="confirmPasswordError"
                  autocomplete="new-password"
                  @click:append-inner="isConfirmPasswordVisible = !isConfirmPasswordVisible"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VCardText class="pt-0">
            <div class="password-strength-box">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption text-medium-emphasis">
                  Kekuatan Password
                </span>

                <VChip
                  size="x-small"
                  :color="passwordStrength.color"
                  variant="tonal"
                  class="font-weight-bold"
                >
                  {{ passwordStrength.label }}
                </VChip>
              </div>

              <VProgressLinear
                :model-value="passwordStrength.percent"
                :color="passwordStrength.color"
                height="8"
                rounded
                class="password-strength-progress"
                :class="passwordStrength.className"
              />
            </div>
          </VCardText>

          <VCardText>
            <p class="text-base mt-2 mb-3">
              Persyaratan Password:
            </p>

            <ul class="password-requirement-list">
              <li
                v-for="item in passwordRequirements"
                :key="item.text"
                class="password-requirement-item"
                :class="{
                  'is-valid': item.valid,
                  'is-invalid': !item.valid && newPassword,
                }"
              >
                <VIcon
                  :icon="item.valid ? 'tabler-circle-check' : 'tabler-circle'"
                  size="18"
                  class="requirement-icon"
                />

                <span>{{ item.text }}</span>
              </li>

              <li
                class="password-requirement-item"
                :class="{
                  'is-valid': isConfirmPasswordMatch,
                  'is-invalid': !isConfirmPasswordMatch && confirmPassword,
                }"
              >
                <VIcon
                  :icon="isConfirmPasswordMatch ? 'tabler-circle-check' : 'tabler-circle'"
                  size="18"
                  class="requirement-icon"
                />

                <span>Konfirmasi password harus sama dengan password baru</span>
              </li>
            </ul>
          </VCardText>

          <VCardText class="d-flex flex-wrap gap-4">
            <VBtn
              type="submit"
              class="text-none"
              :loading="submitLoading"
              :disabled="!isFormValid || submitLoading"
            >
              Simpan
            </VBtn>

            <VBtn
              type="button"
              color="secondary"
              variant="tonal"
              class="text-none"
              :disabled="submitLoading"
              @click="resetForm"
            >
              Reset
            </VBtn>
          </VCardText>
        </VForm>
      </VCard>
    </VCol>
  </VRow>

  <EnableOneTimePasswordDialog v-model:isDialogVisible="isOneTimePasswordDialogVisible" />
</template>

<style scoped lang="scss">
.password-requirement-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-left: 0;
  list-style: none;
}

.password-requirement-item {
  display: flex;
  align-items: center;
  gap: 10px;
  color: rgba(var(--v-theme-on-surface), 0.58);
  transition: all 0.25s ease;
  transform: translateX(0);

  .requirement-icon {
    color: rgba(var(--v-theme-on-surface), 0.38);
    transition: all 0.25s ease;
  }

  &.is-valid {
    color: rgb(var(--v-theme-success));
    transform: translateX(4px);

    .requirement-icon {
      color: rgb(var(--v-theme-success));
      transform: scale(1.1);
    }
  }

  &.is-invalid {
    color: rgb(var(--v-theme-error));

    .requirement-icon {
      color: rgb(var(--v-theme-error));
    }
  }
}

.password-strength-box {
  padding: 12px;
  // border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  // border-radius: 12px;
  // background-color: rgba(var(--v-theme-surface-variant), 0.05);
  transition: all 0.25s ease;
}

.password-strength-progress {
  overflow: hidden;
  transition: all 0.25s ease;
}

.password-strength-progress :deep(.v-progress-linear__determinate) {
  transition: width 0.35s ease, background-color 0.25s ease;
}

.strength-weak {
  animation: strengthPulseWeak 0.35s ease;
}

.strength-medium {
  animation: strengthPulseMedium 0.35s ease;
}

.strength-strong {
  animation: strengthPulseStrong 0.35s ease;
}

.strength-very-strong {
  animation: strengthPulseVeryStrong 0.35s ease;
}

@keyframes strengthPulseWeak {
  0% {
    transform: scaleX(0.96);
    opacity: 0.75;
  }

  100% {
    transform: scaleX(1);
    opacity: 1;
  }
}

@keyframes strengthPulseMedium {
  0% {
    transform: scaleX(0.97);
    opacity: 0.8;
  }

  100% {
    transform: scaleX(1);
    opacity: 1;
  }
}

@keyframes strengthPulseStrong {
  0% {
    transform: scaleX(0.98);
    opacity: 0.85;
  }

  100% {
    transform: scaleX(1);
    opacity: 1;
  }
}

@keyframes strengthPulseVeryStrong {
  0% {
    transform: scale(0.98);
    opacity: 0.85;
  }

  60% {
    transform: scale(1.01);
    opacity: 1;
  }

  100% {
    transform: scale(1);
  }
}
</style>