<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use BaconQrCode\Renderer\{ImageRenderer, Image\SvgImageBackEnd, RendererStyle\RendererStyle};
use BaconQrCode\Writer;
use Dompdf\{Dompdf, Options};

final class CertificatePdf
{
    public function render(array $document): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled',false);
        $options->set('isPhpEnabled',false);
        $options->set('isJavascriptEnabled',false);
        $options->set('allowedProtocols',['data://'=>['rules'=>[]]]);
        $options->set('chroot',dirname(__DIR__,2).'/public/assets/brand');
        $options->set('defaultFont','DejaVu Sans');
        $options->set('isFontSubsettingEnabled',true);
        $options->set('fontCache',sys_get_temp_dir());
        $options->set('tempDir',sys_get_temp_dir());
        $pdf = new Dompdf($options);
        // Browser-rendered derivative of the supplied SVG: php-svg-lib cannot
        // preserve its percentage dimensions and typography. No source SVG is edited.
        $brand=dirname(__DIR__,2).'/public/assets/brand/';
        $provenance=json_decode(file_get_contents($brand.'logo-h-pdf.json'),true,8,JSON_THROW_ON_ERROR);
        if (!hash_equals($provenance['source_sha256'],hash_file('sha256',$brand.'logo-h.svg'))) { throw new \RuntimeException('PDF brand derivative needs regeneration.'); }
        $png=file_get_contents($brand.'logo-h-pdf.png');
        if (!hash_equals($provenance['derivative_sha256'],hash('sha256',$png))) { throw new \RuntimeException('PDF brand derivative checksum mismatch.'); }
        $logo = 'data:image/png;base64,'.base64_encode($png);
        $writer = new Writer(new ImageRenderer(new RendererStyle(150),new SvgImageBackEnd()));
        $qr = 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($document['verification_url']));
        ob_start();
        try { require dirname(__DIR__,2).'/templates/certificate-pdf.php'; $html = ob_get_contents(); }
        finally { ob_end_clean(); }
        $pdf->loadHtml($html,'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
