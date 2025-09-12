<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use CapsuleCmdr\Affinity\Http\Controllers\AffinityController;

use CapsuleCmdr\Affinity\Support\EsiClient;

Route::middleware(['web','auth'])
    ->prefix('affinity')
    ->as('affinity.')
    ->group(function(){
        Route::get('/test', function () {
            return response('This is a dummy route, nothing here yet.', 200);
        })->name('test');

        Route::get('/lab', function () {

            $char_id = 2117189532;

            $esi = new EsiClient();
            $authed = EsiClient::forCharacter($char_id);
            $aff = $authed->post('/characters/affiliation/', [], [$char_id]);

            return $aff;
        })->name('lab');

        Route::get('/entities', [AffinityController::class, 'entityManager'])->name('entities.index');
        Route::post('/entities/trust', [AffinityController::class, 'updateTrust'])->name('entities.updateTrust');


        Route::get('/settings', [AffinityController::class, 'settings'])->name('settings');
        Route::post('/settings', [AffinityController::class, 'updateSettings'])->name('settings.update');
        
        Route::get('/about',[AffinityController::class,'about'])->name('about');


    });