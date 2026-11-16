<?php

namespace App\Modules\Invoices\Domain;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * ZATCA (KSA) simplified tax invoice QR payload, Phase 1: base64 of TLV fields
 * 1 seller name, 2 VAT number, 3 timestamp (ISO 8601), 4 total incl. VAT, 5 VAT total.
 * Phase 2 (cryptographic stamp, clearance/reporting) requires ZATCA onboarding.
 */
final class ZatcaQr
{
    public static function payload(string $sellerName, string $vatNumber, string $timestamp, string $total, string $vat): string
    {
        $tlv = '';
        foreach ([1 => $sellerName, 2 => $vatNumber, 3 => $timestamp, 4 => $total, 5 => $vat] as $tag => $value) {
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }

    /** @return array<int, string> */
    public static function decode(string $payload): array
    {
        $bin = (string) base64_decode($payload, true);
        $out = [];
        for ($i = 0; $i < strlen($bin);) {
            $tag = ord($bin[$i]);
            $len = ord($bin[$i + 1]);
            $out[$tag] = substr($bin, $i + 2, $len);
            $i += 2 + $len;
        }

        return $out;
    }

    /** Scannable QR image (inline SVG) of the payload, as phones and the ZATCA app read it. */
    public static function svg(string $payload, int $size = 160): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($payload);

        // Drop the XML declaration so the SVG can be embedded in HTML.
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }
}
