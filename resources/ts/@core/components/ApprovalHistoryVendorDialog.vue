```vue
<script setup lang="ts">
import { computed } from 'vue'

interface ApprovalHistoryItem {
  id?: number
  step_order: number | string
  label?: string | null
  approver_type?: string | null
  approver_id?: number | string | null

  approver_name_snapshot?: string | null
  candidate_name?: string | null
  processed_by?: string | null

  approval_mode?: string | null
  status?: string | null

  approved_at?: string | null
  rejected_at?: string | null
  cancelled_at?: string | null
  created_at?: string | null
  updated_at?: string | null

  notes?: string | null
}

const props = withDefaults(defineProps<{
  modelValue: boolean
  vendorLabel?: string
  approvals?: ApprovalHistoryItem[]
}>(), {
  vendorLabel: '-',
  approvals: () => [],
})

const emit = defineEmits<{
  (
    event: 'update:modelValue',
    value: boolean,
  ): void
}>()

const isDialogOpen = computed({
  get: () => props.modelValue,

  set: value => {
    emit('update:modelValue', value)
  },
})

const normalizeText = (
  value: unknown,
): string => {
  return String(value ?? '').trim()
}

const formatDateTime = (
  value?: string | null,
): string => {
  if (!value)
    return '-'

  const date = new Date(value)

  if (Number.isNaN(date.getTime()))
    return String(value)

  const dd = String(date.getDate())
    .padStart(2, '0')

  const mm = String(date.getMonth() + 1)
    .padStart(2, '0')

  const yyyy = date.getFullYear()

  const hh = String(date.getHours())
    .padStart(2, '0')

  const ii = String(date.getMinutes())
    .padStart(2, '0')

  return `${dd}/${mm}/${yyyy} ${hh}:${ii}`
}

const getProcessedBy = (
  item: ApprovalHistoryItem,
): string => {
  const status = String(item.status || '')
    .trim()
    .toUpperCase()

  if (!['APPROVED', 'REJECTED'].includes(status)) {
    return '-'
  }

  return item.approver_name_snapshot || '-'
}

const getStatusKey = (
  status?: string | null,
): string => {
  const value = normalizeText(status)
    .toUpperCase()

  if (value === 'APPROVED')
    return 'approved'

  if (value === 'WAITING')
    return 'waiting'

  if (value === 'PENDING')
    return 'pending'

  if (value === 'REJECTED')
    return 'rejected'

  if (value === 'SKIPPED')
    return 'skipped'

  if (value === 'CANCELLED')
    return 'cancelled'

  return 'unknown'
}

const getStatusLabel = (
  status?: string | null,
): string => {
  const value = normalizeText(status)
    .toUpperCase()

  if (value === 'APPROVED')
    return 'Approved'

  if (value === 'WAITING')
    return 'Sedang Menunggu'

  if (value === 'PENDING')
    return 'Belum Diproses'

  if (value === 'REJECTED')
    return 'Rejected'

  if (value === 'SKIPPED')
    return 'Dilewati'

  if (value === 'CANCELLED')
    return 'Dibatalkan'

  return value || '-'
}

const getStatusColor = (
  status?: string | null,
): string => {
  const value = normalizeText(status)
    .toUpperCase()

  if (value === 'APPROVED')
    return 'success'

  if (value === 'WAITING')
    return 'warning'

  if (value === 'PENDING')
    return 'secondary'

  if (value === 'REJECTED')
    return 'error'

  if (value === 'SKIPPED')
    return 'info'

  if (value === 'CANCELLED')
    return 'default'

  return 'default'
}

const getStatusIcon = (
  status?: string | null,
): string => {
  const value = normalizeText(status)
    .toUpperCase()

  if (value === 'APPROVED')
    return 'tabler-circle-check'

  if (value === 'WAITING')
    return 'tabler-loader-2'

  if (value === 'PENDING')
    return 'tabler-clock'

  if (value === 'REJECTED')
    return 'tabler-circle-x'

  if (value === 'SKIPPED')
    return 'tabler-player-skip-forward'

  if (value === 'CANCELLED')
    return 'tabler-ban'

  return 'tabler-help-circle'
}

const normalizedApprovals = computed(() => {
  return [...props.approvals]
    .sort((first, second) => {
      const stepComparison
        = Number(first.step_order)
          - Number(second.step_order)

      if (stepComparison !== 0)
        return stepComparison

      return Number(first.id ?? 0)
        - Number(second.id ?? 0)
    })
    .map(item => {
      const statusUpper = normalizeText(
        item.status,
      ).toUpperCase()

      /*
      |--------------------------------------------------------------------------
      | Waktu aksi aktual
      |--------------------------------------------------------------------------
      |
      | WAITING/PENDING/CANCELLED/SKIPPED bukan waktu proses approval.
      | Jangan fallback ke updated_at.
      |--------------------------------------------------------------------------
      */
      const actionAt = statusUpper === 'APPROVED'
        ? item.approved_at
        : statusUpper === 'REJECTED'
          ? item.rejected_at
          : null

      const candidateName = normalizeText(
        item.candidate_name,
      )

      /*
      |--------------------------------------------------------------------------
      | Pelaku aktual
      |--------------------------------------------------------------------------
      |
      | Hanya boleh tampil pada APPROVED atau REJECTED.
      |--------------------------------------------------------------------------
      */
      const processedBy = (
        statusUpper === 'APPROVED'
        || statusUpper === 'REJECTED'
      )
        ? normalizeText(
            item.processed_by
              ?? item.approver_name_snapshot,
          )
        : ''

      return {
        ...item,

        step_order: Number(
          item.step_order || 0,
        ),

        label_display:
          normalizeText(item.label)
          || `Tahap ${item.step_order}`,

        /*
        | Diproses Oleh hanya aktor aktual.
        */
        approver_display:
          processedBy || '-',

        /*
        | Kandidat tetap dipisahkan.
        */
        candidate_display:
          candidateName || '-',

        approver_type_display:
          normalizeText(item.approver_type)
            .toUpperCase()
          || '-',

        approval_mode_display:
          normalizeText(item.approval_mode)
            .toUpperCase()
          || 'ANY',

        status_display:
          getStatusLabel(item.status),

        status_color:
          getStatusColor(item.status),

        status_icon:
          getStatusIcon(item.status),

        status_key:
          getStatusKey(item.status),

        action_at_display:
          formatDateTime(actionAt),

        notes_display:
          normalizeText(item.notes),
      }
    })
})

const displayApprovals = computed(() => {
  type NormalizedApproval
    = typeof normalizedApprovals.value[number]

  const groupedByStep = new Map<
    number,
    NormalizedApproval[]
  >()

  normalizedApprovals.value.forEach(item => {
    const stepOrder = Number(item.step_order)

    const existingItems
      = groupedByStep.get(stepOrder) ?? []

    existingItems.push(item)

    groupedByStep.set(
      stepOrder,
      existingItems,
    )
  })

  const result: NormalizedApproval[] = []

  groupedByStep.forEach(stepItems => {
    const approvalMode = String(
      stepItems[0]?.approval_mode_display
        ?? 'ANY',
    )
      .trim()
      .toUpperCase()

    /*
    |--------------------------------------------------------------------------
    | Mode ALL
    |--------------------------------------------------------------------------
    |
    | Semua kandidat wajib memproses, tampilkan seluruh row.
    |--------------------------------------------------------------------------
    */
    if (approvalMode === 'ALL') {
      result.push(...stepItems)

      return
    }

    /*
    |--------------------------------------------------------------------------
    | Mode ANY sudah diputuskan
    |--------------------------------------------------------------------------
    |
    | Hanya tampilkan user yang benar-benar approve/reject.
    |--------------------------------------------------------------------------
    */
    const rejectedItem = stepItems.find(
      item => item.status_key === 'rejected',
    )

    if (rejectedItem) {
      result.push(rejectedItem)

      return
    }

    const approvedItem = stepItems.find(
      item => item.status_key === 'approved',
    )

    if (approvedItem) {
      result.push(approvedItem)

      return
    }

    /*
    |--------------------------------------------------------------------------
    | Mode ANY masih menunggu
    |--------------------------------------------------------------------------
    |
    | Tampilkan satu kartu untuk satu step, bukan satu kartu per kandidat.
    |--------------------------------------------------------------------------
    */
    const waitingItems = stepItems.filter(
      item => item.status_key === 'waiting',
    )

    if (waitingItems.length > 0) {
      const representative = {
        ...waitingItems[0],

        /*
        | Gabungkan nama kandidat.
        */
        candidate_display: waitingItems
          .map(item => item.candidate_display)
          .filter(
            candidate =>
              candidate
              && candidate !== '-',
          )
          .filter(
            (
              candidate,
              index,
              candidates,
            ) =>
              candidates.indexOf(candidate)
              === index,
          )
          .join(' / ') || '-',

        /*
        | Belum ada yang memproses.
        */
        approver_display: '-',

        /*
        | Belum ada waktu aksi.
        */
        action_at_display: '-',
      }

      result.push(representative)

      return
    }

    /*
    |--------------------------------------------------------------------------
    | Mode ANY belum aktif
    |--------------------------------------------------------------------------
    |
    | Bila seluruhnya PENDING, cukup tampilkan satu row representatif.
    |--------------------------------------------------------------------------
    */
    const pendingItems = stepItems.filter(
      item => item.status_key === 'pending',
    )

    if (pendingItems.length > 0) {
      const representative = {
        ...pendingItems[0],

        candidate_display: pendingItems
          .map(item => item.candidate_display)
          .filter(
            candidate =>
              candidate
              && candidate !== '-',
          )
          .filter(
            (
              candidate,
              index,
              candidates,
            ) =>
              candidates.indexOf(candidate)
              === index,
          )
          .join(' / ') || '-',

        approver_display: '-',
        action_at_display: '-',
      }

      result.push(representative)

      return
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback
    |--------------------------------------------------------------------------
    */
    result.push(stepItems[0])
  })

  return result.sort((first, second) => {
    const stepComparison
      = Number(first.step_order)
        - Number(second.step_order)

    if (stepComparison !== 0)
      return stepComparison

    return Number(first.id ?? 0)
      - Number(second.id ?? 0)
  })
})

const waitingStep = computed(() => {
  return normalizedApprovals.value.find(
    item => item.status_key === 'waiting',
  )
})

const rejectedStep = computed(() => {
  return normalizedApprovals.value.find(
    item => item.status_key === 'rejected',
  )
})

const approvedCount = computed(() => {
  return normalizedApprovals.value.filter(
    item => item.status_key === 'approved',
  ).length
})

const effectiveApprovals = computed(() => {
  return normalizedApprovals.value.filter(
    item =>
      item.status_key !== 'skipped'
      && item.status_key !== 'cancelled',
  )
})

const isFinished = computed(() => {
  if (normalizedApprovals.value.length === 0)
    return false

  if (rejectedStep.value)
    return false

  if (waitingStep.value)
    return false

  return effectiveApprovals.value.every(
    item => item.status_key === 'approved',
  )
})

const totalSteps = computed(() => {
  return new Set(
    normalizedApprovals.value.map(
      item => item.step_order,
    ),
  ).size
})

const approvedSteps = computed(() => {
  const approvedStepOrders = new Set<number>()

  const groupedByStep = new Map<
    number,
    typeof normalizedApprovals.value
  >()

  normalizedApprovals.value.forEach(item => {
    const existing = groupedByStep.get(
      item.step_order,
    ) ?? []

    existing.push(item)

    groupedByStep.set(
      item.step_order,
      existing,
    )
  })

  groupedByStep.forEach(approvals => {
    const mode = approvals[0]
      ?.approval_mode_display
      ?? 'ANY'

    const statuses = approvals.map(
      item => item.status_key,
    )

    if (
      mode === 'ANY'
      && statuses.includes('approved')
    ) {
      approvedStepOrders.add(
        approvals[0].step_order,
      )

      return
    }

    if (
      mode === 'ALL'
      && approvals
        .filter(
          item =>
            item.status_key !== 'skipped'
            && item.status_key !== 'cancelled',
        )
        .every(
          item => item.status_key === 'approved',
        )
    ) {
      approvedStepOrders.add(
        approvals[0].step_order,
      )
    }
  })

  return approvedStepOrders.size
})

const summaryText = computed(() => {
  if (rejectedStep.value) {
    return `Master Vendor ditolak pada tahap ${rejectedStep.value.step_order} - ${rejectedStep.value.label_display}.`
  }

  if (waitingStep.value) {
    return `Saat ini menunggu approval pada tahap ${waitingStep.value.step_order} - ${waitingStep.value.label_display}.`
  }

  if (isFinished.value) {
    return 'Seluruh tahapan approval Master Vendor telah selesai.'
  }

  if (
    normalizedApprovals.value.length > 0
  ) {
    return 'Proses approval Master Vendor telah berhenti atau dibatalkan.'
  }

  return 'Belum ada data approval.'
})

const summaryColor = computed(() => {
  if (rejectedStep.value)
    return 'error'

  if (waitingStep.value)
    return 'warning'

  if (isFinished.value)
    return 'success'

  return 'info'
})

const currentPositionText = computed(() => {
  if (waitingStep.value) {
    return `Tahap ${waitingStep.value.step_order}`
  }

  if (rejectedStep.value) {
    return `Reject Tahap ${rejectedStep.value.step_order}`
  }

  if (isFinished.value)
    return 'Selesai'

  return '-'
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
          History Approval Master Vendor
        </VCardTitle>

        <VCardSubtitle class="mt-1">
          {{ vendorLabel }}
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

            <div>
              {{ summaryText }}
            </div>
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
                  {{ totalSteps }}
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
                  {{ approvedSteps }}
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
              :color="
                waitingStep
                  ? 'warning'
                  : rejectedStep
                    ? 'error'
                    : isFinished
                      ? 'success'
                      : 'info'
              "
              class="summary-card"
            >
              <VCardText>
                <div class="text-caption text-medium-emphasis mb-1">
                  Posisi Saat Ini
                </div>

                <div class="text-subtitle-1 font-weight-bold text-wrap">
                  {{ currentPositionText }}
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VAlert
          v-if="displayApprovals.length === 0"
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
            v-for="(item, index) in displayApprovals"
            :key="
              item.id
                || `${item.step_order}-${index}`
            "
            class="approval-flow-item"
          >
            <div class="approval-flow-track">
              <div
                class="approval-flow-dot"
                :class="[
                  `status-${item.status_key}`,
                  {
                    'is-waiting-pulse':
                      item.status_key === 'waiting',
                  },
                ]"
              >
                {{ item.step_order }}
              </div>

              <div
                v-if="
                  index
                    < displayApprovals.length - 1
                "
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
                      Tahap {{ item.step_order }}
                      -
                      {{ item.label_display }}
                    </div>

                    <div class="text-caption text-medium-emphasis mt-1">
                      Mode approval:
                      <strong>
                        {{ item.approval_mode_display }}
                      </strong>
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
                      {{ getProcessedBy(item) }}
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
                  v-if="
                    item.status_key === 'waiting'
                  "
                  color="warning"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Tahap approval saat ini sedang
                  menunggu proses pada posisi ini.
                </VAlert>

                <VAlert
                  v-else-if="
                    item.status_key === 'pending'
                  "
                  color="secondary"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Tahap ini belum aktif dan masih
                  menunggu tahap sebelumnya selesai.
                </VAlert>

                <VAlert
                  v-else-if="
                    item.status_key === 'skipped'
                  "
                  color="info"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Kandidat ini dilewati karena
                  approval mode ANY telah dipenuhi
                  oleh approver lain.
                </VAlert>

                <VAlert
                  v-else-if="
                    item.status_key === 'cancelled'
                  "
                  color="default"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  Tahap ini dibatalkan karena
                  proses approval dihentikan.
                </VAlert>

                <VAlert
                  v-if="item.notes_display"
                  :color="
                    item.status_key === 'rejected'
                      ? 'error'
                      : item.status_key === 'skipped'
                        ? 'info'
                        : 'default'
                  "
                  variant="tonal"
                  class="mt-4"
                >
                  <div class="font-weight-bold mb-1">
                    Catatan
                  </div>

                  <div>
                    {{ item.notes_display }}
                  </div>
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
          class="text-none"
          @click="isDialogOpen = false"
        >
          Tutup
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.approval-history-header {
  background:
    linear-gradient(
      135deg,
      rgb(var(--v-theme-surface)) 0%,
      rgba(var(--v-theme-primary), 0.06) 100%
    );
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
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  align-items: center;
  width: 42px;
}

.approval-flow-dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 999px;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 12%);
  color: #fff;
  font-size: 14px;
  font-weight: 700;
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

.approval-flow-dot.status-skipped {
  background: rgb(var(--v-theme-info));
}

.approval-flow-dot.status-cancelled,
.approval-flow-dot.status-unknown {
  background:
    rgb(
      var(--v-theme-on-surface-variant)
    );
}

.approval-flow-line {
  flex: 1;
  width: 3px;
  min-height: 48px;
  margin-top: 8px;
  border-radius: 999px;
  background:
    rgba(
      var(--v-theme-on-surface),
      0.12
    );
}

.approval-flow-card {
  flex: 1;
  border-radius: 18px;
}

.approval-flow-dot.is-waiting-pulse {
  animation:
    waitingPulse
    2.4s
    ease-in-out
    infinite;
}

@keyframes waitingPulse {
  0% {
    box-shadow:
      0 6px 18px rgba(0, 0, 0, 12%),
      0 0 0 0 rgba(var(--v-theme-warning), 45%);
    transform: scale(1);
  }

  50% {
    box-shadow:
      0 8px 22px rgba(0, 0, 0, 18%),
      0 0 0 8px rgba(var(--v-theme-warning), 12%);
    transform: scale(1.08);
  }

  100% {
    box-shadow:
      0 6px 18px rgba(0, 0, 0, 12%),
      0 0 0 0 rgba(var(--v-theme-warning), 0%);
    transform: scale(1);
  }
}
</style>
```
