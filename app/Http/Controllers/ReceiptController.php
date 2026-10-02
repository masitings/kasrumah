<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReceiptController extends Controller
{
    /** Thumbnails stay behind auth: only the owner can fetch their own receipt. */
    public function show(Request $request, Expense $expense): SymfonyResponse
    {
        abort_unless($expense->user_id === $request->user()->id, 404);
        abort_unless($expense->image_path !== null, 404);
        abort_unless(Storage::disk('local')->exists($expense->image_path), 404);

        return Storage::disk('local')->response($expense->image_path, $this->filename($expense));
    }

    private function filename(Expense $expense): string
    {
        return 'receipt-'.$expense->id.'.'.pathinfo($expense->image_path, PATHINFO_EXTENSION);
    }
}
