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
        // Update any existing 'draft' entries to 'submitted'
        \DB::table('expenses')
            ->where('status', 'draft')
            ->update(['status' => 'submitted']);
        
        // SQLite doesn't support MODIFY COLUMN, so we skip the enum modification
        // The validation will be handled at the application level
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse action needed for SQLite
    }
};
