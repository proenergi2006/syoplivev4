<script setup lang="ts">
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import axios from '@axios'
import loginBackground from '@images/pages/bg2.png'
import { requiredValidator } from '@validators'

import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { VForm } from 'vuetify/components'

const isPasswordVisible = ref(false)
const refVForm = ref<InstanceType<typeof VForm> | null>(null)
const loginLoading = ref(false)

const username = ref('')
const password = ref('')

const errors = ref<Record<'username' | 'password', string | undefined>>({
  username: undefined,
  password: undefined,
})

const route = useRoute()
const router = useRouter()
const ability = useAppAbility()

/**
 * Menghapus pesan error ketika user mulai mengetik kembali.
 */
watch(username, () => {
  errors.value.username = undefined
})

watch(password, () => {
  errors.value.password = undefined
})

const resetErrors = () => {
  errors.value = {
    username: undefined,
    password: undefined,
  }
}

const saveUserData = (authUser: any) => {
  const userData = {
    id: authUser?.id ?? null,
    name: authUser?.name ?? '-',
    username: authUser?.username ?? username.value,
    email: authUser?.email ?? null,

    role_id: authUser?.role_id ?? null,
    role: authUser?.role || authUser?.role_name || '-',
    roles: authUser?.roles || [],
    role_code: authUser?.role_code || authUser?.role_kode || null,

    cabang_id: authUser?.cabang_id ?? null,
    cabang: authUser?.cabang ?? null,

    department_id: authUser?.department_id ?? null,
    department: authUser?.department ?? null,
  }

  localStorage.setItem('userData', JSON.stringify(userData))
}

const login = async () => {
  if (loginLoading.value)
    return

  resetErrors()
  loginLoading.value = true

  try {
    /**
     * Proses login.
     */
    const loginResponse = await axios.post('/auth/login', {
      username: username.value,
      password: password.value,
    })

    const token = loginResponse.data?.token

    if (!token)
      throw new Error('Token tidak ditemukan pada response login.')

    /**
     * Simpan token dan pasang pada header Axios.
     */
    localStorage.setItem('accessToken', token)

    axios.defaults.headers.common.Authorization = `Bearer ${token}`

    /**
     * Ambil data user terbaru.
     */
    const meResponse = await axios.get('/auth/me', {
      headers: {
        Accept: 'application/json',
      },
    })

    const authUser
      = meResponse.data?.data
        || meResponse.data?.user
        || loginResponse.data?.user

    if (!authUser)
      throw new Error('Data user tidak ditemukan.')

    saveUserData(authUser)

    /**
     * Untuk sementara seluruh user diberikan ability manage all.
     * Nantinya dapat diganti dengan abilities dari backend.
     */
    const abilities = [
      {
        action: 'manage',
        subject: 'all',
      },
    ]

    localStorage.setItem('userAbilities', JSON.stringify(abilities))
    ability.update(abilities)

    /**
     * Mengambil menu yang diizinkan untuk user.
     * Kegagalan mengambil menu tidak membatalkan login.
     */
    try {
      const menuResponse = await axios.get('/auth/my-menus')

      localStorage.setItem(
        'navItems',
        JSON.stringify(menuResponse.data),
      )
    }
    catch (menuError) {
      console.warn(
        'Fetch menu gagal, proses redirect tetap dilanjutkan:',
        menuError,
      )
    }

    /**
     * Redirect ke halaman yang sebelumnya dituju,
     * atau ke dashboard default.
     */
    const queryRedirect = route.query.to
    const redirectTo
      = typeof queryRedirect === 'string'
        ? queryRedirect
        : '/dashboards/crm'

    await router.replace(redirectTo)
  }
  catch (error: any) {
    const response = error?.response

    console.error(
      'LOGIN ERROR:',
      response?.status,
      response?.data || error,
    )

    resetErrors()

    /**
     * Validation error Laravel.
     */
    if (response?.status === 422 && response.data?.errors) {
      errors.value = {
        username: response.data.errors.username?.[0],
        password: response.data.errors.password?.[0],
      }

      return
    }

    /**
     * Username atau password salah.
     */
    if (response?.status === 401) {
      const field = response.data?.field
      const message = response.data?.message || 'Username atau password salah.'

      if (field === 'username') {
        errors.value.username = message
        return
      }

      if (field === 'password') {
        errors.value.password = message
        return
      }

      errors.value.password = message
      return
    }

    /**
     * Error server atau koneksi.
     */
    if (!response) {
      errors.value.username = 'Tidak dapat terhubung ke server.'
      return
    }

    errors.value.username
      = response.data?.message || 'Login gagal. Silakan coba kembali.'
  }
  finally {
    loginLoading.value = false
  }
}

