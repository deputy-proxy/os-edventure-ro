<?php

namespace App\Models;

use App\Models\Concerns\HasPanelSearchTitle;
use App\Models\Concerns\HasTasks;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\Comments\Concerns\HasComments;
use Relaticle\Comments\Contracts\Commentable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeContract extends Model implements Commentable
{
    public const BASIS_MONTHLY = 'monthly';

    public const BASIS_HOURLY = 'hourly';

    public const COMPENSATION_BASES = [self::BASIS_MONTHLY => 'Monthly salary', self::BASIS_HOURLY => 'Hourly rate'];

    use HasComments;
    use HasFactory;
    use HasPanelSearchTitle;
    use HasTasks;
    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['clauses' => 'array', 'base_salary' => 'decimal:2', 'hourly_rate' => 'decimal:2'];
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
    protected $fillable = ['employee_id', 'start_date', 'end_date', 'contract_type', 'compensation_basis', 'base_salary', 'hourly_rate', 'clauses'];

    public function equipmentReimbursementAmount(): ?float
    {
        $clauses = $this->clauses;
        if (! is_array($clauses)) {
            return null;
        }

        $acceptedKeys = [
            'use_own_equipment_reimbursement',
            'use_own_equipment_reimbursment',
            'UseOwnEquipmentReimbursement',
            'UseOwnEquipmentReimbursment',
        ];

        foreach ($clauses as $key => $value) {
            if (is_string($key) && in_array($key, $acceptedKeys, true)) {
                $amount = is_array($value) ? ($value['amount'] ?? $value['reimbursement_amount'] ?? null) : $value;
                if (is_numeric($amount) && (float) $amount >= 0) {
                    return round((float) $amount, 2, PHP_ROUND_HALF_UP);
                }
            }

            if (! is_array($value) || ! in_array($value['type'] ?? null, $acceptedKeys, true)) {
                continue;
            }

            $data = $value['data'] ?? $value;
            $amount = is_array($data) ? ($data['amount'] ?? $data['reimbursement_amount'] ?? null) : null;
            if (is_numeric($amount) && (float) $amount >= 0) {
                return round((float) $amount, 2, PHP_ROUND_HALF_UP);
            }
        }

        return null;
    }

    public function payrollInputs(): HasMany
    {
        return $this->hasMany(EmployeeContractPayrollInput::class)->orderByDesc('effective_from');
    }

    public function workerEngagements(): HasMany
    {
        return $this->hasMany(WorkerEngagement::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['start_date', 'end_date', 'contract_type', 'compensation_basis', 'base_salary', 'hourly_rate'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function payrollCalculationRuns(): HasMany
    {
        return $this->hasMany(PayrollCalculationRun::class);
    }
}
