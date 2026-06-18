import axios from '@/plugins/axios'
import router from '@/router'
import { useNavigationStore } from '@/stores/navigation'
import { usePermissionStore } from '@/stores/permission'
import { defineStore } from 'pinia'

interface LoginPayload {
  email: string
  password: string
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as any,
    menus: [] as any[],
    loading: false,
  }),

  actions: {
    async login(payload: LoginPayload): Promise<void> {
      if (this.loading)
        return

      this.loading = true

      const navigationStore = useNavigationStore()
      const permissionStore = usePermissionStore()

      try {
        /*
        |--------------------------------------------------------------------------
        | Pastikan state akun sebelumnya sudah bersih
        |--------------------------------------------------------------------------
        */
        navigationStore.clearNavigation()

        if (typeof permissionStore.$reset === 'function')
          permissionStore.$reset()

        this.user = null
        this.menus = []

        localStorage.removeItem('navItems')
        localStorage.removeItem('userAbilities')
        localStorage.removeItem('userData')
        localStorage.removeItem('access_token')

        /*
        |--------------------------------------------------------------------------
        | Login
        |--------------------------------------------------------------------------
        */
        const { data } = await axios.post('/auth/login', payload, {
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
        })

        const token = String(
          data?.token
          ?? data?.access_token
          ?? '',
        ).trim()

        if (!token)
          throw new Error('Token login tidak ditemukan pada response server.')

        /*
        |--------------------------------------------------------------------------
        | Simpan token sebelum request /auth/me dan /auth/my-menus
        |--------------------------------------------------------------------------
        */
        localStorage.setItem('access_token', token)

        /*
        |--------------------------------------------------------------------------
        | Pastikan Axios langsung memakai token baru
        |--------------------------------------------------------------------------
        |
        | Bagian ini tetap diperlukan bila interceptor Axios hanya membaca token
        | saat aplikasi pertama kali dimuat.
        |--------------------------------------------------------------------------
        */
        axios.defaults.headers.common.Authorization = `Bearer ${token}`

        /*
        |--------------------------------------------------------------------------
        | Ambil user login terlebih dahulu
        |--------------------------------------------------------------------------
        |
        | Navigation store memakai userData.id untuk memastikan cache menu
        | berasal dari akun yang benar.
        |--------------------------------------------------------------------------
        */
        await this.fetchUser()

        /*
        |--------------------------------------------------------------------------
        | Bersihkan menu lama sekali lagi setelah identitas user tersedia
        |--------------------------------------------------------------------------
        */
        navigationStore.clearNavigation()

        /*
        |--------------------------------------------------------------------------
        | Paksa mengambil menu akun baru
        |--------------------------------------------------------------------------
        */
        await navigationStore.fetchFromApi(true)

        /*
        |--------------------------------------------------------------------------
        | Sinkronkan state menus Auth Store
        |--------------------------------------------------------------------------
        |
        | Tidak perlu request /auth/my-menus kedua kali.
        |--------------------------------------------------------------------------
        */
        this.menus = [...navigationStore.items]

        /*
        |--------------------------------------------------------------------------
        | Muat permission/abilities akun baru
        |--------------------------------------------------------------------------
        */
        await permissionStore.loadPermissions(true)

        /*
        |--------------------------------------------------------------------------
        | Redirect setelah seluruh identitas, permission, dan menu siap
        |--------------------------------------------------------------------------
        */
        await router.replace('/')
      }
      catch (error) {
        /*
        |--------------------------------------------------------------------------
        | Bersihkan login parsial jika salah satu proses gagal
        |--------------------------------------------------------------------------
        */
        navigationStore.clearNavigation()

        if (typeof permissionStore.$reset === 'function')
          permissionStore.$reset()

        this.user = null
        this.menus = []

        localStorage.removeItem('access_token')
        localStorage.removeItem('userData')
        localStorage.removeItem('userAbilities')
        localStorage.removeItem('navItems')

        delete axios.defaults.headers.common.Authorization

        throw error
      }
      finally {
        this.loading = false
      }
    },

    async fetchUser(): Promise<void> {
      const { data } = await axios.get('/auth/me', {
        headers: {
          Accept: 'application/json',
        },
      })

      /*
      |--------------------------------------------------------------------------
      | Mendukung response langsung user atau { data: user }
      |--------------------------------------------------------------------------
      */
      const userData = data?.data ?? data

      this.user = userData

      /*
      |--------------------------------------------------------------------------
      | Navigation store membaca userData.id
      |--------------------------------------------------------------------------
      */
      localStorage.setItem(
        'userData',
        JSON.stringify(userData),
      )
    },

    /*
    |--------------------------------------------------------------------------
    | Opsional: dipertahankan untuk pemanggilan manual di tempat lain
    |--------------------------------------------------------------------------
    |
    | Pada proses login tidak perlu dipanggil lagi karena menu sudah dimuat
    | melalui navigationStore.fetchFromApi(true).
    |--------------------------------------------------------------------------
    */
    async fetchMenus(force = false): Promise<void> {
      const navigationStore = useNavigationStore()

      await navigationStore.fetchFromApi(force)

      this.menus = [...navigationStore.items]
    },

    async logout(): Promise<void> {
      const navigationStore = useNavigationStore()
      const permissionStore = usePermissionStore()

      try {
        await axios.post(
          '/auth/logout',
          {},
          {
            headers: {
              Accept: 'application/json',
            },
          },
        )
      }
      catch (error) {
        console.error('LOGOUT API ERROR:', error)
      }
      finally {
        /*
        |--------------------------------------------------------------------------
        | Reset reactive state
        |--------------------------------------------------------------------------
        */
        navigationStore.clearNavigation()

        if (typeof permissionStore.$reset === 'function')
          permissionStore.$reset()

        this.user = null
        this.menus = []

        /*
        |--------------------------------------------------------------------------
        | Hapus session akun lama
        |--------------------------------------------------------------------------
        */
        const storageKeys = [
          'access_token',
          'accessToken',
          'token',
          'userData',
          'userAbilities',
          'navItems',
        ]

        storageKeys.forEach(key => {
          localStorage.removeItem(key)
          sessionStorage.removeItem(key)
        })

        delete axios.defaults.headers.common.Authorization

        await router.replace('/login')
      }
    },
  },
})