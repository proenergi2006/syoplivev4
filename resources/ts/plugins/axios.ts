import axios from 'axios'
import Swal from 'sweetalert2'

const apiBaseUrl = String(
  import.meta.env.VITE_API_BASE_URL || '/api',
).replace(/\/+$/, '')

const axiosIns = axios.create({
  baseURL: apiBaseUrl,
  timeout: 30000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let isUnauthorizedHandling = false

/*
|--------------------------------------------------------------------------
| Auto Attach Bearer Token
|--------------------------------------------------------------------------
*/
axiosIns.interceptors.request.use(
  config => {
    const token = localStorage.getItem('accessToken')

    if (token) {
      /*
      |--------------------------------------------------------------------------
      | Pastikan headers tidak undefined
      |--------------------------------------------------------------------------
      */
      config.headers = config.headers ?? {}
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  },
  error => Promise.reject(error),
)

/*
|--------------------------------------------------------------------------
| Handle Unauthorized
|--------------------------------------------------------------------------
*/
axiosIns.interceptors.response.use(
  response => response,
  async error => {
    if (error.response?.status === 401) {
      const reason = String(error.response?.data?.reason ?? '')
      const message = String(
        error.response?.data?.message
        ?? 'Sesi Anda telah berakhir. Silakan login kembali.',
      )

      localStorage.removeItem('accessToken')
      localStorage.removeItem('access_token')

      const isLoginPage = window.location.pathname.includes('/login')

      if (!isLoginPage && !isUnauthorizedHandling) {
        isUnauthorizedHandling = true

        const isSessionExpired = [
          'idle_timeout',
          'invalid_token',
          'missing_token',
          'token_expired',
        ].includes(reason)

        await Swal.fire({
          icon: 'warning',
          customClass: {
            confirmButton: 'swal-confirm-button-white',
          },
          title: isSessionExpired
            ? 'Sesi Berakhir'
            : 'Akses Tidak Valid',
          text: isSessionExpired
            ? message
            : 'Sesi Anda tidak valid atau telah berakhir. Silakan login kembali.',
          confirmButtonText: 'Login Kembali',
          allowOutsideClick: false,
          allowEscapeKey: false,
        })

        window.location.href = '/login'
      }
    }

    return Promise.reject(error)
  },
)

export default axiosIns