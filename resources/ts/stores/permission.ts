import axios from '@axios'
import { defineStore } from 'pinia'

type PermissionScope =
  | 'NONE'
  | 'OWN_DATA'
  | 'OWN_DEPARTMENT'
  | 'OWN_CABANG'
  | 'ALL'

interface PermissionAbility {
  allowed: boolean
  scope: PermissionScope
}

type PermissionValue = PermissionAbility | boolean

interface PermissionModuleRoute {
  id: number
  code: string
  name: string
  route_prefix: string
  sort_order?: number
}

interface PermissionState {
  permissions: Record<string, PermissionValue>
  modules: PermissionModuleRoute[]
  isLoaded: boolean
  isLoading: boolean
  loadingPromise: Promise<void> | null
}

const normalizePath = (path: string): string => {
  const cleanPath = String(path || '')
    .split('?')[0]
    .split('#')[0]
    .trim()

  if (!cleanPath)
    return '/'

  const withLeadingSlash = cleanPath.startsWith('/')
    ? cleanPath
    : `/${cleanPath}`

  return withLeadingSlash.length > 1
    ? withLeadingSlash.replace(/\/+$/, '')
    : withLeadingSlash
}

export const usePermissionStore = defineStore(
  'permission',
  {
    state: (): PermissionState => ({
      permissions: {},
      modules: [],
      isLoaded: false,
      isLoading: false,
      loadingPromise: null,
    }),

    getters: {
      can: state => {
        return (permissionCode: string): boolean => {
          const code = String(permissionCode || '').trim()

          if (!code)
            return false

          const permission = state.permissions[code]

          if (typeof permission === 'boolean')
            return permission

          return Boolean(permission?.allowed)
        }
      },

      scope: state => {
        return (permissionCode: string): PermissionScope => {
          const code = String(permissionCode || '').trim()
          const permission = state.permissions[code]

          if (
            !permission
            || typeof permission === 'boolean'
          ) {
            return 'NONE'
          }

          return permission.scope || 'NONE'
        }
      },

      getRequiredPermission: state => {
        return (routePath: string): string | null => {
          const normalizedPath = normalizePath(routePath)

          /*
          |--------------------------------------------------------------------------
          | Cari prefix paling spesifik
          |--------------------------------------------------------------------------
          | Contoh:
          |
          | /non_trade/purchase_request
          | /non_trade/purchase_request/report
          |
          | Prefix yang lebih panjang harus didahulukan.
          |--------------------------------------------------------------------------
          */
          const matchedModule = [...state.modules]
            .filter(module => {
              const prefix = normalizePath(
                module.route_prefix,
              )

              if (!prefix || prefix === '/')
                return false

              return normalizedPath === prefix
                || normalizedPath.startsWith(`${prefix}/`)
            })
            .sort((a, b) => {
              return normalizePath(b.route_prefix).length
                - normalizePath(a.route_prefix).length
            })[0]

          if (!matchedModule)
            return null

          const prefix = normalizePath(
            matchedModule.route_prefix,
          )

          const remainingPath = normalizedPath
            .slice(prefix.length)
            .replace(/^\/+/, '')

          const segments = remainingPath
            .split('/')
            .filter(Boolean)

          const firstSegment = (
            segments[0] || ''
          ).toLowerCase()

          /*
          |--------------------------------------------------------------------------
          | Penentuan action berdasarkan URL
          |--------------------------------------------------------------------------
          */
          let action = 'view'

          if (
            firstSegment === 'create'
            || firstSegment === 'new'
            || firstSegment === 'add'
          ) {
            action = 'create'
          }
          else if (
            firstSegment === 'edit'
            || firstSegment === 'update'
          ) {
            action = 'update'
          }
          else if (
            firstSegment === 'detail'
            || firstSegment === 'show'
            || firstSegment === 'view'
          ) {
            action = 'view'
          }

          return `${matchedModule.code}.${action}`
        }
      },
    },

    actions: {
      async loadPermissions(
        force = false,
      ): Promise<void> {
        if (this.isLoaded && !force)
          return

        if (this.loadingPromise && !force) {
          await this.loadingPromise

          return
        }

        this.isLoading = true

        this.loadingPromise = (async () => {
          try {
            const response = await axios.get(
              '/auth/me/permissions',
            )

            const responseData
              = response.data?.data ?? {}

            this.permissions
              = responseData.permissions ?? {}

            this.modules = Array.isArray(
              responseData.modules,
            )
              ? responseData.modules
              : []

            this.isLoaded = true
          }
          catch (error) {
            this.permissions = {}
            this.modules = []
            this.isLoaded = false

            throw error
          }
          finally {
            this.isLoading = false
            this.loadingPromise = null
          }
        })()

        await this.loadingPromise
      },

      clearPermissions(): void {
        this.permissions = {}
        this.modules = []
        this.isLoaded = false
        this.isLoading = false
        this.loadingPromise = null
      },
    },
  },
)