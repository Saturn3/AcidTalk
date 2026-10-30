<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    /** Upload (immagini, PDF, audio, file generici). L'allegato viene poi collegato al messaggio. */
    public function store(Request $request)
    {
        $max = (int) Organization::current()->setting('max_upload_mb', 25) * 1024;
        $request->validate([
            'file' => ['required', 'file', 'max:'.$max, 'mimes:jpg,jpeg,png,gif,webp,svg,pdf,txt,md,csv,json,xml,zip,doc,docx,xls,xlsx,ppt,pptx,mp3,m4a,aac,ogg,oga,opus,wav,flac,weba,webm,mp4,mov'],
            'duration' => 'nullable|numeric',
            'voice' => 'nullable|boolean',
        ]);
        $file = $request->file('file');
        $path = $file->store('attachments/'.date('Y/m'), 'public');
        $meta = [];
        if ($request->filled('duration')) {
            $meta['duration'] = (float) $request->input('duration');
        }
        if (str_starts_with((string) $file->getMimeType(), 'image/') && ($size = @getimagesize($file->getRealPath()))) {
            $meta['width'] = $size[0];
            $meta['height'] = $size[1];
        }

        $name = $file->getClientOriginalName();
        $mime = (string) $file->getMimeType();
        $kind = Attachment::kindFromMime($mime, $name, $request->boolean('voice') ? 'voice' : $file->getClientMimeType());
        if ($kind === 'audio') {
            $mime = Attachment::audioMime($mime, $name);
        }

        $attachment = Attachment::create([
            'user_id' => $request->user()->id,
            'disk' => 'public',
            'path' => $path,
            'name' => $name,
            'mime' => $mime,
            'size' => $file->getSize(),
            'kind' => $kind,
            'meta' => $meta ?: null,
        ]);

        return response()->json($attachment, 201);
    }

    public function destroy(Request $request, Attachment $attachment)
    {
        abort_unless($attachment->user_id === $request->user()->id && ! $attachment->message_id, 403);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }
}
