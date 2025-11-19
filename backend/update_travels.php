<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\TravelSegment;
use Carbon\Carbon;

$tenant = Tenant::where('slug', 'success1763470193')->first();

if (!$tenant) {
    echo "Tenant not found!\n";
    exit(1);
}

$tenant->run(function() {
    echo "Updating travel segments...\n";
    
    // Query directly without tenant scope
    $travels = \DB::table('travel_segments')->get();
    echo "Found {$travels->count()} travel segments\n";
    
    foreach ($travels as $travel) {
        if (!$travel->start_at && $travel->travel_date) {
            // Set random morning time
            $hour = rand(6, 11);
            $minute = rand(0, 3) * 15; // 0, 15, 30, 45
            $startAt = Carbon::parse($travel->travel_date)->setTime($hour, $minute);
            
            // Set end_at 1h30min to 4h later
            $duration = rand(90, 240); // 1h30 to 4h in minutes
            $endAt = $startAt->copy()->addMinutes($duration);
            
            \DB::table('travel_segments')
                ->where('id', $travel->id)
                ->update([
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'duration_minutes' => $duration,
                    'updated_at' => now()
                ]);
            
            echo "Updated travel #{$travel->id} - {$startAt->format('Y-m-d H:i')} to {$endAt->format('H:i')} (Duration: {$duration} minutes)\n";
        }
    }
    
    echo "Done!\n";
});
