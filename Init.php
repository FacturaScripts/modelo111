<?php
/**
 * This file is part of Modelo111 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Modelo111;

use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;

/**
 * Inicialización del plugin Modelo111: registra los endpoints de la API de sus dos modelos, el 111 y el 190.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Init extends InitClass
{
    /**
     * Registra los endpoints de solo lectura GET /api/3/modelo111 y GET /api/3/modelo190 y los añade al
     * listado de recursos de la API.
     */
    public function init(): void
    {
        Kernel::addRoute('/api/3/modelo111', 'ApiModelo111', -1);
        ApiRoot::addCustomResource('modelo111');

        Kernel::addRoute('/api/3/modelo190', 'ApiModelo190', -1);
        ApiRoot::addCustomResource('modelo190');
    }

    /**
     * Se ejecuta al desinstalar el plugin. No hay nada que limpiar.
     */
    public function uninstall(): void
    {
    }

    /**
     * Se ejecuta al instalar o actualizar el plugin. No hay nada que preparar.
     */
    public function update(): void
    {
    }
}
