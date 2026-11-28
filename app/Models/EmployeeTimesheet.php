<?php

namespace App\Models;

use App\Models\Concerns\HasPanelSearchTitle;
use App\Models\Concerns\HasTasks;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Relaticle\Comments\Concerns\HasComments;
use Relaticle\Comments\Contracts\Commentable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeTimesheet extends Model implements Commentable
{
    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    private bool $reviewTransitionAllowed = false;

    use HasComments;
    use HasFactory;
    use HasPanelSearchTitle;
    use HasTasks;
    use LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (self $timesheet): void {
            if (! $timesheet->exists) {
                $timesheet->approval_status ??= self::APPROVAL_PENDING;
                if ($timesheet->approval_status !== self::APPROVAL_PENDING || $timesheet->reviewed_by || $timesheet->reviewed_at || $timesheet->review_note) {
                    throw ValidationException::withMessages(['approval_status' => 'New timesheets must start pending and without review metadata.']);
                }
                if (($timesheet->source_type === null) !== ($timesheet->source_id === null)) {
                    throw ValidationException::withMessages(['source_type' => 'A source model and source ID must be provided together.']);
                }

                return;
            }

            if ($timesheet->isDirty(['approval_status', 'reviewed_by', 'reviewed_at', 'review_note'])) {
                if (! $timesheet->reviewTransitionAllowed
                    || $timesheet->getOriginal('approval_status') !== self::APPROVAL_PENDING
                    || ! in_array($timesheet->approval_status, [self::APPROVAL_APPROVED, self::APPROVAL_REJECTED], true)
                    || ! $timesheet->reviewed_by
                    || ! $timesheet->reviewed_at
                    || ($timesheet->approval_status === self::APPROVAL_REJECTED && blank($timesheet->review_note))) {
                    throw ValidationException::withMessages(['approval_status' => 'Timesheet review metadata can only be changed through the approval workflow.']);
                }
            }

            if (in_array((string) $timesheet->getOriginal('approval_status'), [self::APPROVAL_APPROVED, self::APPROVAL_REJECTED], true)
                && $timesheet->isDirty(['employee_id', 'date', 'time_start', 'time_end', 'category', 'number_of_hours', 'session_id', 'source_type', 'source_id'])) {
                throw ValidationException::withMessages(['approval_status' => 'Reviewed timesheets are immutable. Create a correction record instead of changing an approved or rejected source.']);
            }
        });

        static::deleting(function (self $timesheet): void {
            if ($timesheet->approval_status !== self::APPROVAL_PENDING) {
                throw ValidationException::withMessages(['approval_status' => 'Reviewed timesheets cannot be deleted.']);
            }
        });
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /** @var array<int, string> */
    protected $fillable = ['employee_id', 'date', 'time_start', 'time_end', 'category', 'number_of_hours', 'session_id', 'approval_status', 'reviewed_by', 'reviewed_at', 'review_note', 'source_type', 'source_id'];

    protected function casts(): array
    {
        return ['number_of_hours' => 'decimal:2', 'date' => 'date', 'time_start' => 'immutable_datetime', 'time_end' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function recordReview(string $status, int $reviewerId, ?string $note): void
    {
        $this->reviewTransitionAllowed = true;
        try {
            $this->forceFill([
                'approval_status' => $status,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
        } finally {
            $this->reviewTransitionAllowed = false;
        }
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['date', 'time_start', 'time_end', 'category', 'number_of_hours', 'approval_status', 'reviewed_by', 'reviewed_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
