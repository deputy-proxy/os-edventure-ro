<?php

namespace App\Services\Payroll;

use App\Models\EmployeePaySlip;
use App\Models\PayrollCalculationRun;
use App\Models\PayrollException;
use App\Models\PayrollExportEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;

class PayrollPeriodExportService
{
    /**
     * @return array{content: string, filename: string, media_type: string, row_count: int, content_sha256: string}
     */
    public function export(User $actor, EmployeePaySlip $paySlip, int $runId, string $type, string $format): array
    {
        $this->authorize($actor);
        if (! in_array($type, ['summary', 'line_items', 'exceptions'], true)) {
            throw ValidationException::withMessages(['report_type' => 'Unsupported payroll report type. Only internal summaries, line-item detail, and exception lists are implemented; statutory submissions are not available.']);
        }
        if (! in_array($format, ['csv', 'json'], true)) {
            throw ValidationException::withMessages(['format' => 'Unsupported payroll export format. Choose CSV or JSON.']);
        }

        $period = EmployeePaySlip::query()->findOrFail($paySlip->getKey());
        $approvedSnapshot = $period->getAttribute('approved_snapshot');
        if (! in_array($period->approval_status, ['approved', 'closed'], true)
            || ! is_array($approvedSnapshot)
            || ! $period->approved_snapshot_hash
            || ! $period->approved_at) {
            throw ValidationException::withMessages(['employee_pay_slip_id' => 'Payroll exports are available only from an approved, locked period with an immutable approval snapshot.']);
        }

        $grossSnapshot = $approvedSnapshot['gross_calculation'] ?? null;
        if (is_array($grossSnapshot)) {
            return $this->exportGross($actor, $period, $approvedSnapshot, $grossSnapshot, $runId, $type, $format);
        }

        $approvedRuns = (array) ($approvedSnapshot['calculation_runs'] ?? []);
        $runSnapshot = collect($approvedRuns)->first(static fn ($snapshot): bool => is_array($snapshot) && (int) ($snapshot['id'] ?? 0) === $runId);
        if (! is_array($runSnapshot)) {
            throw ValidationException::withMessages(['payroll_calculation_run_id' => 'The selected calculation version is not part of this period’s approved snapshot.']);
        }

        $run = PayrollCalculationRun::query()->with('result.items')->findOrFail($runId);
        if ((int) $run->employee_pay_slip_id !== (int) $period->getKey()
            || (int) $run->employee_id !== (int) $period->employee_id
            || (string) $run->calculation_version !== (string) ($runSnapshot['calculation_version'] ?? '')
            || (string) $run->run_status !== (string) ($runSnapshot['run_status'] ?? '')
            || ! $run->result
            || (string) $run->result->result_status !== (string) ($runSnapshot['result']['status'] ?? '')
            || (string) $run->result->snapshot_hash !== (string) ($runSnapshot['result']['snapshot_hash'] ?? '')) {
            throw ValidationException::withMessages(['payroll_calculation_run_id' => 'The selected calculation result does not match the immutable approved snapshot.']);
        }

        $policySnapshot = is_array($runSnapshot['policy_snapshot'] ?? null) ? $runSnapshot['policy_snapshot'] : [];
        $resultSnapshotValue = $run->result->getAttribute('result_snapshot');
        $resultSnapshot = is_array($resultSnapshotValue) ? $resultSnapshotValue : [];
        $periodStart = Carbon::parse((string) $run->period_start)->toDateString();
        $periodEnd = Carbon::parse((string) $run->period_end)->toDateString();
        if ($periodStart !== Carbon::parse((string) $period->month)->startOfMonth()->toDateString()
            || $periodEnd !== Carbon::parse((string) $period->month)->endOfMonth()->toDateString()) {
            throw ValidationException::withMessages(['payroll_calculation_run_id' => 'The selected calculation run does not cover the approved payslip month.']);
        }
        $metadata = [
            'schema_version' => 'internal-payroll-export-v1',
            'report_type' => 'internal_payroll_'.$type,
            'employee_pay_slip_id' => (int) $period->getKey(),
            'employee_id' => (int) $period->employee_id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payroll_calculation_run_id' => (int) $run->getKey(),
            'calculation_version' => (string) $run->calculation_version,
            'policy_id' => isset($policySnapshot['id']) ? (int) $policySnapshot['id'] : null,
            'policy_key' => $policySnapshot['policy_key'] ?? null,
            'policy_version' => isset($policySnapshot['version']) ? (int) $policySnapshot['version'] : null,
            'result_status' => (string) $run->result->result_status,
            'result_snapshot_sha256' => (string) $run->result->snapshot_hash,
            'approval_status' => (string) $period->approval_status,
            'approved_by' => $period->approved_by ? (int) $period->approved_by : null,
            'approved_snapshot_sha256' => (string) $period->approved_snapshot_hash,
            'approved_at' => Carbon::parse((string) $period->approved_at)->toIso8601String(),
            'closed_by' => $period->closed_by ? (int) $period->closed_by : null,
            'closed_at' => $period->closed_at ? Carbon::parse((string) $period->closed_at)->toIso8601String() : null,
        ];

        $summary = null;
        $lineItems = [];
        $exceptions = [];
        if (in_array($type, ['summary', 'line_items'], true)) {
            $summary = $this->validatedSummary($resultSnapshot, $policySnapshot, $run->result->result_status);
        }
        if ($type === 'line_items') {
            $lineItems = $this->validatedLineItems($resultSnapshot, (string) ($summary['currency_code'] ?? ''));
        }
        if ($type === 'exceptions') {
            $exceptions = PayrollException::query()
                ->where('payroll_calculation_run_id', $run->getKey())
                ->where('detected_at', '<=', $period->approved_at)
                ->orderBy('code')
                ->orderBy('id')
                ->get()
                ->map(static function (PayrollException $exception): array {
                    if (trim((string) $exception->code) === '' || ! in_array($exception->severity, ['blocking', 'warning'], true)
                        || trim((string) $exception->message) === '' || trim((string) $exception->resolution_guidance) === '') {
                        throw ValidationException::withMessages(['exceptions' => 'An exception record is missing a required export field.']);
                    }

                    return [
                        'exception_id' => (int) $exception->getKey(),
                        'code' => (string) $exception->code,
                        'severity' => (string) $exception->severity,
                        'message' => (string) $exception->message,
                        'resolution_guidance' => (string) $exception->resolution_guidance,
                        'detected_by' => $exception->detected_by ? (int) $exception->detected_by : null,
                        'detected_at' => Carbon::parse((string) $exception->detected_at)->toIso8601String(),
                    ];
                })->all();
        }

        $payload = [
            'metadata' => $metadata,
            'summary' => $summary,
            'line_items' => $type === 'line_items' ? $lineItems : null,
            'exceptions' => $type === 'exceptions' ? $exceptions : null,
        ];
        $this->assertValidUtf8($payload);
        $content = $format === 'json'
            ? $this->jsonContent($payload)
            : $this->csvContent($type, $metadata, $summary, $lineItems, $exceptions);
        $contentHash = hash('sha256', $content);
        $rowCount = match ($type) {
            'summary' => 1,
            'line_items' => count($lineItems),
            'exceptions' => count($exceptions),
        };
        $safeVersion = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $run->calculation_version) ?? 'unknown';
        $filename = sprintf(
            'payroll-%s-employee-%d-%s-run-%d-%s.%s',
            $type,
            (int) $period->employee_id,
            Carbon::parse((string) $period->month)->format('Y-m'),
            (int) $run->getKey(),
            $safeVersion,
            $format,
        );

