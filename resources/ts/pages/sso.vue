<script setup lang="ts">
import axios from '@axios'
import { useAppAbility } from '@/plugins/casl/useAppAbility'
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { clearAuthSession } from '@/router'

const route = useRoute()
const router = useRouter()
const ability = useAppAbility()

const loadingStatus = ref('Memverifikasi akses SSO...')
const errorMessage = ref('')

onMounted(async () => {
  try {
    /*
    |--------------------------------------------------------------------------
    | Ambil token SSO terenkripsi dari URL
    |--------------------------------------------------------------------------
    */
    const tokenQuery = route.query.token

    const ssoToken = Array.isArray(tokenQuery)
      ? tokenQuery[0]
      : tokenQuery

    if (!ssoToken) {
      throw new Error('Token SSO tidak ditemukan.')
    }

    /*
    |--------------------------------------------------------------------------
    | Proses login SSO
    |--------------------------------------------------------------------------
    */
    loadingStatus.value = 'Memverifikasi akun...'

    const { data } = await axios.post('/auth/sso', {
      token: ssoToken,
    })

    if (!data.token)
      throw new Error('Token login tidak diterima dari server.')

    /*
    |--------------------------------------------------------------------------
    | Simpan token Sanctum
    |--------------------------------------------------------------------------
    */
   loadingStatus.value = 'Menyiapkan sesi pengguna...'
   localStorage.setItem('authSource', 'syop-v3')

    localStorage.setItem(
      'accessToken',
      data.token,
    )

    axios.defaults.headers.common.Authorization
      = `Bearer ${data.token}`

    /*
    |--------------------------------------------------------------------------
    | Ambil user seperti proses login normal
    |--------------------------------------------------------------------------
    */
    const me = await axios.get('/auth/me')

    const userData = me.data.data

    localStorage.setItem(
      'userData',
      JSON.stringify(userData),
    )

    /*
    |--------------------------------------------------------------------------
    | Ability existing
    |--------------------------------------------------------------------------
    */
    loadingStatus.value = 'Memuat hak akses...'

    const abilities = [
      {
        action: 'manage',
        subject: 'all',
      },
    ]

    localStorage.setItem(
      'userAbilities',
      JSON.stringify(abilities),
    )

    ability.update(abilities)

    /*
    |--------------------------------------------------------------------------
    | Memuat menu
    |--------------------------------------------------------------------------
    */
    loadingStatus.value = 'Menyiapkan menu aplikasi...'

    try {
      const menuResponse = await axios.get(
        '/auth/my-menus',
      )

      localStorage.setItem(
        'navItems',
        JSON.stringify(menuResponse.data),
      )
    }
    catch (menuError) {
      console.warn(
        'Fetch menu failed:',
        menuError,
      )
    }

    /*
    |--------------------------------------------------------------------------
    | Masuk dashboard
    |--------------------------------------------------------------------------
    */
    loadingStatus.value = 'Membuka dashboard...'

    await router.replace('/dashboards/crm')
  }
  catch (error: any) {
    console.error(
      'SSO login gagal:',
      error.response?.data || error,
    )

    const message
      = error.response?.data?.message
        || error.message
        || 'Login SSO gagal diproses.'

    /*
    |--------------------------------------------------------------------------
    | Bersihkan kemungkinan session yang sempat terbentuk
    |--------------------------------------------------------------------------
    */
    clearAuthSession()

    /*
    |--------------------------------------------------------------------------
    | Simpan pesan sementara untuk dibaca halaman login
    |--------------------------------------------------------------------------
    */
    sessionStorage.setItem(
      'ssoLoginError',
      message,
    )

    /*
    |--------------------------------------------------------------------------
    | Redirect tanpa membawa error pada URL
    |--------------------------------------------------------------------------
    */
    await router.replace('/login')
  }
})
</script>

