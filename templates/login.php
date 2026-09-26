<?php use Aibid\Http\View as V; ?>
<span class="eyebrow">PORTAL ADMINISTRATIVO</span>
<h1 class="auth-title">Bienvenido de nuevo</h1>
<p class="text-secondary mb-4">Ingresa a tu cuenta para administrar las licencias.</p>
<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= V::escape($error) ?></div><?php endif; ?>
<form method="post" action="/login" class="stack-form">
    <?php V::fields($csrf); ?>
    <div><label for="login" class="form-label">Correo electrónico</label><input id="login" name="login" type="email" autocomplete="username" class="form-control form-control-lg" maxlength="190" required autofocus></div>
    <div><label for="password" class="form-label">Contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" class="form-control form-control-lg" maxlength="1024" required></div>
    <button type="submit" class="btn btn-primary btn-lg w-100">Continuar <span aria-hidden="true">→</span></button>
</form>
<div class="auth-note"><span class="secure-mark" aria-hidden="true">✓</span><p>Tu cuenta está protegida con verificación en dos pasos.<small>Si perdiste el acceso, contacta al superadministrador.</small></p></div>
