<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require __DIR__.'/../vendor/autoload.php';

$root = realpath(__DIR__.'/..');

if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

$htmlPath = $root.'/docs/UNDP_Deployment_Report.html';
$pdfPath = $root.'/docs/UNDP_Deployment_Report.pdf';

$html = file_get_contents($htmlPath);

if ($html === false) {
    fwrite(STDERR, "Unable to read HTML source at {$htmlPath}.\n");
    exit(1);
}

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'Helvetica');
$options->setChroot($root);

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();

$bytes = file_put_contents($pdfPath, $dompdf->output());

if ($bytes === false) {
    fwrite(STDERR, "Unable to write PDF output to {$pdfPath}.\n");
    exit(1);
}

fwrite(STDOUT, "Wrote {$pdfPath}\n");
