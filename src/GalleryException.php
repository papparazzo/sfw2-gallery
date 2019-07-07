<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2018  Stefan Paproth
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

namespace SFW2\Gallery;

use SFW2\Core\SFW2Exception;

class GalleryException extends SFW2Exception {
    const INVALID_PATH                      = 2;
    const INVALID_IMAGE                     = 9;
    const COULD_NOT_DELETE_PREVIEW_IMAGE    = 3;
    const PREVIEW_FILE_DOES_NOT_EXIST       = 4;
    const COULD_NOT_CREATE_GALLERY_PATH     = 5;
    const COULD_NOT_CHANGE_PREVIEW_IMAGE    = 1;
    const COULD_NOT_INSERT_INTO_MEDIA_TABLE = 6;
    const INSERTATION_OF_GALLERY_FAILED     = 7;
    const NO_GALLERY_FETCHED                = 8;
    const UPDATING_GALLERY_FAILED           = 10;
}
