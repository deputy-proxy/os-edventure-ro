<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_direct_write_executions', function (Blueprint $table): void {
            $table->dropForeign(['audit_id']);
            $table->foreign('audit_id')
                ->references('id')
                ->on('mcp_execution_audits')
                ->nullOnDelete();
        });

        Schema::create('mcp_retention_holds', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->string('hold_type', 16);
            $table->char('reason_hash', 64);
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('placed_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->char('release_reason_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['record_type', 'record_id', 'released_at'], 'mcp_retention_hold_active_idx');
            $table->index(['record_type', 'record_id', 'hold_type'], 'mcp_retention_hold_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_retention_holds');

        Schema::table('mcp_direct_write_executions', function (Blueprint $table): void {
            $table->dropForeign(['audit_id']);
            $table->foreign('audit_id')
                ->references('id')
                ->on('mcp_execution_audits')
                ->restrictOnDelete();
        });
    }
};
