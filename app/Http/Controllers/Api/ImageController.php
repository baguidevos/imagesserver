<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Models\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $images = $request->user()->images()->latest()->paginate(30);

        return response()->json($images);
    }

    public function store(StoreImageRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('image');

        $extension = $file->extension();
        $path = sprintf(
            'applications/%s/users/%s/%s.%s',
            $user->application_id,
            $user->id,
            Str::ulid(),
            $extension,
        );

        Storage::disk('images')->put($path, $file->getContent(), ['visibility' => 'private']);

        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        $image = $user->images()->create([
            'application_id' => $user->application_id,
            'disk' => 'images',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);

        return response()->json([
            'id' => $image->id,
            'name' => $image->original_name,
            'mime_type' => $image->mime_type,
            'size' => $image->size,
            'width' => $image->width,
            'height' => $image->height,
            'created_at' => $image->created_at->toISOString(),
            'download_url' => route('images.show', $image),
        ], 201);
    }

    public function show(string $imageId): StreamedResponse
    {
        $image = Image::findOrFail($imageId);

        return Storage::disk($image->disk)->response(
            $image->path,
            $image->original_name,
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
        );
    }

    public function destroy(Request $request, string $imageId): JsonResponse
    {
        $image = $request->user()->images()->findOrFail($imageId);

        Storage::disk($image->disk)->delete($image->path);
        $image->delete();

        return response()->json(null, 204);
    }
}
