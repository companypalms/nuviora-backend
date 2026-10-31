<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que dice el video de un creativo ganador (`.55`): su texto y su hook, o por qué no se pudo transcribir.
 */
class MetaVideoTranscript extends Model
{
    public const DONE = 'done';
    public const NO_ACCESS = 'no_access';
    public const TOO_BIG = 'too_big';
    public const FAILED = 'failed';

    public const LABELS = [
        self::DONE => 'Transcrito',
        self::NO_ACCESS => 'Meta no deja bajar este video',
        self::TOO_BIG => 'El video es muy grande para transcribirlo',
        self::FAILED => 'No se pudo transcribir (se vuelve a intentar)',
    ];

    protected $fillable = ['video_id', 'meta_ad_account_id', 'status', 'hook', 'text', 'segments', 'model', 'size_bytes', 'error', 'attempts',
        'attempted_at', 'transcribed_at'];

    protected $casts = [
        'segments' => 'array',
        'attempted_at' => 'datetime',
        'transcribed_at' => 'datetime',
    ];
}
