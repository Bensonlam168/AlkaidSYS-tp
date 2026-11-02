<?php

use think\facade\Route;

// ===========================================================================
// ⚠️ 为什么本文件每条带参数的路由都带 `->completeMatch()`
// ===========================================================================
// `config/route.php` 的 `route_complete_match = false` 让规则变成**前缀匹配**，
// 于是「先注册的 `:name`」会吃掉「后注册的 `:name/xxx`」——**不是 404，而是走错动作**：
//
//   DELETE v1/lowcode/collections/<name>/fields/<field>          → 命中 CollectionController@delete
//   DELETE v1/lowcode/collections/<name>/relationships/<rel>     → 命中 CollectionController@delete
//
// 也就是说：调用方以为在「删一个字段」，实际执行的是**删掉整张集合**（默认 `drop_table=true`）。
// 修法（与 ER-05 修 `route/admin.php` 时一致）：**父级 `:name` 与更具体的子路由都加**。
// 只给子路由加不够 —— 尾斜杠（`.../fields/<field>/`）匹配不上子路由时会退化回父级的前缀匹配。
//
// 回归防护：`tests/Feature/Route/WorkflowLowcodeRouteReachabilityTest.php`。

// Lowcode Collection API Routes | 低代码Collection API路由
// All routes require authentication and permission control | 所有路由都需要认证和权限控制
// Permission types (internal slug): lowcode.read (query), lowcode.write (create/update), lowcode.delete (delete)
// 权限类型（内部 slug）：lowcode.read（查询）、lowcode.write（创建/更新）、lowcode.delete（删除）
Route::group('v1/lowcode/collections', function () {
    // Collection Management Routes | Collection管理路由
    Route::get('', 'app\controller\lowcode\CollectionController@index')
        ->middleware(\app\middleware\Permission::class, 'lowcode.read');
    Route::get(':name', 'app\controller\lowcode\CollectionController@read')
        ->middleware(\app\middleware\Permission::class, 'lowcode.read')
        ->completeMatch();
    Route::post('', 'app\controller\lowcode\CollectionController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write');
    Route::put(':name', 'app\controller\lowcode\CollectionController@update')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write')
        ->completeMatch();
    // Delete collection | 删除集合
    // ⚠️ `completeMatch()` 必须加：不加时这条前缀匹配会吃掉下面的
    //    `DELETE :name/fields/:field` 与 `DELETE :name/relationships/:relationship`，
    //    调用方以为在删字段，实际把整张集合删了（详见文件头说明）。
    Route::delete(':name', 'app\controller\lowcode\CollectionController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode.delete')
        ->completeMatch();

    // Field Management | 字段管理
    Route::post(':name/fields', 'app\controller\lowcode\FieldController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write')
        ->completeMatch();
    Route::delete(':name/fields/:field', 'app\controller\lowcode\FieldController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode.delete')
        ->completeMatch();

    // Relationship Management | 关系管理
    Route::post(':name/relationships', 'app\controller\lowcode\RelationshipController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write')
        ->completeMatch();
    Route::delete(':name/relationships/:relationship', 'app\controller\lowcode\RelationshipController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode.delete')
        ->completeMatch();
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
        ->middleware(\app\middleware\Permission::class, 'lowcode.read')
        ->completeMatch();
    Route::delete('forms/:name/data/:id', 'app\controller\lowcode\FormDataController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode.delete')
        ->completeMatch();
    Route::get('forms/:name/data', 'app\controller\lowcode\FormDataController@index')
        ->middleware(\app\middleware\Permission::class, 'lowcode.read')
        ->completeMatch();
    Route::post('forms/:name/data', 'app\controller\lowcode\FormDataController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write')
        ->completeMatch();

    // Form Schema Resource | 表单模式资源
    // More specific routes (with parameters) must come BEFORE general routes
    // 更具体的路由（带参数）必须放在一般路由之前
    Route::post('forms/:name/duplicate', 'app\controller\lowcode\FormSchemaController@duplicate')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write')
        ->completeMatch();
    Route::get('forms/:name', 'app\controller\lowcode\FormSchemaController@read')
        ->middleware(\app\middleware\Permission::class, 'lowcode.read')
        ->completeMatch();
    Route::put('forms/:name', 'app\controller\lowcode\FormSchemaController@update')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write')
        ->completeMatch();
    Route::delete('forms/:name', 'app\controller\lowcode\FormSchemaController@delete')
        ->middleware(\app\middleware\Permission::class, 'lowcode.delete')
        ->completeMatch();
    Route::get('forms', 'app\controller\lowcode\FormSchemaController@index')
        ->middleware(\app\middleware\Permission::class, 'lowcode.read');
    Route::post('forms', 'app\controller\lowcode\FormSchemaController@save')
        ->middleware(\app\middleware\Permission::class, 'lowcode.write');
})->middleware(\app\middleware\Auth::class);
