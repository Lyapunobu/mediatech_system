<?php

use App\Http\Controllers\Inventory\InventoryController;
use Illuminate\Support\Facades\Route;

Route::get('/inventory', InventoryController::class)
    ->middleware('permission:inventory.read')
    ->name('inventory.index');
