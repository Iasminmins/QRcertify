<?php
/**
 * Wrapper para chillerlan/php-qrcode.
 * Gera QR Code SVG real, escaneavel, 100% local.
 */

class QRCode
{
    public static function svg(string $data, int $size = 200, string $dark = '#000000', string $light = '#ffffff'): string
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new \RuntimeException('vendor/autoload.php nao encontrado. Execute: composer require chillerlan/php-qrcode');
        }
        require_once $autoload;

        $options = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'eccLevel'        => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone'    => true,
            'quietzoneSize'   => 4,
            'svgAddXmlHeader' => false,
            'outputBase64'    => false,
        ]);

        $qr  = new \chillerlan\QRCode\QRCode($options);
        $svg = (string) $qr->render($data);

        // Remove width/height que a biblioteca coloca e substitui pelos valores solicitados
        // O viewBox já garante o escalonamento correto
        $svg = preg_replace('/(<svg\b[^>]*?)\s+width="[^"]*"/', '$1', $svg);
        $svg = preg_replace('/(<svg\b[^>]*?)\s+height="[^"]*"/', '$1', $svg);
        $svg = str_replace('<svg ', '<svg width="' . $size . '" height="' . $size . '" ', $svg);

        return $svg ?: '';
    }
}