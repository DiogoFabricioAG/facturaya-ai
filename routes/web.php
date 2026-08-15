<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('invoices.create');
});

Route::get('/platform', function () {
    return view('platform.companies');
});
