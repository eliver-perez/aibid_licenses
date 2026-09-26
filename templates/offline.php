<?php use Aibid\Http\View as V; ?>
<div class="page-heading"><div><span class="eyebrow">OPERACIÓN SIN CONEXIÓN</span><h1>Solicitudes offline</h1><p>Importa la solicitud del equipo, revisa sus derechos y entrega el archivo autorizado.</p></div></div>
<?php if ($actor->canWrite()): ?>
<section class="panel form-panel mb-4">
    <h2>Importar .licreq</h2><p class="text-secondary">Importar verifica la firma y deja la solicitud pendiente de una decisión. No activa ni renueva la licencia.</p>
    <form method="post" action="/admin/offline/import" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-3">
        <?php V::fields($csrf); ?><input type="hidden" name="MAX_FILE_SIZE" value="65536">
        <div class="flex-grow-1"><label class="form-label" for="request_file">Archivo de solicitud <span class="optional">máximo 64 KiB</span></label><input class="form-control" type="file" id="request_file" name="request_file" accept=".licreq,application/json" required></div>
        <button class="btn btn-primary" type="submit">Verificar e importar</button>
    </form>
</section>
<?php endif; ?>
<section class="panel">
    <form class="filter-bar" method="get">
        <input class="form-control" name="q" value="<?= V::escape($filters['q']) ?>" placeholder="Cliente o ID de solicitud" aria-label="Buscar solicitud">
        <select class="form-select" name="status" aria-label="Estado"><option value="">Todos los estados</option><?php foreach (['pending'=>'Pendiente','approved'=>'Aprobada','rejected'=>'Rechazada'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
        <select class="form-select" name="product_id" aria-label="Producto"><option value="">Todos los productos</option><?php foreach ($products as $product): ?><option value="<?= V::escape($product['product_id']) ?>" <?= $filters['product_id']===$product['product_id']?'selected':'' ?>><?= V::escape($product['display_name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-primary">Filtrar</button>
    </form>
    <div class="table-responsive"><table class="table"><thead><tr><th>Solicitud</th><th>Acción</th><th>Estado</th><th>Importada</th></tr></thead><tbody>
        <?php foreach ($listing['rows'] as $row): ?><tr>
            <td><a href="/admin/offline/<?= V::escape($row['id_text']) ?>" class="text-link mono"><?= V::escape($row['request_text']) ?></a><small class="cell-sub"><?= V::escape($row['customer_name'] ?? $row['product_id']) ?></small></td>
            <td><?= V::escape(['activate'=>'Activación','renew'=>'Renovación','deactivate'=>'Desactivación'][$row['action']]) ?></td>
            <td><span class="tag <?= $row['status']==='approved'?'tag-green':($row['status']==='rejected'?'tag-red':'') ?>"><?= V::escape(['pending'=>'Pendiente','approved'=>'Aprobada','rejected'=>'Rechazada'][$row['status']]) ?></span></td>
            <td><?= V::escape(V::date($row['imported_at'])) ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$listing['rows']): ?><tr><td colspan="4" class="text-secondary">No hay solicitudes con estos filtros.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php require __DIR__.'/pagination.php'; ?>
</section>
