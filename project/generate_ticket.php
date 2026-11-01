<?php
// Builds the PDF ticket from the saved booking record (never from URL parameters).
// Access needs the booking id plus its signed token, so tickets cannot be forged
// and one customer cannot download another customer's ticket by guessing ids.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';
require_once __DIR__ . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId < 1 || !ticket_token_valid($bookingId, $_GET['t'] ?? null)) {
    http_response_code(404);
    exit('Ticket not found.');
}
$b = find_booking(db(), $bookingId);
if ($b === null || !booking_is_confirmed($b)) {   // unpaid / cancelled reservations get no ticket
    http_response_code(404);
    exit('Ticket not found.');
}

$html = ticket_html($b);

$options = new Options();
$options->set('isRemoteEnabled', false);      // never fetch external URLs while rendering
$options->set('chroot', __DIR__ . '/dompdf'); // never read arbitrary local files
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// File name built only from the validated booking id.
$dompdf->stream('Parking_Ticket_' . (int) $b['id'] . '.pdf', ['Attachment' => 1]);
