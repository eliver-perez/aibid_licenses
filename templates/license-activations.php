<?php use Aibid\Http\View as V; ?>
<section class="panel form-panel mt-4">
    <h2>Instalaciones y revisiones firmadas</h2>
    <?php if (!$license['activations']): ?>
        <p class="text-secondary mb-0">Esta licencia todavía no tiene una instalación activada.</p>
    <?php else: ?>
        <p class="text-secondary small">Una instalación activa como máximo. Las instalaciones retiradas conservan su historial.</p>
        <?php foreach ($license['activations'] as $activation): ?>
            <div class="note-box mt-3">
                <strong><?= V::escape(['active'=>'Activa','deactivated'=>'Desactivada','revoked'=>'Revocada'][$activation['state']]) ?></strong>
                <div class="mono mt-2"><?= V::escape($activation['installation_text']) ?></div>
                <small>Activada: <?= V::escape(V::date($activation['activated_at'])) ?><?php if ($activation['ended_at']): ?> · Retirada: <?= V::escape(V::date($activation['ended_at'])) ?><?php endif; ?></small>
            </div>
        <?php endforeach; ?>
        <div class="table-responsive mt-4"><table class="table"><thead><tr><th>Revisión</th><th>Estado firmado</th><th>Fecha</th><th><span class="visually-hidden">Descarga</span></th></tr></thead><tbody>
            <?php foreach ($license['revisions'] as $revision): ?><tr>
                <td><?= (int)$revision['license_revision'] ?><small class="cell-sub">Clave: <?= V::escape($revision['kid']) ?></small></td>
                <td><?= $revision['license_status']==='active'?'Activa':'Revocada' ?></td>
                <td><?= V::escape(V::date($revision['issued_at'])) ?></td>
                <td><?php if ($actor->allows('offline.download')): ?><a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>/revisions/<?= V::escape($revision['id_text']) ?>/download">Descargar .lic</a><?php endif; ?></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="small text-secondary mt-3 mb-0">Página <?= (int)$license['revision_page'] ?> de <?= (int)$license['revision_pages'] ?> · 50 revisiones por página. Cada archivo conserva su instalación original; descargarlo no crea otra activación.</p>
        <nav class="d-flex gap-3 mt-3" aria-label="Revisiones firmadas">
        <?php if ($license['revision_page']>1): ?><a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>?revision_page=<?= (int)$license['revision_page']-1 ?>">← Revisiones más recientes</a><?php endif; ?>
        <?php if ($license['revision_page']<$license['revision_pages']): ?><a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>?revision_page=<?= (int)$license['revision_page']+1 ?>">Revisiones anteriores →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
