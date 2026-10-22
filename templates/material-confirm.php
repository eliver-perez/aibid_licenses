<?php use Aibid\Http\View as V; ?>
<div class="page-heading"><div><h1><?= V::escape($title) ?></h1><p>Confirma tu identidad para preparar esta descarga sensible. Se registrará en auditoría.</p></div></div>
<form class="panel form-panel stack-form" method="post" action="<?= V::escape($path) ?>"><?php V::fields($csrf); ?><?php require __DIR__.'/reauth-fields.php'; ?><button class="btn btn-primary">Confirmar y descargar</button></form>
