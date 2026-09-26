<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class Capabilities
{
    public static function validate(array $enabled, array $catalog, array $dependencies): void
    {
        foreach ($enabled as $feature) {
            if (!is_string($feature) || !in_array($feature, $catalog, true)) {
                throw new Problem('Se seleccionó una capacidad ajena al producto.');
            }
        }
        foreach ($dependencies as $dependency) {
            if (in_array($dependency['capability_key'], $enabled, true) && !in_array($dependency['required_key'], $enabled, true)) {
                throw new Problem($dependency['capability_key'] . ' requiere ' . $dependency['required_key'] . '.');
            }
        }
    }

    public static function acyclic(array $dependencies): void
    {
        $graph = [];
        foreach ($dependencies as $edge) {
            $graph[$edge['capability_key']][] = $edge['required_key'];
        }
        $visiting = [];
        $visited = [];
        $visit = function (string $feature) use (&$visit, &$visiting, &$visited, $graph): void {
            if (isset($visiting[$feature])) {
                throw new Problem('Las dependencias no pueden formar un ciclo.');
            }
            if (isset($visited[$feature])) {
                return;
            }
            $visiting[$feature] = true;
            foreach ($graph[$feature] ?? [] as $required) {
                $visit($required);
            }
            unset($visiting[$feature]);
            $visited[$feature] = true;
        };
        foreach (array_keys($graph) as $feature) {
            $visit($feature);
        }
    }
}
