<?php use Aibid\Http\View as V; ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= V::escape($title) ?> · AIBID Licencias</title>
    <link rel="icon" type="image/svg+xml" href="/assets/brand/logo-v.svg">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js" defer></script>
    <script src="/assets/app.js" defer></script>
</head>
<body class="<?= $actor ? 'admin-body' : 'auth-body' ?>">
<?php if ($actor): ?>
    <a class="skip-link" href="#main-content">Saltar al contenido</a>
    <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
        <a class="sidebar-brand" href="/admin"><img src="/assets/brand/logo-dark-v.svg" alt="AIBID · Aplicación de Indexación de Bibliotecas Digitales"></a>
        <div class="sidebar-caption">ADMINISTRACIÓN DE LICENCIAS</div>
        <nav class="sidebar-nav">
        <?php foreach (['/admin' => ['Resumen','◫'], '/admin/licenses' => ['Licencias','⌘'], '/admin/offline' => ['Solicitudes offline','⇄'], '/admin/customers' => ['Clientes','◎'], '/admin/products' => ['Productos','◇'], '/admin/audit' => ['Auditoría','≡']] as $href => [$label,$symbol]): $active = $href === '/admin' ? $path === $href : str_starts_with($path, $href); ?>
            <a href="<?= $href ?>" class="nav-item <?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>><span class="nav-symbol" aria-hidden="true"><?= $symbol ?></span><?= $label ?></a>
        <?php endforeach; ?>
        <?php if ($actor->role === 'superadmin'): ?><div class="sidebar-caption mt-4">CONFIGURACIÓN</div><a href="/admin/users" class="nav-item <?= str_starts_with($path, '/admin/users') ? 'active' : '' ?>"><span class="nav-symbol" aria-hidden="true">⊞</span>Administradores</a><?php endif; ?>
        <a href="/admin/security" class="nav-item <?= $path === '/admin/security' ? 'active' : '' ?>"><span class="nav-symbol" aria-hidden="true">⊙</span>Mi seguridad</a>
        </nav>
        <div class="sidebar-bottom"><span class="status-dot"></span> Panel privado <div>Control y trazabilidad en un solo lugar.</div></div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <div class="d-flex align-items-center gap-3"><button class="menu-toggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Abrir navegación">☰</button><span class="breadcrumb-label">Licencias <span>/</span> <?= V::escape($title) ?></span></div>
            <div class="account-area"><span class="avatar"><?= V::escape(mb_strtoupper(mb_substr($actor->name, 0, 1))) ?></span><div class="account-name"><?= V::escape($actor->name) ?><small><?= V::escape(V::role($actor->role)) ?></small></div><form method="post" action="/logout"><?php V::fields($csrf); ?><button class="btn btn-sm btn-outline-secondary" type="submit">Salir</button></form></div>
        </header>
        <main id="main-content" class="main-content"><?= $content ?></main>
        <footer class="workspace-footer">AIBID · Administración de licencias <span>Fechas expresadas en UTC</span></footer>
    </div>
<?php else: ?>
    <main class="auth-shell">
        <section class="auth-brand-panel"><img src="/assets/brand/logo-dark-h.svg" class="auth-logo" alt="AIBID · Aplicación de Indexación de Bibliotecas Digitales"><div class="auth-intro"><span class="eyebrow light">CONTROL DE LICENCIAS</span><h1>Cada licencia.<br>Cada derecho.<br>Todo en orden.</h1><p>Administra clientes, productos y vigencias desde un espacio privado y seguro.</p><div class="auth-proof"><span>01</span><div>Una instalación por licencia<small>Derechos claros, historial completo.</small></div></div></div><footer>AIBID <span>Tu biblioteca digital, ordenada y al alcance.</span></footer></section>
        <section class="auth-form-panel"><a href="/login" class="mobile-brand"><img src="/assets/brand/logo-h.svg" alt="AIBID"></a><div class="auth-form-wrap"><?= $content ?></div><p class="auth-footnote">Acceso exclusivo para personal autorizado.</p></section>
    </main>
<?php endif; ?>
</body>
</html>
