<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Reach\StatamicResrv\Enums\AvailabilityChangeReason;
use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\ReservationLog;

class ActivityLogCpController extends Controller
{
    public function indexCp(): InertiaResponse
    {
        return Inertia::render('resrv::ActivityLog/Index', [
            'enabled' => config('resrv-config.enable_activity_log') === true,
            'availabilityUrl' => cp_route('resrv.logs.availability'),
            'reservationsUrl' => cp_route('resrv.logs.reservations'),
            'entriesUrl' => cp_route('resrv.utilities.entries'),
            'availabilityReasons' => $this->reasonOptions(AvailabilityChangeReason::cases()),
            'reservationReasons' => $this->reasonOptions(ReservationLogReason::cases()),
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statamic_id' => 'sometimes|string',
            'date_start' => 'sometimes|date',
            'date_end' => 'sometimes|date',
            'reason' => ['sometimes', Rule::enum(AvailabilityChangeReason::class)],
            'batch' => 'sometimes|string',
            'reservation_id' => 'sometimes|integer',
            'page' => 'sometimes|integer',
        ]);

        // Ordered explicitly — the CP listing sort bug taught us not to trust default ordering
        // on paginated output.
        $changes = AvailabilityChange::query()
            ->when($data['statamic_id'] ?? null, fn ($query, $id) => $query->forEntry($id))
            ->when($data['date_start'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($data['date_end'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->when($data['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($data['batch'] ?? null, fn ($query, $batch) => $query->forBatch($batch))
            ->when($data['reservation_id'] ?? null, fn ($query, $id) => $query->where('reservation_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage());

        $entryTitles = Entry::withTrashed()
            ->whereIn('item_id', $changes->getCollection()->pluck('statamic_id')->unique())
            ->pluck('title', 'item_id');

        $rateTitles = Rate::withoutGlobalScopes()
            ->whereIn('id', $changes->getCollection()->pluck('rate_id')->filter()->unique())
            ->pluck('title', 'id');

        $changes->through(fn (AvailabilityChange $change) => [
            'id' => $change->id,
            'batch' => $change->batch,
            'statamic_id' => $change->statamic_id,
            'entry_title' => $entryTitles->get($change->statamic_id, $change->statamic_id),
            'rate_id' => $change->rate_id,
            'rate_title' => $change->rate_id ? $rateTitles->get($change->rate_id, "#{$change->rate_id}") : null,
            'date' => $change->date->format('Y-m-d'),
            'action' => $change->action,
            'field' => $change->field,
            'old_value' => $change->old_value,
            'new_value' => $change->new_value,
            'reason' => $change->reason->value,
            'reason_label' => $change->reason->label(),
            'reservation_id' => $change->reservation_id,
            'actor_name' => $change->actor_name,
            'created_at' => $change->created_at->format('Y-m-d H:i:s'),
        ]);

        return response()->json($changes);
    }

    public function reservations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => 'sometimes|string',
            'reservation_id' => 'sometimes|integer',
            'reason' => ['sometimes', Rule::enum(ReservationLogReason::class)],
            'date_start' => 'sometimes|date',
            'date_end' => 'sometimes|date',
            'page' => 'sometimes|integer',
        ]);

        $logs = ReservationLog::query()
            ->when($data['reference'] ?? null, fn ($query, $reference) => $query->where('reference', 'like', "%{$reference}%"))
            ->when($data['reservation_id'] ?? null, fn ($query, $id) => $query->forReservation($id))
            ->when($data['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($data['date_start'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_end'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage());

        $logs->through(fn (ReservationLog $log) => [
            'id' => $log->id,
            'reservation_id' => $log->reservation_id,
            'reference' => $log->reference,
            'status_from' => $log->status_from?->value,
            'status_to' => $log->status_to->value,
            'reason' => $log->reason->value,
            'reason_label' => $log->reason->label(),
            'context' => $log->context,
            'actor_name' => $log->actor_name,
            'created_at' => $log->created_at->format('Y-m-d H:i:s'),
        ]);

        return response()->json($logs);
    }

    /** @param  array<int, AvailabilityChangeReason|ReservationLogReason>  $cases */
    private function reasonOptions(array $cases): array
    {
        return collect($cases)
            ->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])
            ->values()
            ->all();
    }

    private function perPage(): int
    {
        // Clamp so a huge ?perPage can't load/serialize unbounded rows (matches DynamicPricingCpController).
        $perPage = (int) (request('perPage') ?? config('statamic.cp.pagination_size', 25));

        return max(1, min($perPage, 100));
    }
}
