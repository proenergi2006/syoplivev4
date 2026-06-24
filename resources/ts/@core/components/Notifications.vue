<script lang="ts" setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { avatarText } from '@core/utils/formatters'
import axios from '@axios'
import { useRouter } from 'vue-router'

interface Props {
  badgeProps?: Record<string, any>
  location?: Anchor
}

const props = withDefaults(defineProps<Props>(), {
  location: 'bottom end',
  badgeProps: undefined,
})

defineEmits<{
  (e: 'click:readAllNotifications'): void
}>()

type Anchor = 'top' | 'bottom' | 'start' | 'end' | 'center' | 'bottom end' | 'bottom start' | 'top end' | 'top start'

const router = useRouter()

const notifications = ref<any[]>([])
const unreadNotificationCount = ref(0)
const previousUnreadNotificationCount = ref(0)
const notificationLoading = ref(false)
const showNotificationToast = ref(false)
const notificationFetching = ref(false)
const readAllLoading = ref(false)

let notificationToastTimer: ReturnType<typeof setTimeout> | null = null

let pollingTimer: ReturnType<typeof setInterval> | null = null

const triggerNotificationToast = (): void => {
  showNotificationToast.value = true

  if (notificationToastTimer) {
    clearTimeout(notificationToastTimer)
  }

  notificationToastTimer = setTimeout(() => {
    showNotificationToast.value = false
  }, 5000)
}

const buildRefreshEventName = (module?: string | null): string | null => {
  if (!module) return null

  const normalizedModule = String(module)
    .trim()
    .toLowerCase()
    .replace(/_/g, '-')

  if (!normalizedModule) return null

  return `${normalizedModule}:refresh`
}

const dispatchRefreshEventsByModules = (items: any[]): void => {
  const modules = new Set<string>()

  items.forEach(item => {
    const eventName = buildRefreshEventName(item?.module)

    if (eventName) {
      modules.add(eventName)
    }
  })

  modules.forEach(eventName => {
    window.dispatchEvent(new CustomEvent(eventName))
  })
}

const fetchNotifications = async (): Promise<void> => {
  try {
    notificationFetching.value = true

    const response = await axios.get('/notifications', {
      headers: { Accept: 'application/json' },
      params: { limit: 5 },
    })

    const newUnreadCount = Number(response.data?.unread_count || 0)
    const newNotifications = response.data?.data || []

    notifications.value = newNotifications

    if (newUnreadCount > previousUnreadNotificationCount.value) {
      triggerNotificationToast()

      dispatchRefreshEventsByModules(newNotifications)
    }

    unreadNotificationCount.value = newUnreadCount
    previousUnreadNotificationCount.value = newUnreadCount
  } catch (error) {
    console.error('Gagal mengambil notifikasi:', error)
  } finally {
    notificationFetching.value = false
  }
}

const readAllNotifications = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  try {
    readAllLoading.value = true
    
    await axios.patch('/notifications/read-all', {}, {
      headers: { Accept: 'application/json' },
    })
    

    unreadNotificationCount.value = 0
    previousUnreadNotificationCount.value = 0

    notifications.value = notifications.value.map(item => ({
      ...item,
      is_read: true,
      read_at: item.read_at || new Date().toISOString(),
    }))
  } catch (error) {
    console.error('Gagal membaca semua notifikasi:', error)
  } finally {
    readAllLoading.value = false
  }
}

const startPolling = (): void => {
  if (pollingTimer) return

  pollingTimer = setInterval(() => {
    if (document.hidden) return

    fetchNotifications()
  }, 10000)
}

const stopPolling = (): void => {
  if (!pollingTimer) return

  clearInterval(pollingTimer)
  pollingTimer = null
}

const handleVisibilityChange = (): void => {
  if (document.hidden) {
    stopPolling()
  } else {
    fetchNotifications()
    startPolling()
  }
}

const formatNotificationDate = (date?: string): string => {
  if (!date) return '-'

  const value = new Date(date)

  if (Number.isNaN(value.getTime())) return '-'

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(value)
}

