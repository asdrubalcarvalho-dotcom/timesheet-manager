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
        Schema::create('travel_segments', function (Blueprint $table) {
            $table->id();

            // Tenant isolation (multi-tenant architecture) - ULID string
            $table->string('tenant_id');

            // Foreign keys
            $table->foreignId('technician_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');

            // Travel details
            $table->date('travel_date');

            // Origin
            $table->string('origin_country', 2); // ISO alpha-2 code (e.g., PT, ES)
            $table->foreignId('origin_location_id')->nullable()->constrained('locations')->onDelete('set null');

            // Destination
            $table->string('destination_country', 2); // ISO alpha-2 code
            $table->foreignId('destination_location_id')->nullable()->constrained('locations')->onDelete('set null');

            // Classification
            $table->enum('direction', [
                'departure',
                'arrival',
                'project_to_project',
                'internal',
                'other',
            ])->default('other');

            $table->string('classification_reason')->nullable();

            // Status
            $table->enum('status', ['planned', 'completed', 'cancelled'])
                  ->default('planned');

            // Future linkage to timesheets (reserved for future use)
            $table->unsignedBigInteger('linked_timesheet_entry_id')->nullable();

            // Audit fields (follows HasAuditFields trait pattern)
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            // Performance indexes (with custom short names to avoid MySQL 64-char limit)
            $table->index(['tenant_id', 'technician_id', 'project_id', 'travel_date'], 'travel_seg_tenant_tech_proj_date_idx');
            $table->index('travel_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_segments');
    }
};
