<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Internal\DocumentResultController;
use App\Http\Controllers\Internal\DocumentProgressController;
use App\Http\Controllers\DocumentRouteController;
use App\Http\Controllers\RoutingController;
use App\Http\Controllers\AssistantController;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::resource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.storep');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documents/search', [DocumentController::class, 'search'])->name('documents.search');
    Route::post('/assistant/chat', [AssistantController::class, 'ask'])->name('assistant.chat');
    Route::get('/assistant/chat/{id}', [AssistantController::class, 'status'])->name('assistant.chat.status');
    Route::get('/documents/routing-options', [DocumentRouteController::class, 'routingOptions']);
    // Route::get('/inbox', [DocumentRouteController::class, 'inbox'])->name('documents.inbox');
    Route::get('/routing', [RoutingController::class, 'index'])->name('routing.index');
    Route::post('/routing', [RoutingController::class, 'store'])->name('routing.store');



    Route::get('/documents/{document}', [DocumentController::class, 'view'])->name('documents.view');
    Route::post('/documents/{document}/retry', [DocumentController::class, 'retry'])->name('documents.retry');
    Route::get('/activity', [\App\Http\Controllers\Admin\ActivityController::class, 'index'])
    ->name('admin.activity');
    Route::get('/admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'index'])
        ->name('admin.settings');
    Route::put('/admin/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])
        ->name('admin.settings.update');
    Route::post('/documents/{document}/receive', [DocumentRouteController::class, 'receive'])->name('documents.receive');

    // Route::get('/documents/{document}/routing', [DocumentController::class, 'routing'])->name('documents.routing');
    Route::get('/documents/{document}/raw', [DocumentController::class, 'raw'])->name('documents.raw');

    Route::get('/documents/{document}/sign', [DocumentController::class, 'showSignPage'])->name('documents.sign');
    Route::post('/documents/{document}/sign', [DocumentController::class, 'applySignature']);
    Route::post('/documents/{document}/replace', [RoutingController::class, 'replaceFile'])->name('documents.replace');

    Route::get('/routing/{case}', [RoutingController::class, 'show'])->name('routing.show');
    Route::post('/routing/{case}/files', [RoutingController::class, 'addFile']);
    Route::delete('/routing/{case}/files/{document}', [RoutingController::class, 'deleteFile']);
    Route::post('/routing/{case}/forward', [RoutingController::class, 'forward']);
    Route::post('/routing/{case}/receive', [RoutingController::class, 'receive']);
});


Route::post('/api/internal/documents/{document}/result', DocumentResultController::class)
    ->name('internal.documents.result');

Route::post('/api/internal/documents/{document}/progress', DocumentProgressController::class)
->name('internal.documents.progress');

require __DIR__.'/settings.php';
