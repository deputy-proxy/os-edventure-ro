<?php

namespace App\Services;

use App\Models\EmployeeTimesheet;
use App\Models\Session;
use App\Models\SessionAssistance;
use App\Models\TestSimulation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmployeeTimesheetService
{
    /** Create a pending operational timesheet linked to its originating activity. */
    public function createFromActivity(Session|SessionAssistance|TestSimulation $activity): array
    {
        [$employeeId, $date, $start, $end, $hours, $category, $sessionId] = $this->activityDetails($activity);
        $sourceType = $activity::class;
        $sourceId = (int) $activity->getKey();

        return DB::transaction(function () use ($employeeId, $date, $start, $end, $hours, $category, $sessionId, $sourceType, $sourceId): array {
            $existing = EmployeeTimesheet::withTrashed()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if ($existing?->trashed()) {
                throw ValidationException::withMessages(['source' => 'A deleted timesheet already exists for this activity. Restore or reconcile it instead of creating a duplicate.']);
            }
            if ($existing) {
                return ['timesheet' => $existing, 'created' => false];
            }

            if ($sessionId !== null) {
                $otherActivity = EmployeeTimesheet::query()
                    ->where('employee_id', $employeeId)
                    ->where('session_id', $sessionId)
                    ->whereIn('category', ['session', 'session_assistance'])
                    ->first();
                if ($otherActivity) {
                    throw ValidationException::withMessages(['session_id' => 'This session already has a timesheet for the employee. It cannot be added again through a second activity record.']);
                }
            }

            $timesheet = EmployeeTimesheet::query()->create([
                'employee_id' => $employeeId,
                'date' => $date,
                'time_start' => $start,
                'time_end' => $end,
                'category' => $category,
                'number_of_hours' => $hours,
                'session_id' => $sessionId,
                'approval_status' => EmployeeTimesheet::APPROVAL_PENDING,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);

            return ['timesheet' => $timesheet, 'created' => true];
        });
    }

    public function approve(User $actor, EmployeeTimesheet $timesheet, ?string $note = null): EmployeeTimesheet
    {
        return $this->review($actor, $timesheet, EmployeeTimesheet::APPROVAL_APPROVED, $note);
    }

    public function reject(User $actor, EmployeeTimesheet $timesheet, string $note): EmployeeTimesheet
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['review_note' => 'A reason is required when rejecting a timesheet.']);
        }

        return $this->review($actor, $timesheet, EmployeeTimesheet::APPROVAL_REJECTED, $note);
    }

    private function review(User $actor, EmployeeTimesheet $timesheet, string $status, ?string $note): EmployeeTimesheet
    {
        if (! Gate::forUser($actor)->allows('employee_timesheet.approve')
            && ! (auth()->id() === $actor->getKey() && function_exists('hexa') && hexa()->can('employee_timesheet.approve'))) {
            throw new AuthorizationException('The timesheet approval permission is required.');
        }

        return DB::transaction(function () use ($actor, $timesheet, $status, $note): EmployeeTimesheet {
            $locked = EmployeeTimesheet::query()->whereKey($timesheet->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->approval_status !== EmployeeTimesheet::APPROVAL_PENDING) {
                throw ValidationException::withMessages(['approval_status' => 'Only pending timesheets can be reviewed.']);
            }

            $locked->recordReview($status, (int) $actor->getKey(), $note === null ? null : trim($note));

            return $locked->refresh();
        });
    }

    /** @return array{int, string, string, string, float, string, int|null} */
    private function activityDetails(Session|SessionAssistance|TestSimulation $activity): array
    {
        $date = null;

        if ($activity instanceof Session) {
            if (! in_array(strtolower(trim((string) $activity->status)), ['complete', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => 'Only completed sessions can create payable timesheets.']);
            }
            $start = CarbonImmutable::parse($activity->starts_at);
            $end = CarbonImmutable::parse($activity->ends_at);
            $employeeId = (int) $activity->teacher_id;
            $category = 'session';
            $sessionId = (int) $activity->getKey();
        } elseif ($activity instanceof SessionAssistance) {
            $session = $activity->session;
            if (! in_array(strtolower(trim((string) $activity->status)), ['complete', 'completed'], true)
                || ! $session instanceof Session
                || ! in_array(strtolower(trim((string) $session->status)), ['complete', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => 'Only completed session assistance linked to a session can create a timesheet.']);
            }
            $start = CarbonImmutable::parse($session->starts_at);
            $end = CarbonImmutable::parse($session->ends_at);
            $employeeId = (int) $activity->teacher_id;
            $date = CarbonImmutable::parse($activity->assistance_date)->toDateString();
            $category = 'session_assistance';
            $sessionId = (int) $session->getKey();
        } else {
            if (strtolower((string) $activity->status) !== 'completed') {
                throw ValidationException::withMessages(['status' => 'Only completed test simulations can create payable timesheets.']);
            }
            $dateValue = CarbonImmutable::parse($activity->date);
            $hours = (float) $activity->number_of_hours;
            if ($hours <= 0) {
                throw ValidationException::withMessages(['number_of_hours' => 'The simulation must have positive hours.']);
            }
            $start = $dateValue->startOfDay();
            $end = $start->addSeconds((int) round($hours * 3600));
            $employeeId = (int) $activity->teacher_id;
            $date = $dateValue->toDateString();
            $category = 'test_simulation';
            $sessionId = null;

            return [$employeeId, $date, $start->toDateTimeString(), $end->toDateTimeString(), round($hours, 2), $category, $sessionId];
        }

        if (! $end->greaterThan($start)) {
            throw ValidationException::withMessages(['time_end' => 'The activity must end after it starts.']);
        }
        $date ??= $start->toDateString();
        $hours = round($start->diffInSeconds($end) / 3600, 2);

        return [$employeeId, $date, $start->toDateTimeString(), $end->toDateTimeString(), $hours, $category, $sessionId];
    }
}
