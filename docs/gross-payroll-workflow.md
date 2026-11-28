# Gross compensation payroll workflow

The active monthly payroll workflow calculates **gross compensation only**. It does not calculate Romanian statutory deductions, employer contributions, income tax, medical-leave benefits, or net pay.

## Contract compensation basis

Every contract used by payroll must explicitly set `compensation_basis` to one of:

- `monthly`: `base_salary` is the gross monthly RON amount.
- `hourly`: `hourly_rate` is the gross RON amount per eligible hour.

The application does not infer the basis from `contract_type`, `base_salary`, or `hourly_rate`. Existing contracts receive a null basis during migration and remain blocked until an authorized user reviews and sets it. Only the rate field matching the selected basis is required in the contract form.

## Monthly salary

The calculator uses the complete calendar month. For each non-overlapping contract segment:

`gross segment = round(monthly base salary * covered calendar days / calendar days in month, 2)`

Contract start and end dates are inclusive. Sequential contracts are calculated as separate segments. Overlapping contracts, unsupported basis values, or missing applicable rates block calculation. Gaps between contract segments are not paid.

## Hourly compensation

Only timesheets with `approval_status = approved` are eligible. Each approved timesheet is matched to the hourly contract segment covering its authoritative `date` field:

`gross line = round(approved timesheet hours * hourly rate, 2)`

Pending and rejected timesheets are excluded and reported as a warning. Unknown approval states and a timesheet without a supported contract covering its work date block calculation. `date` is the authoritative payroll work date; `time_start` and `time_end` remain operational detail.

Session, session-assistance, and completed test-simulation actions create a pending `EmployeeTimesheet` linked to the source activity. They do not create payslip lines. Manual timesheet entry is reserved for non-session work. A timesheet must be approved before payroll can use it. One session cannot be represented by separate session and assistance timesheets for the same employee, preventing duplicate payment of the same scheduled duration.

## Leave and medical records

The current `EmployeeHoliday` schema does not distinguish the gross-pay treatment required by payroll. An approved holiday overlapping the pay month therefore blocks calculation until the leave classification is resolved. Payroll does not infer paid or unpaid leave and does not add holiday earnings or absence deductions.

Medical leave remains a separate restricted workflow. The calculator reads only overlapping dates and returns a date-conflict warning. It does not read or copy document paths, calculate benefits, or apply deductions. Medical documents and details are not included in payslip snapshots or exports.

## Rounding and currency

All calculation lines are RON. Each gross line is rounded half-up to two decimal places; the gross total is the sum of those rounded lines. No foreign-exchange conversion is performed.

## Draft recalculation and history

A draft/open payslip can be recalculated. Each run appends an immutable summary to `gross_calculation_history` and creates a new immutable `calculation_version`; the payslip points to the active version while prior summary snapshots and line items remain retained for audit. Review and export snapshots include the active calculation context, including non-blocking warnings and date-only medical-leave conflicts. Review, approval, close, correction, reversal, and export continue to use the existing payroll lifecycle. Reviewed, approved, closed, and paid periods cannot be recalculated.

A legacy non-zero `manual_amount` blocks the gross-only calculation and must be reconciled before switching that draft to the new workflow. Existing historical line items remain stored; a new gross calculation version becomes active without rewriting them.

## Retired entry points

The policy-driven calculation actions and generic payroll policy/input/exception resources are not part of the normal payroll navigation. Their models, tables, historical records, and audit data are retained. The current gross workflow does not consume generic policy or payroll-input records.

## Explicit exclusions

No statutory tax or contribution arithmetic, medical-leave benefits, unclassified holiday treatment, overtime, bonus, commission, FX conversion, or payment evidence is inferred by this workflow.
