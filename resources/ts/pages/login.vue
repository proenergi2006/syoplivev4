<script setup lang="ts">
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import axios from '@axios'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'
import { requiredValidator } from '@validators'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { VForm } from 'vuetify/components'

import authBg from '@images/pages/bg.png'

const isPasswordVisible = ref(false)
const refVForm = ref<VForm>()
const loginLoading = ref(false)
const username = ref('')
const password = ref('')
const rememberMe = ref(false)

const errors = ref<Record<string, string | undefined>>({
  username: undefined,
  password: undefined,
})

const route = useRoute()
const router = useRouter()
const ability = useAppAbility()

const login = async () => {
  if (loginLoading.value) return

  loginLoading.value = true
  try {
    const { data } = await axios.post('/auth/login', {
      username: username.value,
      password: password.value,
    })

    localStorage.setItem('accessToken', data.token)

    axios.defaults.headers.common.Authorization = `Bearer ${data.token}`

    const meRes = await axios.get('/auth/me', {
      headers: {
        Accept: 'application/json',
      },
    })

    const authUser = meRes.data?.data || meRes.data?.user || data.user

    localStorage.setItem(
      'userData',
      JSON.stringify({
        id: authUser.id,
        name: authUser.name,
        username: authUser.username,
        email: authUser.email,

        role_id: authUser.role_id,
        role: authUser.role || authUser.role_name || '-',
        roles: authUser.roles || [],
        role_code: authUser.role_code || authUser.role_kode || null,

        cabang_id: authUser.cabang_id,
        cabang: authUser.cabang,

        department_id: authUser.department_id,
        department: authUser.department,
      }),
    )

    const abilities = [{ action: 'manage', subject: 'all' }]
    localStorage.setItem('userAbilities', JSON.stringify(abilities))
    ability.update(abilities)

    try {
      const menuRes = await axios.get('/auth/my-menus')
      localStorage.setItem('navItems', JSON.stringify(menuRes.data))
    } catch (err) {
      console.warn('Fetch menu failed, redirect anyway:', err)
    }

    const redirectTo = (route.query.to as string) || '/dashboards/crm'
    router.replace(redirectTo)
  } catch (e: any) {
    const res = e?.response
    console.error('LOGIN ERROR:', res?.status, res?.data || e)

    errors.value = {
      username: undefined,
      password: undefined,
    }

    if (res?.status === 422 && res.data?.errors) {
      errors.value = {
        username: res.data.errors.username?.[0],
        password: res.data.errors.password?.[0],
      }
      return
    }

    if (res?.status === 401) {
      errors.value = {
        username: undefined,
        password: undefined,
      }

      if (res.data?.field === 'username') {
        errors.value.username = res.data.message
      }

      if (res.data?.field === 'password') {
        errors.value.password = res.data.message
      }

      return
    }

    errors.value = {
      username: 'Login gagal, cek console/network',
      password: 'Login gagal, cek console/network',
    }
  } finally {
    loginLoading.value = false
  }
}

const onSubmit = () => {
  refVForm.value?.validate().then(({ valid }) => {
    if (valid) login()
  })
}
</script>

<template>
  <div class="auth-page">
    <!-- <div class="auth-logo d-flex align-start gap-x-3">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="font-weight-medium leading-normal text-2xl text-uppercase">
        {{ themeConfig.app.title }}
      </h1>
    </div> -->

    <VRow
      no-gutters
      class="auth-wrapper"
    >
      <VCol
        lg="8"
        class="d-none d-lg-flex auth-left"
      >
        <VImg
          :src="authBg"
          cover
          class="auth-bg"
        />
      </VCol>

      <VCol
        cols="12"
        lg="4"
        class="auth-card-v2 d-flex align-center justify-center"
      >
        <VCard
          flat
          :max-width="500"
          class="mt-12 mt-sm-0 pa-4"
        >
          <VCardText>
            <h5 class="text-h5 mb-1">
              System Operasional
            </h5>
            <p class="mb-0">
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
                    :rules="[requiredValidator]"
                    :error-messages="errors.username"
                    prepend-inner-icon="mdi-account-outline"
                    autocomplete="username"
                  />
                </VCol>

                <VCol cols="12">
                  <VTextField
                    v-model="password"
                    label="Password"
                    :rules="[requiredValidator]"
                    :type="isPasswordVisible ? 'text' : 'password'"
                    :error-messages="errors.password"
                    :append-inner-icon="isPasswordVisible ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                    prepend-inner-icon="mdi-lock-outline"
                    autocomplete="current-password"
                    @click:append-inner="isPasswordVisible = !isPasswordVisible"
                  />

                  <div class="d-flex align-center justify-space-between mt-1 mb-4">
                    <!-- <VCheckbox
                      v-model="rememberMe"
                      label="Remember me"
                    /> -->
                  </div>

                  <VBtn
                    block
                    type="submit"
                    :loading="loginLoading"
                    :disabled="loginLoading"
                  >
                    Login
                  </VBtn>
                </VCol>

                <VCol
                  cols="12"
                  class="d-flex align-center"
                >
                  <VDivider />
                  <span class="mx-4">-</span>
                  <VDivider />
                </VCol>

                <VCol
                  cols="12"
                  class="text-center mt-6"
                >
                  <div class="text-body-2 font-weight-medium text-primary">
                    SYOP Version 4.0
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    Proenergi Operational System
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
  min-height: 100vh;
}

.auth-wrapper {
  min-height: 100vh;
}

.auth-left {
  position: relative;
  padding: 0 !important;
  overflow: hidden;
  min-height: 100vh;
}

.auth-bg {
  width: 100%;
  height: 100vh;
}
</style>

<route lang="yaml">
meta:
  layout: blank
  action: read
  subject: Auth
  redirectIfLoggedIn: true
</route>