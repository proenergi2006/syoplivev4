<script setup lang="ts">
import { computed } from 'vue'

interface ApprovalHistoryItem {
  id?: number
  step_order: number | string
  label?: string | null
  approver_type?: string | null
  approver_id?: number | string | null
  approver_name_snapshot?: string | null
  status?: string | null
  approved_at?: string | null
  rejected_at?: string | null
  signed_at?: string | null
  created_at?: string | null
  updated_at?: string | null
  notes?: string | null
}

const props = withDefaults(defineProps<{
  modelValue: boolean
  nomorPo?: string
  approvals?: ApprovalHistoryItem[]
}>(), {
  nomorPo: '-',
  approvals: () => [],
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()

const isDialogOpen = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})

const normalizeText = (value: unknown): string => {
  return String(value ?? '').trim()
}

const formatDateTime = (value?: string | null): string => {
  if (!value) return '-'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)

  const dd = String(date.getDate()).padStart(2, '0')
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const yyyy = date.getFullYear()
  const hh = String(date.getHours()).padStart(2, '0')
  const ii = String(date.getMinutes()).padStart(2, '0')

  return `${dd}/${mm}/${yyyy} ${hh}:${ii}`
}

const getStatusKey = (status?: string | null): string => {
  const val = normalizeText(status).toUpperCase()

  if (val === 'APPROVED') return 'approved'
  if (val === 'WAITING') return 'waiting'
  if (val === 'PENDING') return 'pending'
  if (val === 'REJECTED') return 'rejected'
  if (val === 'CANCELLED') return 'cancelled'

  return 'unknown'
}

const getStatusLabel = (status?: string | null): string => {
  const val = normalizeText(status).toUpperCase()

  if (val === 'APPROVED') return 'Approved'
  if (val === 'WAITING') return 'Sedang Menunggu'
  if (val === 'PENDING') return 'Belum Diproses'
  if (val === 'REJECTED') return 'Rejected'
  if (val === 'CANCELLED') return 'Dibatalkan'

  return val || '-'
}

const getStatusColor = (status?: string | null): string => {
  const val = normalizeText(status).toUpperCase()

  if (val === 'APPROVED') return 'success'
  if (val === 'WAITING') return 'warning'
  if (val === 'PENDING') return 'secondary'
  if (val === 'REJECTED') return 'error'
  if (val === 'CANCELLED') return 'default'

  return 'default'
}

const getStatusIcon = (status?: string | null): string => {
  const val = normalizeText(status).toUpperCase()

  if (val === 'APPROVED') return 'tabler-circle-check'
  if (val === 'WAITING') return 'tabler-loader-2'
  if (val === 'PENDING') return 'tabler-clock'
  if (val === 'REJECTED') return 'tabler-circle-x'
  if (val === 'CANCELLED') return 'tabler-ban'

  return 'tabler-help-circle'
}

const normalizedApprovals = computed(() => {
  return [...props.approvals]
    .sort((a, b) => Number(a.step_order) - Number(b.step_order))
    .map(item => {
      const statusUpper = normalizeText(item.status).toUpperCase()

      const actionAt = statusUpper === 'REJECTED'
        ? (item.rejected_at || item.signed_at || item.updated_at)
        : (item.approved_at || item.signed_at || item.updated_at)

      return {
        ...item,
        step_order: Number(item.step_order || 0),
        label_display: normalizeText(item.label) || `Tahap ${item.step_order}`,
        approver_display: normalizeText(item.approver_name_snapshot) || '-',
        approver_type_display: normalizeText(item.approver_type).toUpperCase() || '-',
        status_display: getStatusLabel(item.status),
        status_color: getStatusColor(item.status),
        status_icon: getStatusIcon(item.status),
        status_key: getStatusKey(item.status),
        action_at_display: formatDateTime(actionAt),
        notes_display: normalizeText(item.notes),
      }
    })
})

const waitingStep = computed(() => {
  return normalizedApprovals.value.find(item => item.status_key === 'waiting')
})

const rejectedStep = computed(() => {
  return normalizedApprovals.value.find(item => item.status_key === 'rejected')
})

