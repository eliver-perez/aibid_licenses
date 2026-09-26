<?php use Aibid\Http\View as V; ?>
<span class="eyebrow">VERIFICACIÓN EN DOS PASOS</span><h1 class="auth-title"><?= V::escape($title) ?></h1>
<?php if ($secret !== null): ?>
<p class="text-secondary">Escanea este código con tu aplicación de autenticación. Después introduce el código de seis dígitos.</p>
<div class="qr-wrap"><img src="<?= V::escape($qr) ?>" width="240" height="240" alt="Código QR para configurar tu segundo factor"></div>
<details class="mb-3"><summary>Configurar con clave manual</summary><code class="secret-code" data-testid="totp-secret"><?= V::escape($secret) ?></code></details>
<?php else: ?><p class="text-secondary">Introduce un código de tu aplicación de autenticación o uno de tus códigos de recuperación.</p><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= V::escape($error) ?></div><?php endif; ?>
<form method="post" action="/mfa" class="stack-form"><?php V::fields($csrf); ?><div><label class="form-label" for="code">Código de verificación</label><input class="form-control form-control-lg code-input" id="code" name="code" autocomplete="one-time-code" maxlength="64" required autofocus></div><button class="btn btn-primary btn-lg" type="submit">Verificar e ingresar</button></form>
<form method="post" action="/logout" class="mt-3"><?php V::fields($csrf); ?><button class="btn btn-link px-0" type="submit">Usar otra cuenta</button></form>
