<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('invoices.create');
});

Route::get('/guias', function () {
    return view('despatches.index', ['units' => App\Models\Despatch::UNITS]);
});

Route::get('/platform', function () {
    return view('platform.companies');
});
