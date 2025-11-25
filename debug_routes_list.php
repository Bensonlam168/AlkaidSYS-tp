<?php

namespace think;

require __DIR__ . '/vendor/autoload.php';

// Initialize App
$app = new App(__DIR__);

// Load routes
if (file_exists(__DIR__ . '/route/lowcode.php')) {
    include __DIR__ . '/route/lowcode.php';
}

// Dump routes
$rules = $app->route->getRuleList();
foreach ($rules as $rule) {
    $route = $rule['route'];
    if ($route instanceof \Closure) {
        $route = 'Closure';
    } elseif (is_array($route)) {
        $route = json_encode($route);
    }
    echo 'Rule: ' . $rule['rule'] . ' -> ' . $route . "\n";
}
