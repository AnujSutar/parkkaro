<?php
/** Run: php tests/unit/ticket_test.php   (renders a real PDF with the bundled dompdf; no database) */
declare(strict_types=1);

require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/booking.php';
require __DIR__ . '/../../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$row = ['id' => 5, 'location_name' => 'Kolhapur Market Parking', 'admin_zone' => 'Zone I',
        'name' => '<img src="http://127.0.0.1:1/x.png"><script>alert(1)</script>', 'email' => 'a@example.com',
        'car_number' => 'MH09AB1234', 'hours' => 3, 'cost' => 60];

T::group('Ticket HTML');
$html = ticket_html($row);
T::ok(!str_contains($html, '<script>') && !str_contains($html, '<img'), 'markup in the name is escaped');
T::ok(str_contains($html, '&lt;script&gt;'), 'escaped text is visible instead');
T::ok(str_contains($html, 'Booking No:</strong> 5'), 'booking number is shown');

T::group('PDF rendering with dompdf');
$o = new Options();
$o->set('isRemoteEnabled', false);
$o->set('chroot', __DIR__ . '/../../dompdf');
$d = new Dompdf($o);
$d->loadHtml($html, 'UTF-8');
$d->setPaper('A4', 'portrait');
$d->render();
$pdf = $d->output();
T::ok(str_starts_with($pdf, '%PDF-'), 'output is a PDF');
T::ok(strlen($pdf) > 1000, 'PDF has content (' . strlen($pdf) . ' bytes)');
T::finish();
