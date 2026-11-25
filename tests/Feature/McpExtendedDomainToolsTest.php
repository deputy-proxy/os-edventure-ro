<?php

namespace Tests\Feature;

use App\Mcp\Authorization\McpToolPermissionRegistry;
use App\Mcp\Server\McpServerFactory;
use App\Mcp\Tools\Extended\ExtendedDomainMcpCapabilityRegistrar;
use App\Models\User;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpExtendedDomainToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionPath;

    private string $keyPath;

    private User $principal;

    /** @var list<string> */
    private array $scopes;

    private const TOOLS = [
        'get_hr_overview',
        'get_approved_evaluation_summary',
        'get_timesheet_status',
        'get_payroll_period_status',
        'get_payroll_validation_exceptions',
        'get_product_catalogue',
        'get_product_cost_summary',
        'get_product_profitability',
        'get_campaign_performance',
        'get_google_ads_import_status',
        'get_market_observations',
        'get_academic_overview',
        'get_attendance_summary',
        'get_group_operations',
    ];

    /** @var list<string> */
    private const DOMAIN_PERMISSIONS = [
        'employee.index',
        'employee_evaluation.index',
        'employee_timesheet.index',
        'employee_pay_slip.index',
        'payroll_exception.index',
        'product.index',
        'product_cost.index',
        'finance-profitability.view',
        'google_ads_metrics.view',
        'market_trend.index',
        'student.index',
        'group.index',
        'session.index',
        'session_attendance.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPath = storage_path('framework/testing/mcp-extended-passport');
        File::ensureDirectoryExists($this->keyPath, 0700, true);
        $private = $this->keyPath.'/oauth-private.key';
        $public = $this->keyPath.'/oauth-public.key';
        if (! is_file($private) || ! is_file($public)) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $privateKey));
            $details = openssl_pkey_get_details($key);
            $this->assertIsArray($details);
            file_put_contents($private, $privateKey);
            file_put_contents($public, $details['key']);
            chmod($private, 0600);
            chmod($public, 0600);
        }
        Passport::loadKeysFrom($this->keyPath);

        $this->sessionPath = storage_path('framework/testing/mcp-extended-sessions');
        config()->set('mcp.session_path', $this->sessionPath);
        File::deleteDirectory($this->sessionPath);

        $this->principal = User::factory()->create(['email' => 'mcp-extended@example.test']);
        config()->set('mcp.ai_principal_email', $this->principal->email);
        $this->scopes = ['mcp:use', ...array_keys((new McpToolPermissionRegistry)->passportScopes())];

        app()->instance(
            McpServerFactory::class,
            new McpServerFactory([app(ExtendedDomainMcpCapabilityRegistrar::class)]),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sessionPath);
        parent::tearDown();
    }

    public function test_extended_domain_tools_are_read_only_and_have_closed_schemas(): void
    {
        $this->grant(self::DOMAIN_PERMISSIONS);

        $tools = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass,
        ], $this->initialize())->assertOk()->json('result.tools');

        $this->assertEqualsCanonicalizing(self::TOOLS, array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertFalse($tool['inputSchema']['additionalProperties'] ?? true);
            $permission = (new McpToolPermissionRegistry)->forTool($tool['name']);
            $this->assertSame('read', $permission->operationType);
            $this->assertFalse($permission->requiresApproval);
            $this->assertNotEmpty($permission->allowedFields);
        }
    }

    public function test_authorized_domain_reads_return_aggregate_safe_views(): void
    {
        $this->grant(self::DOMAIN_PERMISSIONS);
        $session = $this->initialize();
        $calls = [
            ['get_hr_overview', []],
            ['get_approved_evaluation_summary', []],
            ['get_timesheet_status', ['start_date' => '2026-11-01', 'end_date' => '2026-11-22']],
            ['get_payroll_period_status', []],
            ['get_payroll_validation_exceptions', []],
            ['get_product_catalogue', []],
            ['get_product_cost_summary', []],
            ['get_product_profitability', []],
            ['get_campaign_performance', []],
            ['get_google_ads_import_status', []],
            ['get_market_observations', []],
            ['get_academic_overview', []],
            ['get_attendance_summary', ['start_date' => '2026-11-01', 'end_date' => '2026-11-22']],
            ['get_group_operations', []],
        ];

        foreach ($calls as [$name, $arguments]) {
            $response = $this->callTool($name, $arguments, $session)->assertOk();
            $this->assertIsArray($response->json('result.structuredContent'), $name);
            $this->assertDatabaseHas('mcp_execution_audits', [
                'tool_name' => $name,
                'status' => 'succeeded',
                'ai_principal_id' => $this->principal->id,
            ]);
        }
    }

    public function test_hr_tool_denies_access_without_its_specific_domain_permission(): void
    {
        $this->grant(['product.index']);
        $response = $this->callTool('get_hr_overview', [], $this->initialize())->assertOk();

        $this->assertDatabaseHas('mcp_execution_audits', [
            'tool_name' => 'get_hr_overview',
            'status' => 'failed',
            'error_code' => 'authorization_denied',
            'ai_principal_id' => $this->principal->id,
        ]);
    }

    public function test_timesheet_and_attendance_queries_reject_ranges_over_ninety_days(): void
    {
        $this->grant(self::DOMAIN_PERMISSIONS);
        $session = $this->initialize();

        $timesheets = $this->callTool('get_timesheet_status', [
            'start_date' => '2026-01-01', 'end_date' => '2026-04-10',
        ], $session)->assertOk();
        $this->assertDatabaseHas('mcp_execution_audits', ['tool_name' => 'get_timesheet_status', 'status' => 'failed', 'error_code' => 'validation_failed']);

        $attendance = $this->callTool('get_attendance_summary', [
            'start_date' => '2026-01-01', 'end_date' => '2026-04-10',
        ], $session)->assertOk();
        $this->assertDatabaseHas('mcp_execution_audits', ['tool_name' => 'get_attendance_summary', 'status' => 'failed', 'error_code' => 'validation_failed']);
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $role = HexaRole::query()->create([
            'name' => 'Assistant',
            'created_by_name' => 'Test',
            'access' => ['Extended MCP tools' => $permissions],
            'guard' => hexa()->guard(),
        ]);
        $this->principal->roles()->attach($role->getKey());
    }

    private function initialize(): string
    {
        $response = $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'mcp-extended-test', 'version' => '1.0'],
            ],
        ])->assertOk();
        $id = $response->headers->get('Mcp-Session-Id');
        $this->assertNotEmpty($id);

        return (string) $id;
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $name, array $arguments, string $session): TestResponse
    {
        return $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ], $session);
    }

    /** @param array<string, mixed> $payload */
    private function mcpPost(array $payload, ?string $session = null): TestResponse
    {
        Passport::actingAs($this->principal, $this->scopes);
        $server = [
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($session !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }

        return $this->call('POST', '/mcp', [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
