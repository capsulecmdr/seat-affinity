<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth'])
    ->prefix('affinity')
    ->as('affinity.')
    ->group(function(){
        Route::get('/test', function () {
            return response('This is a dummy route, nothing here yet.', 200);
        })->name('test');

        Route::get('/about')->name('about');
    });