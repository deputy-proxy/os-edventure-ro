<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore Laravel's failed-job table when an earlier migration was only
     * partially applied or the table was removed outside the migration system.
     */
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * This repair is intentionally irreversible: dropping this table could
     * destroy operational failure records created after the repair.
     */
    public function down(): void
    {
        // Intentionally do not drop failed_jobs.
    }
};
