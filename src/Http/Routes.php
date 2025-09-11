<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use CapsuleCmdr\Affinity\Http\Controllers\AffinityController;

Route::middleware(['web','auth'])
    ->prefix('affinity')
    ->as('affinity.')
    ->group(function(){
        Route::get('/test', function () {
            return response('This is a dummy route, nothing here yet.', 200);
        })->name('test');

        Route::get('/settings', [AffinityController::class, 'settings'])->name('settings');
        Route::post('/settings', [AffinityController::class, 'updateSettings'])->name('settings.update');
        
        Route::get('/about',[AffinityController::class,'about'])->name('about');


    });