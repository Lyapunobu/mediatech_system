<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class InventoryController extends Controller
{
    public function __invoke(): View
    {
        return view('inventory.index');
    }
}
