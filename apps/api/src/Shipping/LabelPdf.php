<?php
declare(strict_types=1);
namespace Zpx\Shipping;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Common\ErrorCorrectionLevel;

final class LabelPdf
{
    public static function render(array $shipment,string $si,string $payload): string
    {
        $pdf=new \FPDF('P','mm',[101.6,152.4]);
        $pdf->SetAutoPageBreak(false); $pdf->SetMargins(7,7,7); $pdf->AddPage();
        $pdf->SetTitle('ZPX development shipping label'); $pdf->SetCreator('ZPX Delivery');
        $text=static function (string $text): string { return iconv('UTF-8','windows-1252//TRANSLIT',$text) ?: 'ZPX'; };
        $pdf->SetFont('Helvetica','B',16); $pdf->Cell(0,8,'ZipcodeXpress',0,1);
        $pdf->SetFont('Helvetica','B',9); $pdf->Cell(0,6,'TEST ONLY - NOT VALID FOR DROP-OFF',0,1);
        $pdf->Line(7,23,94,23); $pdf->SetY(26);
        $pdf->SetFont('Helvetica','',8); $pdf->Cell(0,5,'DESTINATION',0,1);
        $pdf->SetFont('Helvetica','B',15); $pdf->MultiCell(0,7,$text(substr($shipment['destination_name'],0,45)));
        $pdf->SetFont('Helvetica','',8); $pdf->MultiCell(0,5,'FROM: '.$text(substr($shipment['origin_name'],0,45)));
        $pdf->SetFont('Helvetica','B',8); $pdf->SetY(54); $pdf->Cell(0,5,$si,0,1);
        $matrix=Encoder::encode($payload,ErrorCorrectionLevel::M(),'UTF-8')->getMatrix();
        $n=$matrix->getWidth(); $module=48/($n+8); $x=(101.6-48)/2+4*$module; $y=64+4*$module;
        // Four white modules on every edge; vector modules preserve print resolution.
        $pdf->SetFillColor(0);
        for ($row=0;$row<$n;$row++) { for ($col=0;$col<$n;$col++) {
            if ($matrix->get($col,$row)===1) { $pdf->Rect($x+$col*$module,$y+$row*$module,$module,$module,'F'); }
        } }
        $pdf->SetY(115);$pdf->SetFont('Helvetica','',8);
        $pdf->Cell(0,5,'Parcel '.$shipment['package_id'].' | 1/1 | STANDARD | '.gmdate('Y-m-d'),0,1);
        $pdf->Cell(0,5,$shipment['width_mm'].' x '.$shipment['height_mm'].' x '.$shipment['depth_mm'].' mm | '.$shipment['weight_g'].' g',0,1);
        $pdf->SetFont('Helvetica','B',8);$pdf->Cell(0,6,'Attach flat. Keep the QR code visible.',0,1);
        $pdf->SetFont('Helvetica','',7);$pdf->MultiCell(0,4,'Print at 100% / actual size. This identifies a test parcel only. It is not a pickup code or permission to open a locker.');
        return $pdf->Output('S');
    }
}