const markNotificationAsRead = async (notification: any): Promise<void> => {
  if (!notification?.id || notification.is_read) return

  try {
    await axios.patch(`/notifications/${notification.id}/read`, {}, {
      headers: { Accept: 'application/json' },
    })

    notifications.value = notifications.value.map(item => {
      if (item.id !== notification.id) return item

      return {
        ...item,
        is_read: true,
        read_at: new Date().toISOString(),
      }
    })

    unreadNotificationCount.value = Math.max(unreadNotificationCount.value - 1, 0)
    previousUnreadNotificationCount.value = unreadNotificationCount.value
  } catch (error) {
    console.error('Gagal membaca notifikasi:', error)
  }
}

const deleteReadNotifications = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  try {
    await axios.delete('/notifications/read', {
      headers: { Accept: 'application/json' },
    })

    notifications.value = notifications.value.filter(item => !item.is_read)
  } catch (error) {
    console.error('Gagal menghapus notifikasi yang sudah dibaca:', error)
  }
}

const openNotificationModule = async (event: Event, notification: any): Promise<void> => {
  event.preventDefault()
  event.stopPropagation()

  await markNotificationAsRead(notification)

  if (notification.url) {
    router.push(notification.url)
  }
}

onMounted(() => {
  fetchNotifications()
  startPolling()

  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  stopPolling()

  document.removeEventListener('visibilitychange', handleVisibilityChange)
})
</script>

<template>
  <div class="notification-wrapper">
    <div
      v-if="showNotificationToast"
      class="notification-toast"
    >
      <div class="notification-toast-arrow" />
      <strong>Notifikasi baru</strong>
      <span>Anda mendapatkan notifikasi baru.</span>
    </div>

    <VBadge
      :model-value="!!props.badgeProps"
      v-bind="props.badgeProps"
    >
      <VBtn
        icon
        variant="text"
        color="default"
        size="small"
      >
        <VBadge
          dot
          :model-value="unreadNotificationCount > 0"
          color="error"
          bordered
          offset-x="1"
          offset-y="1"
        >
          <VIcon
            icon="mdi-bell-outline"
            size="24"
          />
        </VBadge>

        <VMenu
          activator="parent"
          width="420px"
          :location="props.location"
          offset="14px"
        >
          <VCard class="d-flex flex-column">
            <VCardItem class="notification-section">
              <VCardTitle class="text-base">
                Notifications
              </VCardTitle>

              <template #append>
                <VChip
                  v-if="unreadNotificationCount > 0"
                  color="primary"
                  size="small"
                >
                  {{ unreadNotificationCount }} New
                </VChip>
                <VBtn
                  icon
                  size="small"
                  variant="text"
                  color="error"
                  :disabled="!notifications.some(item => item.is_read)"
                  @click.stop="deleteReadNotifications"
                >
                  <VIcon
                    icon="mdi-trash-can-outline"
                    size="20"
                  />
                </VBtn>
              </template>
            </VCardItem>

            <VDivider />

            <PerfectScrollbar
              class="notification-scroll"
              :options="{ wheelPropagation: false }"
            >
              <VList class="py-0">
                <template v-if="notifications.length">
                  <template
                    v-for="notification in notifications"
                    :key="notification.id"
                  >
                    <VListItem
                      class="notification-item"
                      :class="{ 'notification-unread': !notification.is_read }"
                      link
                      min-height="86px"
                      @click.stop="markNotificationAsRead(notification)"
                    >
                      <template #prepend>
                        <VListItemAction start>
                          <VAvatar
                            color="primary"
                            size="42"
                            variant="tonal"
                          >
                            {{ avatarText(notification.title || 'N') }}
                          </VAvatar>
                        </VListItemAction>
                      </template>

                      <div class="notification-content">
                        <div class="notification-title-row">
                          <div class="notification-title">
                            {{ notification.title }}
                          </div>

                          <span
                            v-if="!notification.is_read"
                            class="notification-dot"
                          />
                        </div>

                        <div class="notification-message">
                          {{ notification.message }}
                        </div>

                        <div class="notification-footer">
                          <div class="notification-time">
                            {{ formatNotificationDate(notification.created_at) }}
                          </div>

                          <VBtn
                            size="small"
                            color="primary"
                            variant="tonal"
                            @click.stop="openNotificationModule($event, notification)"
                          >
                            Lihat
                          </VBtn>
                        </div>
                      </div>
                    </VListItem>

                    <VDivider />
                  </template>
                </template>

                <VListItem
                  v-else
                  title="Belum ada notifikasi"
                  subtitle="Notifikasi terbaru akan muncul di sini."
                  min-height="72px"
                >
                  <template #prepend>
                    <VAvatar
                      color="secondary"
                      size="40"
                      variant="tonal"
                    >
                      <VIcon icon="mdi-bell-off-outline" />
                    </VAvatar>
                  </template>
                </VListItem>
              </VList>
            </PerfectScrollbar>

            <VCardText class="notification-section">
              <VBtn
                block
                :disabled="!notifications.length"
                :loading="readAllLoading"
                @click.stop="readAllNotifications"
              >
                READ ALL NOTIFICATIONS
              </VBtn>
            </VCardText>
          </VCard>
        </VMenu>
      </VBtn>
    </VBadge>
  </div>
