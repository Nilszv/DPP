<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generates the QR carrier for a passport's resolver URL. SVG is vector, so it scales to
 * any print size without quality loss (suits the EN 18220 carrier sizing/quiet-zone rules).
 * PNG (via Imagick) exists for print shops / label systems that cannot ingest SVG.
 */
class QrService
{
    public function svg(string $text, int $size = 320): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($text);
    }

    /** Print-ready raster export. The 1200px default ~= a 10 cm label at 300 dpi. */
    public function png(string $text, int $size = 1200): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size), new ImagickImageBackEnd);

        return (new Writer($renderer))->writeString($text);
    }
}
