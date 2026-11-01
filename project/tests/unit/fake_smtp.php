<?php
// TEST ONLY: a tiny one-connection SMTP server. php fake_smtp.php <port> <transcript-file> [reject-auth]
$port = (int) $argv[1];
$out = $argv[2];
$rejectAuth = in_array('reject-auth', $argv, true);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
fwrite(STDOUT, "ready\n");
$c = stream_socket_accept($srv, 15);
$log = '';
$w = static function (string $s) use ($c, &$log) { fwrite($c, $s . "\r\n"); $log .= 'S: ' . $s . "\n"; };
$w('220 fake ESMTP');
$data = false; $auth = 0;
while (($l = fgets($c)) !== false) {
    $l = rtrim($l, "\r\n");
    $log .= 'C: ' . $l . "\n";
    if ($data) { if ($l === '.') { $data = false; $w('250 queued'); } continue; }
    $u = strtoupper($l);
    if (str_starts_with($u, 'EHLO')) { $w('250-fake'); $w('250 AUTH LOGIN'); }
    elseif ($u === 'AUTH LOGIN') { $w('334 VXNlcm5hbWU6'); $auth = 1; }
    elseif ($auth === 1) { $w('334 UGFzc3dvcmQ6'); $auth = 2; }
    elseif ($auth === 2) { $auth = 0; $rejectAuth ? $w('535 5.7.8 bad credentials') : $w('235 ok'); if ($rejectAuth) { break; } }
    elseif (str_starts_with($u, 'MAIL FROM')) { $w('250 ok'); }
    elseif (str_starts_with($u, 'RCPT TO')) { $w('250 ok'); }
    elseif ($u === 'DATA') { $w('354 go'); $data = true; }
    elseif ($u === 'QUIT') { $w('221 bye'); break; }
    else { $w('500 ?'); }
}
file_put_contents($out, $log);
