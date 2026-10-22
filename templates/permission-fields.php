<?php use Aibid\Http\View as V; ?>
<fieldset><legend class="small">Permisos sensibles adicionales del operador</legend><p class="small text-secondary">Consulta no recibe estos permisos. Superadministrador dispone de todos.</p>
<?php foreach (\Aibid\Application\Actor::PERMISSIONS as $permission=>$label): ?><label class="form-check small"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= V::escape($permission) ?>" <?= in_array($permission,$permissionValues,true)?'checked':'' ?>> <?= V::escape($label) ?></label><?php endforeach; ?></fieldset>
