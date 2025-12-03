<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Find tenant
$tenant = \Stancl\Tenancy\Database\Models\Tenant::on('mysql')->find('test-company');

if (!$tenant) {
    echo "Tenant not found!\n";
    exit(1);
}

// Initialize tenancy
tenancy()->initialize($tenant);

// Create admin user
try {
    $user = \App\Models\User::create([
        'name' => 'Admin User',
        'email' => 'admin@testcompany.test',
        'password' => \Illuminate\Support\Facades\Hash::make('admin123'),
    ]);
    
    echo "✅ Admin user created successfully!\n";
    echo "Email: admin@testcompany.test\n";
    echo "Password: admin123\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo "✅ Admin user already exists!\n";
    } else {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}
