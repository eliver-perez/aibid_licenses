<?php use Aibid\Http\View as V; ?>
<section class="panel form-panel mt-4"><h2>Solicitudes y recuperaciones offline</h2>
    <p><a class="text-link" href="/admin/offline">Importar o consultar una solicitud .licreq</a></p>
    <?php foreach ($license['offline'] as $request): ?><div class="note-box mt-2"><a class="text-link" href="/admin/offline/<?= V::escape($request['id_text']) ?>"><?= V::escape(['activate'=>'Activación','renew'=>'Renovación','deactivate'=>'Desactivación'][$request['action']]) ?> · <?= V::escape(['pending'=>'Pendiente','approved'=>'Aprobada','rejected'=>'Rechazada'][$request['status']]) ?></a><small class="cell-sub"><?= V::escape(V::date($request['imported_at'])) ?></small></div><?php endforeach; ?>
    <?php foreach ($license['transfers'] as $transfer): ?><div class="note-box mt-3"><strong>Recuperación autorizada</strong><p class="mt-2 mb-1"><?= V::escape($transfer['reason']) ?></p><small><?= V::escape($transfer['actor_name']) ?> · <?= V::escape(V::date($transfer['created_at'])) ?></small><div class="small mono mt-2">Origen: <?= V::escape($transfer['outgoing_text']) ?><br>Destino: <?= V::escape($transfer['incoming_text'] ?? 'Plaza liberada para una activación posterior') ?></div></div><?php endforeach; ?>
    <?php if (!$license['offline'] && !$license['transfers']): ?><p class="text-secondary small">Sin operaciones offline registradas.</p><?php endif; ?>
    <?php if ($actor->role==='superadmin' && $canChange): foreach ($license['activations'] as $activation): if ($activation['state']!=='active') continue; ?>
    <details class="mt-4"><summary>Recuperar plaza por equipo averiado</summary><p class="small text-secondary mt-3">Retira la activación actual y deja disponible la licencia para un nuevo equipo. Para transferir directamente, importa y revisa la solicitud del destino.</p>
        <form class="stack-form" method="post" action="/admin/licenses/<?= V::escape($licenseId) ?>/release">
            <?php V::fields($csrf); ?><input type="hidden" name="version" value="<?= (int)$license['row_version'] ?>"><input type="hidden" name="activation_id" value="<?= V::escape($activation['id_text']) ?>">
            <p class="mono small">Instalación: <?= V::escape($activation['installation_text']) ?></p>
            <?php require __DIR__.'/offline-confirm.php'; ?>
            <label>Motivo de recuperación<textarea class="form-control mt-2" name="reason" maxlength="500" rows="2" required></textarea></label><button class="btn btn-outline-danger">Retirar instalación y liberar plaza</button>
        </form>
    </details>
    <?php endforeach; endif; ?>
</section>
