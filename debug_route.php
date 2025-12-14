<?php

require __DIR__ . '/vendor/autoload.php';

use think\App;
use think\facade\Route;
use app\controller\lowcode\FormSchemaController;

$app = new App(__DIR__);

// 1. Check if controller class exists
if (class_exists(FormSchemaController::class)) {
    echo "Controller class exists.\n";
} else {
    echo "Controller class NOT found.\n";
}

// 2. Check route loading
// Initialize app to load routes
$http = $app->http;
echo "App initialized.\n";

// Manually load route file to check syntax/path
if (file_exists(__DIR__ . '/route/lowcode.php')) {
    echo "route/lowcode.php exists.\n";
    include __DIR__ . '/route/lowcode.php';
    echo "route/lowcode.php included.\n";
} else {
    echo "route/lowcode.php NOT found.\n";
}

// 3. Check if route is registered
$rules = Route::getRuleList();
// Dump rules related to lowcode
foreach ($rules as $rule) {
    if (strpos($rule['rule'], 'lowcode') !== false) {
        echo 'Route registered: ' . $rule['rule'] . ' -> ' . $rule['route'] . "\n";
    }
}
