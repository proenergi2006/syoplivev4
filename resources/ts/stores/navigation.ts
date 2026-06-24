import type { VerticalNavItems } from '@/@layouts/types'
import axios from '@axios'
import { defineStore } from 'pinia'

export const useNavigationStore = defineStore('navigation', {
  state: () => ({
    items: [] as VerticalNavItems,
    loaded: false,
    loading: false,
    loadedUserId: null as number | null,
  }),

  actions: {
    getCurrentUserId(): number | null {
      try {
        const rawUser = localStorage.getItem('userData')

        if (!rawUser)
          return null

        const userData = JSON.parse(rawUser)

        return userData?.id
          ? Number(userData.id)
          : null
      }
      catch {
        return null
      }
    },

    loadFromLocal(): void {
      try {
        const raw = localStorage.getItem('navItems')

        const parsedItems = raw
          ? JSON.parse(raw)
          : []

        this.items = Array.isArray(parsedItems)
          ? parsedItems
          : []

        this.loadedUserId = this.getCurrentUserId()
        this.loaded = true
      }
      catch {
        this.items = []
        this.loadedUserId = null
        this.loaded = true
      }
    },

    async fetchFromApi(force = false): Promise<void> {
      if (this.loading)
        return

      const currentUserId = this.getCurrentUserId()

      const sameUser = (
        currentUserId !== null
        && this.loadedUserId !== null
        && currentUserId === this.loadedUserId
      )

      /*
      |--------------------------------------------------------------------------
      | Gunakan cache hanya jika menu memang milik user yang sama
      |--------------------------------------------------------------------------
      */
      if (
        !force
        && this.loaded
        && sameUser
        && this.items.length > 0
      ) {
        return
      }

      this.loading = true

      try {
        const response = await axios.get('/auth/my-menus', {
          headers: {
            Accept: 'application/json',
          },
        })

        /*
        |--------------------------------------------------------------------------
        | Sesuaikan dengan bentuk response API
        |--------------------------------------------------------------------------
        |
        | Mendukung:
        | - response langsung array;
        | - response { data: [...] }.
        |--------------------------------------------------------------------------
        */
        const responseItems = Array.isArray(response.data)
          ? response.data
          : Array.isArray(response.data?.data)
            ? response.data.data
            : []

        this.items = responseItems
        this.loadedUserId = currentUserId
        this.loaded = true

        localStorage.setItem(
          'navItems',
          JSON.stringify(responseItems),
        )
      }
      catch (error) {
        /*
        |--------------------------------------------------------------------------
        | Jangan biarkan menu akun lama tetap tampil jika fetch gagal
        |--------------------------------------------------------------------------
        */
        this.clearNavigation()

        throw error
      }
      finally {
        this.loading = false
      }
    },

    setItems(items: VerticalNavItems): void {
      this.items = Array.isArray(items)
        ? items
        : []

      this.loadedUserId = this.getCurrentUserId()
      this.loaded = true

      localStorage.setItem(
        'navItems',
        JSON.stringify(this.items),
      )
    },

    clearNavigation(): void {
      /*
      |--------------------------------------------------------------------------
      | Kosongkan reactive state Pinia
      |--------------------------------------------------------------------------
      */
      this.items = []
      this.loaded = false
      this.loading = false
      this.loadedUserId = null

      /*
      |--------------------------------------------------------------------------
      | Hapus cache menu browser
      |--------------------------------------------------------------------------
      */
      localStorage.removeItem('navItems')
      sessionStorage.removeItem('navItems')
    },
  },
})