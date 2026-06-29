import axios from 'axios'

const apiBaseUrl = String(
  import.meta.env.VITE_API_BASE_URL
  || 'http://127.0.0.1:8000/api',
).replace(/\/+$/, '')

const axiosIns = axios.create({
  baseURL: apiBaseUrl,
  timeout: 15000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

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
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('accessToken')
      localStorage.removeItem('access_token')
    }

    return Promise.reject(error)
  },
)

export default axiosIns