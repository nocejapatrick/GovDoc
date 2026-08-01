<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Internal\DocumentResultController;
use App\Http\Controllers\Internal\DocumentProgressController;
use App\Http\Controllers\DocumentRouteController;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::resource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.storep');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documents/search', [DocumentController::class, 'search'])->name('documents.search');
    Route::get('/documents/routing-options', [DocumentRouteController::class, 'routingOptions']);
    Route::get('/inbox', [DocumentRouteController::class, 'inbox'])->name('documents.inbox');


    Route::get('/documents/{document}', [DocumentController::class, 'view'])->name('documents.view');
    Route::post('/documents/{document}/retry', [DocumentController::class, 'retry'])->name('documents.retry');
    Route::get('/activity', [\App\Http\Controllers\Admin\ActivityController::class, 'index'])
    ->name('admin.activity');
    Route::post('/documents/{document}/forward', [DocumentRouteController::class, 'forward'])->name('documents.forward');
    Route::post('/documents/{document}/receive', [DocumentRouteController::class, 'receive'])->name('documents.receive');

    Route::get('/documents/{document}/routing', [DocumentController::class, 'routing'])->name('documents.routing');
    Route::get('/documents/{document}/raw', [DocumentController::class, 'raw'])->name('documents.raw');
});


Route::post('/api/internal/documents/{document}/result', DocumentResultController::class)
    ->name('internal.documents.result');

Route::post('/api/internal/documents/{document}/progress', DocumentProgressController::class)
->name('internal.documents.progress');

require __DIR__.'/settings.php';
