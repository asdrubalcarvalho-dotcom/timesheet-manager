<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'ai_flagged')) {
                $table->boolean('ai_flagged')->default(false)->after('job_status');
            }

            if (!Schema::hasColumn('timesheets', 'ai_score')) {
                $table->decimal('ai_score', 3, 2)->nullable()->after('ai_flagged');
            }

            if (!Schema::hasColumn('timesheets', 'ai_feedback')) {
                $table->json('ai_feedback')->nullable()->after('ai_score');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'ai_feedback')) {
                $table->dropColumn('ai_feedback');
            }

            if (Schema::hasColumn('timesheets', 'ai_score')) {
                $table->dropColumn('ai_score');
            }

            if (Schema::hasColumn('timesheets', 'ai_flagged')) {
                $table->dropColumn('ai_flagged');
            }
        });
    }
};
