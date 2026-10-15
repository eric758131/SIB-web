<?php

namespace App\Support;

use Spatie\Permission\Models\Role;

/**
 * Catalog of admin panel permissions (spatie/laravel-permission) and the default roles.
 * The built-in roles use the same names as the WordPress roles so both stay in sync.
 */
class PanelPermissions
{
    public const GUARD = 'web';

    /** name => [group, label] */
    public const PERMISSIONS = [
        'publicaciones.crear' => ['Publicaciones', 'Crear y editar sus propias publicaciones'],
        'publicaciones.ver_todas' => ['Publicaciones', 'Ver y editar publicaciones de otros usuarios'],
        'publicaciones.publicar' => ['Publicaciones', 'Publicar sin revisión previa'],
        'publicaciones.eliminar' => ['Publicaciones', 'Mover a la papelera y restaurar'],
        'publicaciones.eliminar_definitivo' => ['Publicaciones', 'Eliminar definitivamente'],
        'categorias.gestionar' => ['Contenido', 'Gestionar categorías'],
        'medios.subir' => ['Contenido', 'Subir imágenes y documentos'],
        'mensajes.gestionar' => ['Contacto', 'Leer y gestionar mensajes de contacto'],
        'usuarios.gestionar' => ['Administración', 'Gestionar usuarios'],
        'roles.gestionar' => ['Administración', 'Gestionar roles y permisos'],
        'actividad.ver' => ['Administración', 'Ver el historial de actividad'],
    ];

    /** Built-in roles: name => [label, permissions] ("*" = all). */
    public const DEFAULT_ROLES = [
        'administrator' => ['Administrador', ['*']],
        'editor' => ['Editor', [
            'publicaciones.crear', 'publicaciones.ver_todas', 'publicaciones.publicar', 'publicaciones.eliminar',
            'categorias.gestionar', 'medios.subir', 'mensajes.gestionar', 'actividad.ver',
        ]],
        'author' => ['Autor', ['publicaciones.crear', 'medios.subir']],
    ];

    public static function label(?string $role): string
    {
        if (! $role) {
            return 'Sin acceso';
        }

        return self::DEFAULT_ROLES[$role][0] ?? $role;
    }

    public static function isSystemRole(string $role): bool
    {
        return array_key_exists($role, self::DEFAULT_ROLES);
    }

    /**
     * WordPress role to store in usermeta for a panel role, so WordPress grants similar rights.
     */
    public static function wordpressRoleFor(?Role $role): ?string
    {
        if (! $role) {
            return null;
        }
        if (self::isSystemRole($role->name)) {
            return $role->name;
        }

        return match (true) {
            $role->hasPermissionTo('usuarios.gestionar') => 'administrator',
            $role->hasPermissionTo('publicaciones.ver_todas') => 'editor',
            $role->hasPermissionTo('publicaciones.crear') => 'author',
            default => 'subscriber',
        };
    }
}