</template>

<style lang="scss">
.notification-section {
  padding: 14px !important;
}

.notification-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/*
|--------------------------------------------------------------------------
| LIST
|--------------------------------------------------------------------------
*/

.notification-scroll {
  max-height: 320px;
}

.notification-item {
  align-items: flex-start !important;
  padding-block: 12px !important;
  transition: all 0.2s ease;
}

.notification-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.03);
}

.notification-unread {
  background: rgba(var(--v-theme-primary), 0.07);
}

.notification-content {
  min-width: 0;
  width: 100%;
  padding-right: 8px;
}

.notification-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.notification-title {
  font-size: 14px;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.92);
  white-space: normal;
  line-height: 1.4;
  word-break: break-word;
}

.notification-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: rgb(var(--v-theme-error));
  flex: 0 0 auto;
}

.notification-message {
  margin-top: 4px;
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.68);
  white-space: normal;
  line-height: 1.45;
  word-break: break-word;
}

.notification-time {
  margin-top: 7px;
  font-size: 11px;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.46);
}

.notification-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
}

.notification-footer .v-btn {
  flex: 0 0 auto;
  text-transform: none;
  font-weight: 700;
}
/*
|--------------------------------------------------------------------------
| TOAST
|--------------------------------------------------------------------------
*/

.notification-toast {
  position: absolute;
  top: calc(100% + 14px);
  right: -72px;
  z-index: 99999;

  width: 290px;

  padding: 14px 16px;

  border-radius: 16px;

  background: rgb(var(--v-theme-surface));

  border: 1px solid rgba(var(--v-border-color), 0.12);

  box-shadow:
    0 14px 40px rgba(15, 23, 42, 0.16),
    0 2px 8px rgba(15, 23, 42, 0.08);

  text-align: left;

  animation: notification-slide-in 0.25s ease;

  pointer-events: none;
}

.notification-toast strong {
  display: block;

  margin-bottom: 3px;

  font-size: 13px;
  font-weight: 800;

  color: rgba(var(--v-theme-on-surface), 0.92);
}

.notification-toast span {
  display: block;

  font-size: 12px;
  line-height: 1.45;

  color: rgba(var(--v-theme-on-surface), 0.68);

  white-space: normal;
}

.notification-toast-arrow {
  position: absolute;

  top: -7px;
  right: 86px;

  width: 14px;
  height: 14px;

  background: rgb(var(--v-theme-surface));

  border-left: 1px solid rgba(var(--v-border-color), 0.12);
  border-top: 1px solid rgba(var(--v-border-color), 0.12);

  transform: rotate(45deg);
}

/*
|--------------------------------------------------------------------------
| ANIMATION
|--------------------------------------------------------------------------
*/

@keyframes notification-slide-in {
  from {
    opacity: 0;
    transform: translateY(-8px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

@media (max-width: 600px) {
  .notification-toast {
    right: -110px;
    width: 250px;
  }

  .notification-toast-arrow {
    right: 122px;
  }

  .notification-scroll {
    max-height: 260px;
  }
}
</style>