const approvedCount = computed(() => {
  return normalizedApprovals.value.filter(item => item.status_key === 'approved').length
})

const summaryText = computed(() => {
  if (rejectedStep.value) {
    return `Dokumen ditolak pada tahap ${rejectedStep.value.step_order} - ${rejectedStep.value.label_display}`
  }

  if (waitingStep.value) {
    return `Saat ini menunggu approval pada tahap ${waitingStep.value.step_order} - ${waitingStep.value.label_display}`
  }

  if (normalizedApprovals.value.length > 0 && approvedCount.value === normalizedApprovals.value.length) {
    return 'Seluruh tahapan approval telah selesai.'
  }

  return 'Belum ada data approval.'
})

const summaryColor = computed(() => {
  if (rejectedStep.value) return 'error'
  if (waitingStep.value) return 'warning'
  if (normalizedApprovals.value.length > 0 && approvedCount.value === normalizedApprovals.value.length) return 'success'

  return 'info'
})
</script>

<template>
  <VDialog
    v-model="isDialogOpen"
    max-width="980"
    scrollable
  >
    <VCard class="approval-history-dialog">
      <VCardItem class="px-6 py-4 approval-history-header">
        <template #prepend>
          <VAvatar
            size="42"
            color="primary"
            variant="tonal"
          >
            <VIcon icon="tabler-git-merge" />
          </VAvatar>
        </template>

        <VCardTitle class="text-h6 font-weight-bold">
          History Approval Purchase Order
        </VCardTitle>

        <VCardSubtitle class="mt-1">
          {{ nomorPo }}
        </VCardSubtitle>

        <template #append>
          <VBtn
            icon
            variant="text"
            color="primary"
            @click="isDialogOpen = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-6">
        <VAlert
          :color="summaryColor"
          variant="tonal"
          class="mb-6"
        >
          <div class="d-flex flex-column gap-1">
            <div class="text-subtitle-1 font-weight-bold">
              Ringkasan Status Approval
            </div>
            <div>{{ summaryText }}</div>
          </div>
        </VAlert>

        <VRow class="mb-6">
          <VCol
            cols="12"
            md="4"
          >
            <VCard
              variant="tonal"
              color="primary"
              class="summary-card"
            >
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-1">
                  Total Tahap
                </div>
                <div class="text-h4 font-weight-bold">
                  {{ normalizedApprovals.length }}
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VCard
              variant="tonal"
              color="success"
              class="summary-card"
            >
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-1">
                  Tahap Disetujui
                </div>
                <div class="text-h4 font-weight-bold">
                  {{ approvedCount }}
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VCard
              variant="tonal"
              :color="waitingStep ? 'warning' : rejectedStep ? 'error' : 'info'"
              class="summary-card"
            >
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-1">
                  Posisi Saat Ini
                </div>
                <div class="text-subtitle-1 font-weight-bold text-wrap">
                  {{
                    waitingStep
                      ? `Tahap ${waitingStep.step_order}`
                      : rejectedStep
                        ? `Reject Tahap ${rejectedStep.step_order}`
                        : 'Selesai'
                  }}
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VAlert
          v-if="normalizedApprovals.length === 0"
          type="info"
          variant="tonal"
        >
          Belum ada data history approval.
        </VAlert>

        <div
          v-else
          class="approval-flow"
        >
          <div
            v-for="(item, index) in normalizedApprovals"
            :key="item.id || `${item.step_order}-${index}`"
            class="approval-flow-item"
          >
            <div class="approval-flow-track">
              <div
                class="approval-flow-dot"
                :class="[
                    `status-${item.status_key}`,
                    {
                    'is-waiting-pulse': item.status_key === 'waiting',
                    },
                ]"
              >
                {{ item.step_order }}
              </div>

              <div
                v-if="index < normalizedApprovals.length - 1"
                class="approval-flow-line"
              />
            </div>

            <VCard
              class="approval-flow-card"
              variant="outlined"
            >
              <VCardText class="pa-4">
                <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-3">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Tahap {{ item.step_order }} - {{ item.label_display }}
                    </div>
                  </div>

                  <VChip
                    :color="item.status_color"
                    variant="tonal"
                    size="small"
                  >
                    <VIcon
                      start
                      :icon="item.status_icon"
                      size="16"
                    />
                    {{ item.status_display }}
                  </VChip>
                </div>

                <VRow dense>
                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      Posisi / Tahap
                    </div>
                    <div class="font-weight-medium mt-1">
                      {{ item.label_display }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      Diproses Oleh
                    </div>
                    <div class="font-weight-medium mt-1">
                      {{ item.approver_display }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      Waktu
                    </div>
                    <div class="font-weight-medium mt-1">
                      {{ item.action_at_display }}
                    </div>
                  </VCol>
                </VRow>

                <VAlert
                  v-if="item.status_key === 'waiting'"
                  color="warning"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Tahap approval saat ini sedang menunggu proses pada posisi ini.
                </VAlert>

                <VAlert
                  v-else-if="item.status_key === 'pending'"
                  color="secondary"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Tahap ini belum aktif dan masih menunggu tahap sebelumnya selesai.
                </VAlert>

                <VAlert
                  v-else-if="item.status_key === 'cancelled'"
                  color="default"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Tahap ini dibatalkan karena dokumen berhenti pada tahap sebelumnya.
                </VAlert>

                <VAlert
                  v-if="item.notes_display"
                  :color="item.status_key === 'rejected' ? 'error' : 'info'"
                  variant="tonal"
                  class="mt-4"
                >
                  <div class="font-weight-bold mb-1">
                    Catatan
                  </div>
                  <div>{{ item.notes_display }}</div>
                </VAlert>
              </VCardText>
            </VCard>
          </div>
        </div>
      </VCardText>

      <VDivider />

      <VCardActions class="px-6 py-4 justify-end">
        <VBtn
          color="primary"
          variant="flat"
          @click="isDialogOpen = false"
          class="text-none"
        >
          Tutup
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.approval-history-header {
  background: linear-gradient(135deg, rgb(var(--v-theme-surface)) 0%, rgba(var(--v-theme-primary), 0.06) 100%);
}

.summary-card {
  min-height: 100%;
  border-radius: 16px;
}

.approval-flow {
  position: relative;
}

.approval-flow-item {
  display: flex;
  align-items: stretch;
  gap: 16px;
}

.approval-flow-item:not(:last-child) {
  margin-bottom: 18px;
}

.approval-flow-track {
  width: 42px;
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-shrink: 0;
}

.approval-flow-dot {
  width: 36px;
  height: 36px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  color: #fff;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
}

.approval-flow-dot.status-approved {
  background: rgb(var(--v-theme-success));
}

.approval-flow-dot.status-waiting {
  background: rgb(var(--v-theme-warning));
}

.approval-flow-dot.status-pending {
  background: rgb(var(--v-theme-secondary));
}

.approval-flow-dot.status-rejected {
  background: rgb(var(--v-theme-error));
}

.approval-flow-dot.status-cancelled,
.approval-flow-dot.status-unknown {
  background: rgb(var(--v-theme-on-surface-variant));
}

.approval-flow-line {
  width: 3px;
  flex: 1;
  margin-top: 8px;
  border-radius: 999px;
  background: rgba(var(--v-theme-on-surface), 0.12);
  min-height: 48px;
}

.approval-flow-card {
  flex: 1;
  border-radius: 18px;
}

.approval-flow-dot.is-waiting-pulse {
  animation: waitingPulse 2.4s ease-in-out infinite;
}

@keyframes waitingPulse {
  0% {
    transform: scale(1);
    box-shadow:
      0 6px 18px rgba(0, 0, 0, 0.12),
      0 0 0 0 rgba(var(--v-theme-warning), 0.45);
  }

  50% {
    transform: scale(1.08);
    box-shadow:
      0 8px 22px rgba(0, 0, 0, 0.18),
      0 0 0 8px rgba(var(--v-theme-warning), 0.12);
  }

  100% {
    transform: scale(1);
    box-shadow:
      0 6px 18px rgba(0, 0, 0, 0.12),
      0 0 0 0 rgba(var(--v-theme-warning), 0);
  }
}
</style>