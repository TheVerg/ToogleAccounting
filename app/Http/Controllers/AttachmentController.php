<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function preview(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($request, $attachment);

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            ['Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($request, $attachment);

        return Storage::disk('local')->download(
            $attachment->path,
            $attachment->original_name,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    private function authorizeAccess(Request $request, Attachment $attachment): void
    {
        $user = $request->user();

        abort_unless(
            $attachment->company_id === null
                || $user->isSystemAdmin()
                || $user->companies()->whereKey($attachment->company_id)->exists(),
            403,
        );
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
    }
}
