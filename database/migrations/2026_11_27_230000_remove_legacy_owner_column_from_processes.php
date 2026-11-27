<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('processes', 'owner')) {
            Schema::table('processes', function (Blueprint $table) {
                $table->dropColumn('owner');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('processes', 'owner')) {
            Schema::table('processes', function (Blueprint $table) {
                $table->unsignedBigInteger('owner')->nullable()->after('steps');
            });

            if (Schema::hasColumn('processes', 'owner_id')) {
                DB::table('processes')->update(['owner' => DB::raw('owner_id')]);
            }
        }
    }
};
