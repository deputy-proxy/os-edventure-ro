<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_direct_write_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_principal_id')->constrained('users')->restrictOnDelete();
            $table->char('oauth_client_id', 36);
            $table->string('tool_name', 128);
            $table->string('tool_version', 32);
            $table->string('request_id', 100)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->string('status', 32)->default('executing');
            $table->text('result')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->foreignId('audit_id')->nullable()->constrained('mcp_execution_audits')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['ai_principal_id', 'oauth_client_id', 'tool_name', 'idempotency_key'],
                'mcp_direct_write_idempotency_unique',
            );
            $table->index(['status', 'started_at'], 'mcp_direct_write_status_idx');
            $table->index(['target_type', 'target_id'], 'mcp_direct_write_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_direct_write_executions');
    }
};
