# MCP extended domain tools (#539)

Issue #539 adds fourteen read-only tools across HR/payroll, products, marketing and academic operations. All tools are registered through `ExtendedDomainMcpCapabilityRegistrar`, use the mandatory authorization/audit wrapper, and have dedicated OAuth scopes, required live HexaLite permissions, a global all-record scope limited to aggregate reporting, and explicit nested output allowlists.

The project owner authorized development across all four domains on 2026-11-22. This is development authorization, not unrestricted disclosure or production enablement. The initial contracts deliberately expose aggregate or operational data only. Sensitive field expansion and production rollout remain review gates.

## HR and payroll

| Tool | OAuth scope | Required domain permission | Output |
| --- | --- | --- | --- |
| `get_hr_overview` | `mcp.hr.overview.read` | `employee.index` | Current/deleted employee counts only |
| `get_approved_evaluation_summary` | `mcp.hr.evaluations.read` | `employee_evaluation.index` | Approved evaluation counts and average score by year |
| `get_timesheet_status` | `mcp.hr.timesheets.read` | `employee_timesheet.index` | Entry and recorded-hour aggregates by category, bounded to 90 days |
| `get_payroll_period_status` | `mcp.hr.payroll-periods.read` | `employee_pay_slip.index` | Period and approval-state counts |
| `get_payroll_validation_exceptions` | `mcp.hr.payroll-exceptions.read` | `payroll_exception.index` | Exception counts by period, severity, state and code |

No tool returns employee names, employee identifiers, contact details, date of birth, bank details, salary or payslip line amounts, payroll input notes, evaluation comments, exception messages, resolution notes, or raw exception evidence. Payroll reads are status-only and never invoke payroll calculation, review, approval, close, correction, reversal or payment actions.

Evaluation, timesheet, payroll and attendance breakdowns apply a minimum cohort size of five distinct people. Smaller groups are suppressed; timesheet totals are withheld when fewer than five distinct employees appear in the requested range. Timesheet and attendance date ranges must be valid, ordered and no wider than 90 days.

## Products

| Tool | OAuth scope | Required domain permissions | Output |
| --- | --- | --- | --- |
| `get_product_catalogue` | `mcp.products.catalogue.read` | `product.index` | Product title/status/type and effective-dated price/currency |
| `get_product_cost_summary` | `mcp.products.costs.read` | `product_cost.index` | Monthly allocated costs by currency |
| `get_product_profitability` | `mcp.products.profitability.read` | `product.index`, `finance-profitability.view` | Latest immutable run's currency-separated aggregate totals |

Catalogue prices are resolved through the existing `ProductsOverviewData` service for the as-of date. Missing and ambiguous prices remain explicit and are never inferred. Cost totals use canonical decimal allocated amounts only; legacy integer cost rows are counted separately and excluded from monetary totals. Profitability reads the latest immutable calculation run through `ProfitabilityReportService`, excludes customer details, preserves incomplete/null values, and does not combine currencies or imply statutory profit.

## Marketing

| Tool | OAuth scope | Required domain permission | Output |
| --- | --- | --- | --- |
| `get_campaign_performance` | `mcp.marketing.campaign-performance.read` | `google_ads_metrics.view` | Google Ads daily metric aggregates only |
| `get_google_ads_import_status` | `mcp.marketing.google-ads-imports.read` | `google_ads_metrics.view` | Import configuration status, freshness and recent batch metadata |
| `get_market_observations` | `mcp.marketing.market-observations.read` | `market_trend.index` | Stored Market Trends observations only |

Google Ads metrics retain the source reporting period and currency-specific totals. Cost is reported in micros of the source currency. Import-status responses omit account/customer identifiers, selected campaign IDs, credential references and secret material. Campaign-performance responses expose imported Google Ads campaign IDs/names alongside their aggregate metrics. Market Trends observations retain source, observation time, effective period, unit, quality, verification and freshness metadata. This is a read of stored observations only: MCP does not initiate Market Trends ingestion. PaidCampaign and competitor-offer data have been removed from these payloads. Market Trends ingestion remains a separate capability related to #297/#299.

## Academic operations

| Tool | OAuth scope | Required domain permissions | Output |
| --- | --- | --- | --- |
| `get_academic_overview` | `mcp.academic.overview.read` | `student.index`, `group.index`, `session.index` | Student/enrollment/group/session aggregates |
| `get_attendance_summary` | `mcp.academic.attendance.read` | `session_attendance.index`, `session.index` | Attendance counts by status for a bounded period |
| `get_group_operations` | `mcp.academic.groups.read` | `group.index`, `session.index` | Group and scheduled-session counts by status/type |

Academic outputs do not contain student names, identifiers, dates of birth, contact details, customer details, teacher identities, attendance feedback, achievement notes, or individual attendance rows. Daily enrollment values below five are suppressed. Attendance status buckets require at least five distinct students.

## Authorization and enablement

All fourteen tools are read-only. Each call requires the configured Assistant principal, `mcp:use`, its dedicated OAuth scope, and every required HexaLite domain permission. The wrapper records success/failure audits and filters the returned payload through the tool's explicit allowlist. The Assistant role must receive only the exact permissions required by the tools that are enabled. Google Ads metrics use the independent `google_ads_metrics.view` permission; they no longer borrow `paid_campaign.index`. Market observations depend only on `market_trend.index`.

The `McpGlobalRecordScope` declaration is used only for the all-record aggregate contracts described above. No tool accepts record identifiers or returns unrestricted person-level collections. If a future requirement needs employee, student, payroll or other individual-level records, add a dedicated record-scope contract and a separately reviewed field allowlist rather than widening these tools.

Production enablement remains subject to review of the exact field set, intended audience, retention and operational access controls. Project-level development approval does not remove those release checks.
