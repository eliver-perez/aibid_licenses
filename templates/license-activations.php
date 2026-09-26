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
                <td><?php if ($actor->canWrite()): ?><a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>/revisions/<?= V::escape($revision['id_text']) ?>/download">Descargar .lic</a><?php endif; ?></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="small text-secondary mt-3 mb-0">Se muestran las últimas 50 revisiones. Cada archivo corresponde a su instalación original; descargarlo no crea otra activación.</p>
    <?php endif; ?>
</section>
