<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Immagine avatar: ritaglio quadrato centrato 256×256 (GD), JPEG o PNG con trasparenza. */
class AvatarController extends Controller
{
    public function update(Request $request)
    {
        return $this->store($request, $request->user());
    }

    public function destroy(Request $request)
    {
        return $this->remove($request->user());
    }

    /** Amministrazione: avatar di un utente o di un bot. */
    public function adminUpdate(Request $request, User $user)
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);

        return $this->store($request, $user);
    }

    public function adminDestroy(Request $request, User $user)
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);

        return $this->remove($user);
    }

    private function store(Request $request, User $user)
    {
        $request->validate(['avatar' => 'required|file|image|mimes:jpeg,jpg,png,webp,gif|max:8192']);
        $path = self::save($request->file('avatar'), $user);
        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return new UserResource($user->fresh());
    }

    private function remove(User $user)
    {
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->forceFill(['avatar_path' => null])->save();

        return new UserResource($user->fresh());
    }

    public static function save(UploadedFile $file, User $user, int $size = 256): string
    {
        $name = 'avatars/'.$user->id.'-'.Str::random(16);
        $src = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($file->getRealPath())) : false;
        if (! $src) {
            // GD non disponibile o formato non leggibile: salviamo l'originale
            $ext = in_array($e = strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif']) ? str_replace('jpeg', 'jpg', $e) : 'jpg';
            Storage::disk('public')->putFileAs('avatars', $file, basename($name).'.'.$ext);

            return $name.'.'.$ext;
        }
        // orientamento EXIF delle foto da smartphone
        if (function_exists('exif_read_data') && in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'])) {
            $o = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
            $src = match ((int) $o) { 3 => imagerotate($src, 180, 0), 6 => imagerotate($src, -90, 0), 8 => imagerotate($src, 90, 0), default => $src };
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        $png = in_array($file->getMimeType(), ['image/png', 'image/gif', 'image/webp']);
        if ($png) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
        ob_start();
        $png ? imagepng($dst, null, 6) : imagejpeg($dst, null, 86);
        $bytes = ob_get_clean();
        $path = $name.($png ? '.png' : '.jpg');
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
}