        DB::transaction(function () use ($actor, $period, $run, $type, $format, $rowCount, $contentHash): void {
            $lockedPeriod = EmployeePaySlip::query()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($lockedPeriod->approval_status, ['approved', 'closed'], true)
                || ! hash_equals((string) $period->approved_snapshot_hash, (string) $lockedPeriod->approved_snapshot_hash)) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'The approved payroll snapshot changed while preparing the export.']);
            }

            PayrollExportEvent::query()->create([
                'employee_pay_slip_id' => $lockedPeriod->getKey(),
                'payroll_calculation_run_id' => $run->getKey(),
                'actor_id' => $actor->getKey(),
                'report_type' => 'internal_payroll_'.$type,
                'format' => $format,
                'row_count' => $rowCount,
                'approved_snapshot_hash' => $lockedPeriod->approved_snapshot_hash,
                'content_sha256' => $contentHash,
                'created_at' => now(),
            ]);
        });

        return [
            'content' => $content,
            'filename' => $filename,
            'media_type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8',
            'row_count' => $rowCount,
            'content_sha256' => $contentHash,
        ];
    }

    /** @return array{content: string, filename: string, media_type: string, row_count: int, content_sha256: string} */
    private function exportGross(User $actor, EmployeePaySlip $period, array $approvedSnapshot, array $grossSnapshot, int $version, string $type, string $format): array
    {
        $approvedVersion = (int) ($grossSnapshot['version'] ?? 0);
        if ($approvedVersion <= 0 || $version !== $approvedVersion
            || (int) $period->active_calculation_version !== $approvedVersion
            || (string) ($grossSnapshot['gross_total'] ?? '') !== (string) $period->amount) {
            throw ValidationException::withMessages(['gross_calculation_version' => 'The selected gross calculation version is not part of the immutable approved snapshot.']);
        }

        $sourceItems = $approvedSnapshot['employee_pay_slip']['source_items'] ?? null;
        if (! is_array($sourceItems)) {
            throw ValidationException::withMessages(['source_items' => 'The approved gross calculation is missing its source-linked line items.']);
        }

        $lineItems = [];
        foreach ($sourceItems as $index => $item) {
            if (! is_array($item) || (int) ($item['calculation_version'] ?? 0) !== $approvedVersion
                || ! isset($item['id'], $item['source_type'], $item['source_id'], $item['occurred_on'], $item['description'], $item['quantity'], $item['unit'], $item['unit_rate'], $item['amount'])
                || ! is_numeric($item['amount']) || ! is_numeric($item['quantity']) || ! is_numeric($item['unit_rate'])
                || ($item['currency'] ?? null) !== 'RON') {
                throw ValidationException::withMessages(['source_items' => 'An approved gross line is missing a required field or does not match the calculation version.']);
            }

            $lineItems[] = [
                'line_number' => $index + 1,
                'item_type' => (string) $item['item_type'],
                'source_type' => (string) $item['source_type'],
                'source_id' => (int) $item['source_id'],
                'occurred_on' => (string) $item['occurred_on'],
                'description' => (string) $item['description'],
                'quantity' => (string) $item['quantity'],
                'unit' => (string) $item['unit'],
                'unit_rate' => (string) $item['unit_rate'],
                'amount' => (string) $item['amount'],
                'currency_code' => 'RON',
                'calculation_snapshot_json' => json_encode($item['calculation_snapshot'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $periodStart = Carbon::parse((string) $period->month)->startOfMonth()->toDateString();
        $periodEnd = Carbon::parse((string) $period->month)->endOfMonth()->toDateString();
        $metadata = [
            'schema_version' => 'internal-payroll-export-v1',
            'report_type' => 'internal_payroll_gross_'.$type,
            'employee_pay_slip_id' => (int) $period->getKey(),
            'employee_id' => (int) $period->employee_id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'gross_calculation_version' => $approvedVersion,
            'formula_version' => (string) ($grossSnapshot['formula_version'] ?? 'gross-v1'),
            'currency_code' => 'RON',
            'gross_amount' => (string) $grossSnapshot['gross_total'],
            'calculation_history' => $grossSnapshot['calculation_history'] ?? [],
            'active_calculation_snapshot' => $grossSnapshot['active_calculation_snapshot'] ?? [],
            'approval_status' => (string) $period->approval_status,
            'approved_by' => $period->approved_by ? (int) $period->approved_by : null,
            'approved_snapshot_sha256' => (string) $period->approved_snapshot_hash,
            'approved_at' => Carbon::parse((string) $period->approved_at)->toIso8601String(),
            'closed_by' => $period->closed_by ? (int) $period->closed_by : null,
            'closed_at' => $period->closed_at ? Carbon::parse((string) $period->closed_at)->toIso8601String() : null,
        ];
        $summary = [
            'currency_code' => 'RON',
            'gross_amount' => (string) $grossSnapshot['gross_total'],
            'calculation_version' => $approvedVersion,
            'formula_version' => (string) ($grossSnapshot['formula_version'] ?? 'gross-v1'),
            'line_count' => count($lineItems),
            'rounding' => (string) ($grossSnapshot['rounding'] ?? ''),
        ];
        $calculationContext = (array) ($grossSnapshot['active_calculation_snapshot'] ?? []);
        $exceptions = array_map(static fn (string $warning): array => [
            'code' => 'gross_calculation_warning',
            'severity' => 'warning',
            'message' => $warning,
        ], (array) ($calculationContext['warnings'] ?? []));
        $medicalConflicts = (array) ($calculationContext['medical_leave_date_conflicts'] ?? []);
        if (($medicalConflicts['has_overlap'] ?? false) === true) {
            $exceptions[] = [
                'code' => 'medical_leave_date_conflict',
                'severity' => 'warning',
                'message' => 'Medical leave dates overlap the pay period. Manual review is required; no benefit or deduction was calculated.',
                'overlapping_date_ranges' => $medicalConflicts['overlapping_date_ranges'] ?? [],
            ];
        }
        $payload = [
            'metadata' => $metadata,
            'summary' => $type === 'summary' ? $summary : null,
            'line_items' => $type === 'line_items' ? $lineItems : null,
            'exceptions' => $type === 'exceptions' ? $exceptions : null,
        ];
        $this->assertValidUtf8($payload);
        $content = $format === 'json'
            ? $this->jsonContent($payload)
            : $this->csvContent($type, $metadata, $type === 'summary' ? $summary : null, $lineItems, $exceptions);
        $contentHash = hash('sha256', $content);
        $rowCount = match ($type) {
            'summary' => 1,
            'line_items' => count($lineItems),
            'exceptions' => count($exceptions),
            default => throw ValidationException::withMessages(['type' => 'Unsupported payroll export type.']),
        };
        $filename = sprintf('payroll-%s-employee-%d-%s-gross-v%d.%s', $type, (int) $period->employee_id, Carbon::parse((string) $period->month)->format('Y-m'), $approvedVersion, $format);

        DB::transaction(function () use ($actor, $period, $approvedVersion, $type, $format, $rowCount, $contentHash): void {
            $lockedPeriod = EmployeePaySlip::query()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($lockedPeriod->approval_status, ['approved', 'closed'], true)
                || (int) $lockedPeriod->active_calculation_version !== $approvedVersion
                || ! hash_equals((string) $period->approved_snapshot_hash, (string) $lockedPeriod->approved_snapshot_hash)) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'The approved gross payroll snapshot changed while preparing the export.']);
            }

            PayrollExportEvent::query()->create([
                'employee_pay_slip_id' => $lockedPeriod->getKey(),
                'payroll_calculation_run_id' => null,
                'gross_calculation_version' => $approvedVersion,
                'actor_id' => $actor->getKey(),
                'report_type' => 'internal_payroll_gross_'.$type,
                'format' => $format,
                'row_count' => $rowCount,
                'approved_snapshot_hash' => $lockedPeriod->approved_snapshot_hash,
                'content_sha256' => $contentHash,
                'created_at' => now(),
            ]);
        });

        return [
            'content' => $content,
            'filename' => $filename,
            'media_type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8',
            'row_count' => $rowCount,
            'content_sha256' => $contentHash,
        ];
    }

    /** @return array<string, string|int> */
    private function validatedSummary(array $result, array $policy, string $resultStatus): array
    {
        $currency = $result['currency_code'] ?? null;
        $gross = $result['gross_amount'] ?? null;
        $deductions = $result['deductions_amount'] ?? null;
        $net = $result['net_amount'] ?? null;
        if ($resultStatus !== 'calculated'
            || ! isset($policy['id'], $policy['version'])
            || ! is_numeric($gross)
            || ! is_numeric($deductions)
            || ! is_numeric($net)
            || ! is_string($currency)
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages(['calculation_result' => 'The selected run is missing required internal summary fields or does not contain a calculated result.']);
        }

        return [
            'currency_code' => $currency,
            'gross_amount' => (string) $gross,
            'deductions_amount' => (string) $deductions,
            'net_amount' => (string) $net,
            'rounding_mode' => (string) ($result['rounding_mode'] ?? ''),
            'rounding_precision' => (int) ($result['rounding_precision'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function validatedLineItems(array $result, string $currency): array
    {
        $items = $result['line_items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages(['line_items' => 'The selected calculation version has no line-item detail to export.']);
        }

        $validated = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)
                || ! isset($item['line_number'], $item['item_code'], $item['item_type'], $item['label'], $item['amount'], $item['currency_code'])
                || ! is_numeric($item['amount'])
                || ! in_array($item['item_type'], ['earning', 'deduction'], true)
                || $item['currency_code'] !== $currency) {
                throw ValidationException::withMessages(['line_items' => 'A calculation line is missing a required field or uses an inconsistent currency.']);
            }
            $validated[] = [
                'line_number' => (int) $item['line_number'],
                'item_code' => (string) $item['item_code'],
                'item_type' => (string) $item['item_type'],
                'label' => (string) $item['label'],
                'calculation_method' => (string) ($item['calculation_method'] ?? ''),
                'source_parameter' => (string) ($item['source_parameter'] ?? ''),
                'base_item_code' => isset($item['base_item_code']) ? (string) $item['base_item_code'] : '',
                'input_value' => isset($item['input_value']) ? (string) $item['input_value'] : '',
                'rate_percent' => isset($item['rate_percent']) ? (string) $item['rate_percent'] : '',
                'amount' => (string) $item['amount'],
                'currency_code' => (string) $item['currency_code'],
                'rounding_mode' => (string) ($item['rounding_mode'] ?? ''),
                'rounding_precision' => (int) ($item['rounding_precision'] ?? 0),
                'calculation_snapshot_json' => json_encode($item['calculation_snapshot'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $validated;
    }

    private function jsonContent(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        } catch (JsonException) {
            throw ValidationException::withMessages(['encoding' => 'Payroll export data contains invalid UTF-8 or cannot be encoded as JSON.']);
        }
    }

    /** @param 'summary'|'line_items'|'exceptions' $type */
    private function csvContent(string $type, array $metadata, ?array $summary, array $lineItems, array $exceptions): string
    {
        $common = array_keys($metadata);
        $dataRows = match ($type) {
            'summary' => [$summary ?? []],
            'line_items' => $lineItems,
            'exceptions' => $exceptions,
        };
        $dataHeaders = match ($type) {
            'summary' => array_keys($summary ?? []),
            'line_items' => array_keys($lineItems[0] ?? [
                'line_number' => null, 'item_code' => null, 'item_type' => null, 'label' => null,
                'calculation_method' => null, 'source_parameter' => null, 'base_item_code' => null,
                'input_value' => null, 'rate_percent' => null, 'amount' => null, 'currency_code' => null,
                'rounding_mode' => null, 'rounding_precision' => null, 'calculation_snapshot_json' => null,
            ]),
            'exceptions' => array_keys($exceptions[0] ?? [
                'exception_id' => null, 'code' => null, 'severity' => null, 'message' => null,
                'resolution_guidance' => null, 'detected_by' => null, 'detected_at' => null,
            ]),
        };
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw ValidationException::withMessages(['format' => 'The CSV export stream could not be created.']);
        }
        fwrite($stream, "\xEF\xBB\xBF");
        $headers = array_merge($common, $dataHeaders);
        if (fputcsv($stream, $headers, ',', '"', '', "\r\n") === false) {
            fclose($stream);
            throw ValidationException::withMessages(['format' => 'The CSV header could not be written.']);
        }
        foreach ($dataRows as $row) {
            $values = array_merge(array_values($metadata), array_values($row));
            $values = array_map(fn ($value): string => $this->csvValue($value), $values);
            if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
                fclose($stream);
                throw ValidationException::withMessages(['format' => 'A CSV row could not be written.']);
            }
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if (! is_string($content)) {
            throw ValidationException::withMessages(['format' => 'The CSV export stream could not be read.']);
        }

        return $content;
    }

    private function csvValue(mixed $value): string
    {
        if (is_array($value)) {
            try {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                throw ValidationException::withMessages(['encoding' => 'A CSV cell contains invalid UTF-8 or cannot be encoded.']);
            }
        }
        $string = $value === null ? '' : (string) $value;
        if (! mb_check_encoding($string, 'UTF-8')) {
            throw ValidationException::withMessages(['encoding' => 'Payroll export data contains invalid UTF-8.']);
        }
        if ($string !== '' && ! is_numeric($string) && preg_match('/^[=+@\-\t\r]/', $string) === 1) {
            $string = "'".$string;
        }

        return $string;
    }

    private function assertValidUtf8(mixed $value): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages(['encoding' => 'Payroll export data contains invalid UTF-8.']);
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->assertValidUtf8($nested);
            }
        }
    }

    private function authorize(User $actor): void
    {
        if (Gate::forUser($actor)->allows('payroll_period.export')) {
            return;
        }
        if (auth()->id() === $actor->getKey() && function_exists('hexa') && hexa()->can('payroll_period.export')) {
            return;
        }

        throw new AuthorizationException('The payroll export permission is required.');
    }
}
