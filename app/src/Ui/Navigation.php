<?php
declare(strict_types=1);

namespace Admin\Ui;

/** Presentation-only navigation over existing application routes. */
final class Navigation
{
    public static function sections(): array
    {
        return [
            'machines' => ['label' => 'Machines', 'icon' => 'hard-drive', 'href' => '/machines', 'children' => []],
            'presets' => ['label' => 'Modèles', 'icon' => 'package', 'href' => '/presets', 'children' => []],
            'activity' => ['label' => 'Activité', 'icon' => 'zap', 'href' => '/activity', 'children' => [
                ['/activity', 'Toute l’activité'], ['/installations', 'Installations'],
            ]],
            'settings' => ['label' => 'Appliance', 'icon' => 'settings', 'href' => '/settings', 'children' => [
                ['/settings', 'Vue d’ensemble'], ['/settings/images', 'Images système'],
                ['/settings/ansible', 'Ansible'], ['/settings/https', 'Accès HTTPS'],
                ['/settings/ipxe', 'Démarrage iPXE'],
            ]],
        ];
    }

    public static function section(string $path): string
    {
        if (preg_match('~^/(?:activity|installations|tasks|jobs|wipes|disktests|init)(?:/|$)~', $path)) return 'activity';
        if (preg_match('~^/settings(?:/|$)~', $path)) return 'settings';
        if (preg_match('~^/presets(?:/|$)~', $path)) return 'presets';
        return 'machines';
    }

    public static function current(string $path, string $href): bool
    {
        if ($href === '/machines') return $path === '/' || $path === $href;
        if (in_array($href, ['/settings', '/activity'], true)) return $path === $href;
        return $path === $href || str_starts_with($path, $href . '/');
    }

    public static function context(string $path, string $title, array $data = []): array
    {
        $key = self::section($path);
        $section = self::sections()[$key];
        $crumbs = [['label' => $section['label'], 'href' => $section['href']]];
        if ($path === '/' || $path === $section['href']) return ['section' => $key, 'crumbs' => $crumbs];

        if (preg_match('~^/machines/(\d+)(/install)?$~', $path, $matches)) {
            $machine = $data['machine'] ?? [];
            $label = trim((string)($machine['hostname'] ?? '')) ?: (string)($machine['mac'] ?? 'Machine #' . $matches[1]);
            $crumbs[] = ['label' => $label, 'href' => '/machines/' . $matches[1]];
            if (isset($matches[2])) $crumbs[] = ['label' => 'Préparer l’installation', 'href' => $path];
        } elseif (preg_match('~^/presets/(\d+)$~', $path)) {
            $crumbs[] = ['label' => (string)($data['preset']['name'] ?? 'Éditer le modèle'), 'href' => $path];
        } else {
            $labels = [
                '/machines/new' => 'Ajouter une machine', '/presets/new' => 'Nouveau modèle',
                '/presets/import' => 'Importer', '/presets/duplicate' => 'Dupliquer',
                '/installations' => 'Installations', '/settings/images' => 'Images système',
                '/settings/images/add' => 'Ajouter une image', '/settings/ansible' => 'Ansible',
                '/settings/https' => 'Accès HTTPS', '/settings/ipxe' => 'Démarrage iPXE',
            ];
            $crumbs[] = ['label' => $labels[$path] ?? $title, 'href' => $path];
        }
        return ['section' => $key, 'crumbs' => $crumbs];
    }
}
