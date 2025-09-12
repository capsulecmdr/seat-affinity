<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use CapsuleCmdr\Affinity\Http\Controllers\AffinityController;

use Seat\Eseye\Eseye;
use Seat\Eseye\Containers\EsiAuthentication;

Route::middleware(['web','auth'])
    ->prefix('affinity')
    ->as('affinity.')
    ->group(function(){
        Route::get('/test', function () {
            return response('This is a dummy route, nothing here yet.', 200);
        })->name('test');

        Route::get('/lab', function () {

            $client_id = config('services.eveonline.client_id');
            $client_secret = config('services.eveonline.client_secret');

            $char_id = 2117189532;
            $rt = \Seat\Eveapi\Models\RefreshToken::where('character_id',$char_id)->first();

            $auth = new EsiAuthentication([
            'client_id'  => $client_id,
            'secret' => $client_secret,
            'refresh_token' => $rt->token,
            // 'access_token' optional; Eseye will fetch one using the refresher
            // 'scopes' optional; Eseye can work without providing this
            ]);

            $esi = new Eseye($auth);

            $me = $esi->invoke('get','/characters/{character_id}/', ['character_id' => $char_id]);

            return $me;
        })->name('lab');

        Route::get('/entities', [AffinityController::class, 'entityManager'])->name('entities.index');
        Route::post('/entities/trust', [AffinityController::class, 'updateTrust'])->name('entities.updateTrust');


        Route::get('/settings', [AffinityController::class, 'settings'])->name('settings');
        Route::post('/settings', [AffinityController::class, 'updateSettings'])->name('settings.update');
        
        Route::get('/about',[AffinityController::class,'about'])->name('about');


    });