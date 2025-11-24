<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

echo "=== Testing Request Header Setting ===\n\n";

// Test 1: Create request and set header
$request = $app->make(\think\Request::class);
$request->header('Authorization', 'Bearer test123');

echo "Test 1: Set header using header() method\n";
echo "  Set: Authorization = Bearer test123\n";
echo "  Get: " . ($request->header('Authorization') ?: 'NULL') . "\n\n";

// Test 2: Create request with withHeader
$request2 = $app->make(\think\Request::class);
try {
    $request2 = $request2->withHeader(['Authorization' => 'Bearer test456']);
    echo "Test 2: Set header using withHeader() method\n";
    echo "  Set: Authorization = Bearer test456\n";
    echo "  Get: " . ($request2->header('Authorization') ?: 'NULL') . "\n\n";
} catch (\Exception $e) {
    echo "Test 2 Failed: " . $e->getMessage() . "\n\n";
}

// Test 3: Check if header() is a setter or getter
$request3 = $app->make(\think\Request::class);
$reflection = new \ReflectionMethod($request3, 'header');
echo "Test 3: Inspect header() method\n";
echo "  Parameters: " . $reflection->getNumberOfParameters() . "\n";
foreach ($reflection->getParameters() as $param) {
    echo "    - " . $param->getName() . ($param->isOptional() ? ' (optional)' : '') . "\n";
}

