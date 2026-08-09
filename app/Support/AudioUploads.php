<?php

namespace App\Support;

/**
 * What counts as an uploadable recording, in one place for every audio module.
 *
 * Laravel's `mimetypes` rule ignores what the browser declares and asks finfo to guess
 * from the bytes. WebM, Ogg and MP4 are containers shared with video, and an audio-only
 * recording carries the same signature as a film, so finfo answers video/webm for what
 * MediaRecorder produced as audio/webm. Listing only the audio/* spellings therefore
 * rejected every recording made in Chrome, Edge or Safari — Firefox happened to pass
 * because its Ogg output is guessed as audio/ogg.
 *
 * Verified with real files rather than assumed:
 *   webm/opus -> video/webm      ogg/opus -> audio/ogg
 *   mp4/aac   -> video/mp4       m4a/aac  -> audio/x-m4a
 *
 * The video/* spellings are accepted because refusing them means refusing the recorder
 * built into the app. A file with actual pictures in it would pass this check; the size
 * cap and the space's own storage quota are what bound that, and the recording only ever
 * plays back through an <audio> element.
 */
final class AudioUploads
{
    /** @var list<string> */
    public const MIME_TYPES = [
        'audio/webm', 'video/webm',
        'audio/ogg', 'video/ogg', 'application/ogg',
        'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/aac',
        'audio/mpeg',
        'audio/wav', 'audio/x-wav',
    ];

    /** The `mimetypes:` half of a validation rule. */
    public static function rule(): string
    {
        return 'mimetypes:' . implode(',', self::MIME_TYPES);
    }
}