const onSubmit = async () => {
  const validation = await refVForm.value?.validate()

  if (validation?.valid)
    await login()
}
</script>

<template>
  <div class="auth-page">
    <VRow
      no-gutters
      class="auth-wrapper"
    >
      <!-- Background login -->
      <VCol
        cols="12"
        lg="8"
        class="d-none d-lg-flex auth-left"
      >
        <VImg
          :src="loginBackground"
          alt="Pro Energi Oil and Gas"
          cover
          eager
          class="auth-background-image"
        />
      </VCol>

      <!-- Form login -->
      <VCol
        cols="12"
        lg="4"
        class="auth-card-v2 d-flex align-center justify-center"
      >
        <VCard
          flat
          width="100%"
          max-width="500"
          class="login-card pa-4"
        >
          <VCardText>
            <h1 class="text-h5 mb-1">
              System Operasional
            </h1>

            <p class="mb-0 text-medium-emphasis">
              Please sign in with your account.
            </p>
          </VCardText>

          <VCardText>
            <VForm
              ref="refVForm"
              @submit.prevent="onSubmit"
            >
              <VRow>
                <VCol cols="12">
                  <VTextField
                    v-model="username"
                    label="Username"
                    placeholder="Masukkan username"
                    :rules="[requiredValidator]"
                    :error-messages="errors.username"
                    prepend-inner-icon="mdi-account-outline"
                    autocomplete="username"
                    autofocus
                  />
                </VCol>

                <VCol cols="12">
                  <VTextField
                    v-model="password"
                    label="Password"
                    placeholder="Masukkan password"
                    :rules="[requiredValidator]"
                    :type="isPasswordVisible ? 'text' : 'password'"
                    :error-messages="errors.password"
                    :append-inner-icon="
                      isPasswordVisible
                        ? 'mdi-eye-off-outline'
                        : 'mdi-eye-outline'
                    "
                    prepend-inner-icon="mdi-lock-outline"
                    autocomplete="current-password"
                    @click:append-inner="
                      isPasswordVisible = !isPasswordVisible
                    "
                  />
                </VCol>

                <VCol cols="12">
                  <VBtn
                    block
                    type="submit"
                    size="large"
                    :loading="loginLoading"
                    :disabled="loginLoading"
                  >
                    Login
                  </VBtn>
                </VCol>

                <VCol
                  cols="12"
                  class="d-flex align-center mt-4"
                >
                  <VDivider />

                  <span class="mx-4 text-medium-emphasis">
                    -
                  </span>

                  <VDivider />
                </VCol>

                <VCol
                  cols="12"
                  class="text-center mt-4"
                >
                  <div class="text-body-2 font-weight-medium text-primary">
                    SYOP Version 4.0
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    Pro Energi Operational System
                  </div>
                </VCol>
              </VRow>
            </VForm>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth.scss";

.auth-page {
  width: 100%;
  min-height: 100vh;
  overflow: hidden;
}

.auth-wrapper {
  width: 100%;
  min-height: 100vh;
  margin: 0 !important;
}

.auth-left {
  position: relative;
  min-height: 100vh;
  padding: 0 !important;
  overflow: hidden;
  background-color: #eef4fa;
}

.auth-background-image {
  width: 100%;
  height: 100vh;
  min-height: 100vh;
}

/*
 * Memastikan gambar VImg memenuhi seluruh area sebelah kiri.
 */
.auth-background-image :deep(.v-img__img) {
  object-fit: cover;
  object-position: center center;
}

.auth-card-v2 {
  min-height: 100vh;
  padding: 24px;
  background-color: rgb(var(--v-theme-surface));
}

.login-card {
  background-color: transparent !important;
}

@media (max-width: 1279px) {
  .auth-card-v2 {
    min-height: 100vh;
    padding: 16px;
  }

  .login-card {
    max-width: 500px !important;
  }
}
</style>

<route lang="yaml">
meta:
  layout: blank
  action: read
  subject: Auth
  redirectIfLoggedIn: true
</route>