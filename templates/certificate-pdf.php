<?php
use Aibid\Http\View as V;
$d = $document;
$licensing = $d['type']==='licensing';
$title = $licensing ? 'Constancia de licenciamiento' : 'Constancia de entrega para activación';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title><?= $title ?></title>
<style>
@page { margin: 30pt 38pt; } body { font-family: 'DejaVu Sans'; font-size: 9pt; color: #17243a; line-height: 1.3; }
.logo { width: 360pt; height: 65pt; } h1 { font-size: 17pt; margin: 8pt 0 4pt; color: #142641; } h2 { font-size: 10pt; margin-top: 10pt; }
.delivery .logo { width: 300pt; height: 54.167pt; }
.delivery th, .delivery td { padding: 2.5pt 3pt; }
.muted { color: #526276; } .folio { background: #edf2f8; padding: 9pt; font-size: 8pt; }
table { width: 100%; border-collapse: collapse; margin-top: 6pt; } th,td { border-bottom: 1pt solid #e2e8f0; padding: 4pt 3pt; text-align: left; vertical-align: top; } th { width: 34%; font-weight: bold; }
.code { font-family: 'DejaVu Sans Mono'; font-size: 18pt; letter-spacing: 1pt; background: #edf2f8; padding: 10pt; }
.hash { font-family: 'DejaVu Sans Mono'; font-size: 7pt; word-wrap: break-word; }
.notice { border-left: 3pt solid #385782; padding: 6pt 9pt; background: #f6f8fb; } .verification { margin-top: 10pt; page-break-inside: avoid; } .qr { width: 72pt; height: 72pt; float: right; }
.footer { margin-top: 10pt; border-top: 1pt solid #cad5e2; padding-top: 6pt; font-size: 7pt; }
</style></head><body class="<?= $licensing?'licensing':'delivery' ?>">
<img class="logo" src="<?= V::escape($logo) ?>" alt="AIDEX">
<h1><?= $title ?></h1><?php if ($licensing): ?><p class="muted">AIDEX — Aplicación de Indexación Digital de Expedientes</p><?php endif; ?>
<div class="folio"><strong>Folio: <?= V::escape($d['folio']) ?></strong> · Revisión <?= (int)$d['revision'] ?><br>Documento generado: <?= V::escape(V::date($d['generated_at'])) ?></div>
<table>
<tr><th>Titular / licenciatario</th><td><?= V::escape($d['holder']) ?></td></tr>
<?php if ($d['legal_name']): ?><tr><th>Razón social</th><td><?= V::escape($d['legal_name']) ?></td></tr><?php endif; ?>
<?php if ($d['tax_id']): ?><tr><th>RFC</th><td><?= V::escape($d['tax_id']) ?></td></tr><?php endif; ?>
<tr><th>Producto</th><td><?= V::escape($d['product']) ?></td></tr>
<tr><th>Modalidad</th><td><?= $d['license_type']==='perpetual'?'Perpetua · Uso sin vencimiento':'Suscripción' ?></td></tr>
<tr><th>Emisión de licencia</th><td><?= V::escape(V::date($d['issued_at'])) ?></td></tr>
<tr><th>Vencimiento</th><td><?= $d['expires_at']===null?'Sin vencimiento':V::escape(V::date($d['expires_at'])) ?></td></tr>
<tr><th>Instalaciones autorizadas</th><td><?= (int)$d['limits']['max_installations'] ?></td></tr>
<tr><th>Usuarios permitidos</th><td><?= $d['limits']['max_users']===null?'Sin límite':(int)$d['limits']['max_users'] ?></td></tr>
<tr><th>Módulos autorizados</th><td><?= V::escape(implode(', ',$d['features']) ?: 'Ninguno') ?></td></tr>
<?php if ($d['reference']!==''): ?><tr><th>Referencia comercial</th><td><?= V::escape($d['reference']) ?></td></tr><?php endif; ?>
</table>
<?php if ($licensing): ?>
<h2>Alcance administrativo</h2><p class="notice">Esta constancia acredita la emisión de los derechos indicados. No contiene credenciales ni activa el programa. No es un comprobante de pago y no sustituye el contrato comercial.</p>
<?php else: ?>
<h2>Entrega al responsable técnico autorizado</h2>
<?php if ($d['activation_method']==='online'): ?>
<p>Método: activación online con código y conexión al servidor de licencias.</p>
<p class="code"><?= V::escape($d['activation_code']) ?></p>
<p>Introduce el código en AIDEX, con o sin guiones. Completa la activación en la instalación autorizada. La entrega del código no confirma que la instalación esté activada. El código no sustituye el archivo firmado de una activación totalmente offline.</p>
<?php else: ?>
<p>Método: archivo firmado para activación offline. Instalación autorizada:<br><strong><?= V::escape($d['installation']) ?></strong></p>
<p>Archivo: <?= V::escape($d['offline_filename']) ?><br>Revisión técnica: <?= (int)$d['license_revision'] ?> · Estado del archivo firmado: <?= V::escape($d['artifact_status']) ?></p>
<p class="hash">SHA-256 del archivo:<br><?= V::escape($d['offline_sha256']) ?></p>
<p>Importa el .lic en el equipo que creó la solicitud .licreq. Verifica firma Ed25519, producto, identidad y revisión. SHA-256 identifica el archivo; no sustituye su firma.</p>
<?php endif; ?>
<p class="notice">CONFIDENCIAL. Custodia el código o archivo y entrégalo solo al responsable autorizado por un canal seguro. Esta entrega histórica no acredita vigencia actual.</p>
<?php endif; ?>
<div class="verification"><img class="qr" src="<?= V::escape($qr) ?>" alt="Verificación de constancia"><h2>Verificación de autenticidad</h2><p>Verifica folio, revisión, estado y SHA-256 del PDF recibido en el sitio oficial del QR. Un QR copiado no valida otro contenido.</p><p style="font-size:7pt;word-wrap:break-word"><?= V::escape($d['verification_url']) ?></p></div>
<div class="footer">AIDEX — Aplicación de Indexación Digital de Expedientes. Documento histórico: sus condiciones pueden cambiar. La autenticidad documental no acredita activación vigente. Zona horaria: <?= V::TIMEZONE ?>.</div>
</body></html>
