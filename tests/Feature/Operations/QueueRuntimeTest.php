<?php

namespace Tests\Feature\Operations;

use App\Jobs\SendCustomerLeadContractEmail;
use App\Mail\CustomerLeadContractMail;
use App\Models\CustomerLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueueRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_worker_completes_a_representative_job(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.retry_after' => 90,
            'queue.failed.database' => config('database.default'),
        ]);
        Mail::fake();
        Storage::fake('local');

        Storage::disk('local')->put('customer-lead-contracts/contract.pdf', '%PDF-1.4 synthetic contract');

        $lead = CustomerLead::factory()->create([
            'email' => 'queue-test@example.test',
            'contract_document' => 'customer-lead-contracts/contract.pdf',
            'contract_send_status' => 'queued',
        ]);

        SendCustomerLeadContractEmail::dispatch($lead->id);

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);

        $startedAt = hrtime(true);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--timeout' => 60,
            '--tries' => 1,
        ])->assertExitCode(0);

        $durationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $this->assertLessThan(60, $durationSeconds, 'The representative queued job must complete inside the configured worker timeout.');

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('sent', $lead->fresh()->contract_send_status);
        $this->assertNotNull($lead->fresh()->contract_sent_at);
        Mail::assertSent(CustomerLeadContractMail::class);
    }

    public function test_schedule_runner_executes_scheduler_heartbeat_and_due_work(): void
    {
        Cache::forget('ops:heartbeat:scheduler');
        $scheduledAt = now()->startOfMinute();
        $this->travelTo($scheduledAt);

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame($scheduledAt->timestamp, Cache::get('ops:heartbeat:scheduler'));
    }
}
