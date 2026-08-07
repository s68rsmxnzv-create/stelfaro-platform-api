<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\View\View;

class PublicQuotationController extends Controller
{
    public function __invoke(string $token): View
    {
        $quotation = Quotation::query()->where('public_token', $token)->with(['lines', 'tenant'])->firstOrFail();

        return view('quotations.public', compact('quotation'));
    }
}
