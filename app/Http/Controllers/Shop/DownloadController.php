<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\DownloadGrant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    /**
     * Stream PDF download using token.
     */
    public function download(string $token, int $productId): Response
    {
        $grant = DownloadGrant::with('product')
            ->where('token', $token)
            ->where('product_id', $productId)
            ->firstOrFail();

        // Check if grant is still valid
        if (!$grant->is_valid) {
            abort(403, 'Download link has expired or reached maximum downloads.');
        }

        $product = $grant->product;

        if (!$product->pdf_path || !Storage::disk('pdfs')->exists($product->pdf_path)) {
            abort(404, 'PDF file not found.');
        }

        // Record download
        $grant->recordDownload();

        // Stream file
        $path = Storage::disk('pdfs')->path($product->pdf_path);
        $filename = $product->pdf_filename ?: basename($path);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
