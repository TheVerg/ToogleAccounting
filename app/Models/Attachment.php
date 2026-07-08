<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;

/**
 * Supporting document (receipt, invoice copy, contract, ...) stored on
 * the private local disk and linked to the record it substantiates.
 */
class Attachment extends Model
{
    protected $fillable = [
        'company_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Store an uploaded file privately and link it to $model. */
    public static function storeFor(Model $model, UploadedFile $file, int $companyId, ?int $userId = null): self
    {
        $path = $file->store('attachments/'.$companyId, 'local');

        return $model->attachments()->create([
            'company_id' => $companyId,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);
    }
}
