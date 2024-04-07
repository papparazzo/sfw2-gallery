<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2023  Stefan Paproth
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

declare(strict_types=1);

namespace SFW2\Gallery\Controller;

use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpNotFound;
use SFW2\Core\Permission\AccessType;
use SFW2\Core\Permission\PermissionInterface;
use SFW2\Core\Utils\DateTimeHelper;
use SFW2\Database\DatabaseException;
use SFW2\Database\DatabaseInterface;
use SFW2\Gallery\Helper\ImageHelperTrait;
use SFW2\Routing\AbstractController;
use SFW2\Routing\HelperTraits\getRoutingDataTrait;
use SFW2\Routing\ResponseEngine;

final class Video extends AbstractController
{
   use getRoutingDataTrait;
   use ImageHelperTrait;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly DateTimeHelper      $dateTimeHelper,
        private readonly PermissionInterface $permission,
        private readonly string $title = "Videos",
        private readonly string $description = ''
    )
    {
    }

    /**
     * @throws HttpNotFound
     */
    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        return $responseEngine->render($request, $this->getData(), "SFW2\\Gallery\\videos");
    }

    protected function getData(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'entries' => [
            ]
        ];
    }
}