<?php

use think\facade\Route;

// Lowcode Collection API Routes | 低代码Collection API路由
// All routes require authentication and permission control | 所有路由都需要认证和权限控制
// Permission types: lowcode:read (query), lowcode:write (create/update), lowcode:delete (delete)
// 权限类型：lowcode:read（查询）、lowcode:write（创建/更新）、lowcode:delete（删除）
Route::group('v1/lowcode/collections', function () {
    // Collection Management Routes | Collection管理路由
    Route::get('', 'app\controller\lowcode\CollectionController@index')
        ->middleware(\app\middleware\Permission::class, 'lowcode:read');
    Route::get(':name', 'app\controller\lowcode\CollectionController@read')
        ->middleware(\app\middleware\Permission::class, 'lowcode:read');
    Route::post('', 'app\controller\lowcode\CollectionController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
    Route::put(':name', 'app\controller\lowcode\CollectionController@update')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
    Route::delete(':name', 'app\controller\lowcode\CollectionController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode:delete');

    // Field Management | 字段管理
    Route::post(':name/fields', 'app\controller\lowcode\FieldController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
    Route::delete(':name/fields/:field', 'app\controller\lowcode\FieldController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode:delete');

    // Relationship Management | 关系管理
    Route::post(':name/relationships', 'app\controller\lowcode\RelationshipController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
    Route::delete(':name/relationships/:relationship', 'app\controller\lowcode\RelationshipController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode:delete');
})->middleware(\app\middleware\Auth::class);

// Form Designer Routes | 表单设计器路由
// All routes require authentication and permission control | 所有路由都需要认证和权限控制
Route::group('v1/lowcode', function () {
    // IMPORTANT: Route order matters! More specific/nested routes must come BEFORE general routes
    // 重要：路由顺序很重要！更具体/嵌套的路由必须放在一般路由之前

    // Form Data Resource (Nested) | 表单数据资源（嵌套）
    // These must come FIRST because they are more specific than Form Schema routes
    // 这些必须放在最前面，因为它们比表单Schema路由更具体
    Route::get('forms/:name/data/:id', 'app\controller\lowcode\FormDataController@read')
        ->middleware(\app\middleware\Permission::class, 'lowcode:read');
    Route::delete('forms/:name/data/:id', 'app\controller\lowcode\FormDataController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode:delete');
    Route::get('forms/:name/data', 'app\controller\lowcode\FormDataController@index')
        ->middleware(\app\middleware\Permission::class, 'lowcode:read');
    Route::post('forms/:name/data', 'app\controller\lowcode\FormDataController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');

    // Form Schema Resource | 表单模式资源
    // More specific routes (with parameters) must come BEFORE general routes
    // 更具体的路由（带参数）必须放在一般路由之前
    Route::post('forms/:name/duplicate', 'app\controller\lowcode\FormSchemaController@duplicate')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
    Route::get('forms/:name', 'app\controller\lowcode\FormSchemaController@read')
        ->middleware(\app\middleware\Permission::class, 'lowcode:read');
    Route::put('forms/:name', 'app\controller\lowcode\FormSchemaController@update')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
    Route::delete('forms/:name', 'app\controller\lowcode\FormSchemaController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode:delete');
    Route::get('forms', 'app\controller\lowcode\FormSchemaController@index')
        ->middleware(\app\middleware\Permission::class, 'lowcode:read');
    Route::post('forms', 'app\controller\lowcode\FormSchemaController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode:write');
})->middleware(\app\middleware\Auth::class);
