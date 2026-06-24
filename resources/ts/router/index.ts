import {
  createRouter,
  createWebHistory,
} from 'vue-router'

import { setupLayouts } from 'virtual:generated-layouts'
import routes from '~pages'

import { canNavigate } from '@layouts/plugins/casl'
import { isUserLoggedIn } from '@/router/utils'
import { usePermissionStore } from '@/stores/permission'

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
| Route halaman tetap berasal dari file-based routing.
| Permission tidak lagi ditambahkan secara hardcode ke generated route.
|--------------------------------------------------------------------------
*/
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),

  routes: [
    /*
    |--------------------------------------------------------------------------
    | Root redirect
    |--------------------------------------------------------------------------
    */
    {
      path: '/',

      redirect: to => {
        let userData: Record<string, any> = {}

        try {
          userData = JSON.parse(
            localStorage.getItem('userData') || '{}',
          )
        }
        catch {
          userData = {}
        }

        const userRole = userData?.role || null

        if (userRole === 'admin') {
          return {
            name: 'dashboards-crm',
          }
        }

        if (userRole === 'client') {
          return {
            name: 'access-control',
          }
        }

        return {
          name: 'login',
          query: to.query,
        }
      },
    },

    /*
    |--------------------------------------------------------------------------
    | Existing redirects
    |--------------------------------------------------------------------------
    */
    {
      path: '/pages/user-profile',

      redirect: () => ({
        name: 'pages-user-profile-tab',

        params: {
          tab: 'profile',
        },
      }),
    },

    {
      path: '/pages/account-settings',

      redirect: () => ({
        name: 'pages-account-settings-tab',

        params: {
          tab: 'account',
        },
      }),
    },

    /*
    |--------------------------------------------------------------------------
    | Auto-generated file routes
    |--------------------------------------------------------------------------
    */
    ...setupLayouts(routes),
  ],
})

/*
|--------------------------------------------------------------------------
| Cookie helper
|--------------------------------------------------------------------------
*/
const removeCookie = (name: string): void => {
  document.cookie = `${name}=; Max-Age=0; path=/`
  document.cookie
    = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
}

/*
|--------------------------------------------------------------------------
| Clear local authentication
|--------------------------------------------------------------------------
*/
const clearAuthSession = (): void => {
  const authKeys = [
    'accessToken',
    'userData',
    'userAbilityRules',
  ]

  authKeys.forEach(key => {
    removeCookie(key)

    localStorage.removeItem(key)
    sessionStorage.removeItem(key)
  })
}

/*
|--------------------------------------------------------------------------
| Navigation guard
|--------------------------------------------------------------------------
*/
router.beforeEach(async to => {
  /*
  |--------------------------------------------------------------------------
  | Route yang boleh dibuka tanpa pemeriksaan permission
  |--------------------------------------------------------------------------
  */
  const publicRoutes = [
    'login',
    'not-authorized',
    'forbidden',
  ]

  const routeName = String(to.name || '')
  const isPublicRoute = publicRoutes.includes(routeName)

  /*
  |--------------------------------------------------------------------------
  | 1. Public route
  |--------------------------------------------------------------------------
  | Permission tidak dimuat pada halaman login agar tidak terjadi loop.
  |--------------------------------------------------------------------------
  */
  if (isPublicRoute) {
    const loggedIn = isUserLoggedIn()

    if (loggedIn && routeName === 'login') {
      return {
        path: '/dashboards/crm',
        replace: true,
      }
    }

    return true
  }

  /*
  |--------------------------------------------------------------------------
  | 2. Authentication lokal
  |--------------------------------------------------------------------------
  */
  if (!isUserLoggedIn()) {
    return {
      name: 'login',

      query: {
        to: to.fullPath !== '/'
          ? to.fullPath
          : undefined,
      },

      replace: true,
    }
  }

  /*
  |--------------------------------------------------------------------------
  | 3. Route khusus guest
  |--------------------------------------------------------------------------
  */
  if (to.meta.redirectIfLoggedIn) {
    return {
      path: '/dashboards/crm',
      replace: true,
    }
  }

  /*
  |--------------------------------------------------------------------------
  | 4. Memuat permission dan permission module
  |--------------------------------------------------------------------------
  | Endpoint /auth/me/permissions harus mengembalikan:
  |
  | data.permissions
  | data.modules
  |--------------------------------------------------------------------------
  */
  const permissionStore = usePermissionStore()

  try {
    await permissionStore.loadPermissions()
  }
  catch (error: any) {
    const status = Number(
      error?.response?.status || 0,
    )

    /*
    |--------------------------------------------------------------------------
    | Session sudah tidak valid
    |--------------------------------------------------------------------------
    */
    if (status === 401 || status === 419) {
      permissionStore.clearPermissions()
      clearAuthSession()

      return {
        name: 'login',

        query: {
          to: to.fullPath !== '/'
            ? to.fullPath
            : undefined,

          session_expired: '1',
        },

        replace: true,
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Permission gagal dimuat karena server/network error
    |--------------------------------------------------------------------------
    */
    return {
      name: 'forbidden',

      query: {
        reason: 'permission_load_failed',
        from: to.fullPath,
      },

      replace: true,
    }
  }

  /*
  |--------------------------------------------------------------------------
  | 5. Menentukan permission yang dibutuhkan
  |--------------------------------------------------------------------------
  | Prioritas:
  |
  | 1. meta.permission, apabila suatu route khusus masih menggunakannya.
  | 2. Permission otomatis berdasarkan permission_modules.route_prefix.
  |--------------------------------------------------------------------------
  */
  const permissionFromMeta = String(
    to.meta.permission || '',
  ).trim()

  const permissionFromModule
    = permissionStore.getRequiredPermission(to.path)

  const requiredPermission
    = permissionFromMeta || permissionFromModule

  /*
  |--------------------------------------------------------------------------
  | 6. Cek permission database
  |--------------------------------------------------------------------------
  */
  if (requiredPermission) {
    if (!permissionStore.can(requiredPermission)) {
      return {
        name: 'forbidden',

        query: {
          permission: requiredPermission,
          from: to.fullPath,
        },

        replace: true,
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Permission database sudah lolos
    |--------------------------------------------------------------------------
    | Jangan diperiksa kembali melalui CASL lama.
    |--------------------------------------------------------------------------
    */
    return true
  }

  /*
  |--------------------------------------------------------------------------
  | 7. CASL existing
  |--------------------------------------------------------------------------
  | Digunakan untuk halaman lama yang belum terdaftar pada
  | permission_modules dan masih memakai action/subject.
  |--------------------------------------------------------------------------
  */
  if (!canNavigate(to)) {
    return {
      name: 'forbidden',

      query: {
        from: to.fullPath,
      },

      replace: true,
    }
  }

  return true
})

export default router