<template>
  <div class="sso-loading-screen">
    <div class="sso-loading-container">
      <!-- Logo -->
      <div class="sso-loading-logo">
        <img
          src="/logo-proenergi.png"
          alt="Logo Pro Energi"
        >
      </div>

      <!-- Nama aplikasi -->
      <div class="sso-loading-title">
        SYOP
      </div>

      <div class="sso-loading-version">
        Version 4.0
      </div>

      <!-- Progress bar -->
      <div class="sso-loading-progress">
        <div class="sso-loading-progress-bar" />
      </div>

      <!-- Status -->
      <div
        class="sso-loading-status"
        :class="{ 'text-error': errorMessage }"
      >
        {{ loadingStatus }}
      </div>

      <div
        v-if="errorMessage"
        class="sso-error-message"
      >
        {{ errorMessage }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.sso-loading-screen {
  position: fixed;
  z-index: 99999;
  display: flex;
  inline-size: 100%;
  min-block-size: 100vh;
  align-items: center;
  justify-content: center;
  background:
    radial-gradient(
      circle at 20% 20%,
      rgb(16 45 122 / 8%),
      transparent 35%
    ),
    radial-gradient(
      circle at 80% 80%,
      rgb(247 148 29 / 8%),
      transparent 35%
    ),
    #fff;
  inset: 0;
}

.sso-loading-container {
  display: flex;
  min-inline-size: 360px;
  flex-direction: column;
  align-items: center;
  padding: 40px 48px;
  border: 1px solid rgb(16 45 122 / 8%);
  border-radius: 18px;
  background: rgb(255 255 255 / 86%);
  box-shadow:
    0 20px 60px rgb(16 45 122 / 10%),
    0 8px 24px rgb(0 0 0 / 5%);
  backdrop-filter: blur(12px);
}

.sso-loading-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-block-end: 18px;
  animation: logoPulse 1.8s ease-in-out infinite;
}

.sso-loading-logo img {
  display: block;
  inline-size: 230px;
  max-inline-size: 100%;
  block-size: auto;
  object-fit: contain;
}

.sso-loading-title {
  color: #102d7a;
  font-size: 28px;
  font-weight: 700;
  letter-spacing: 4px;
  line-height: 1.2;
}

.sso-loading-version {
  margin-block-start: 4px;
  color: #6e6b7b;
  font-size: 14px;
  font-weight: 500;
  letter-spacing: 1px;
}

.sso-loading-progress {
  position: relative;
  overflow: hidden;
  inline-size: 300px;
  max-inline-size: 100%;
  block-size: 6px;
  margin-block-start: 28px;
  border-radius: 999px;
  background: rgb(16 45 122 / 10%);
}

.sso-loading-progress-bar {
  position: absolute;
  inline-size: 42%;
  block-size: 100%;
  border-radius: inherit;
  background: linear-gradient(
    90deg,
    #102d7a 0%,
    #ed1b2f 55%,
    #f7941d 100%
  );
  animation: loadingProgress 1.5s ease-in-out infinite;
}

.sso-loading-status {
  min-block-size: 22px;
  margin-block-start: 18px;
  color: #6e6b7b;
  font-size: 14px;
  text-align: center;
}

.sso-loading-status.text-error {
  color: rgb(var(--v-theme-error));
}

.sso-error-message {
  max-inline-size: 340px;
  margin-block-start: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 13px;
  text-align: center;
}

@keyframes loadingProgress {
  0% {
    inset-inline-start: -45%;
  }

  50% {
    inset-inline-start: 55%;
  }

  100% {
    inset-inline-start: 105%;
  }
}

@keyframes logoPulse {
  0%,
  100% {
    opacity: 1;
    transform: scale(1);
  }

  50% {
    opacity: 0.88;
    transform: scale(1.025);
  }
}

@media (max-width: 480px) {
  .sso-loading-container {
    min-inline-size: auto;
    inline-size: calc(100% - 32px);
    padding: 32px 24px;
  }

  .sso-loading-logo img {
    inline-size: 190px;
  }

  .sso-loading-progress {
    inline-size: 100%;
  }
}
</style>

<route lang="yaml">
meta:
  layout: blank
  public: true
</route>