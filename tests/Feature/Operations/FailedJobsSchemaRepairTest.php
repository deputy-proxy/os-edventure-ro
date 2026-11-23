<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailedJobsSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_creates_the_standard_failed_jobs_schema_when_missing(): void
    {
        Schema::drop('failed_jobs');

        $migration = require database_path('migrations/2026_11_23_000001_repair_missing_failed_jobs_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertTrue(Schema::hasColumns('failed_jobs', [
            'id',
            'uuid',
            'connection',
            'queue',
            'payload',
            'exception',
            'failed_at',
        ]));
    }

    public function test_repair_is_safe_to_repeat_and_preserves_existing_failed_jobs(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'non-sensitive test fixture',
            'failed_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_23_000001_repair_missing_failed_jobs_table.php');
        $migration->up();

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    public function test_repair_rollback_does_not_drop_operational_failure_records(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'non-sensitive test fixture',
            'failed_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_23_000001_repair_missing_failed_jobs_table.php');
        $migration->down();

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }
}
