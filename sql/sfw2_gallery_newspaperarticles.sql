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

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}_gallery_newspaperarticles` (
  `Id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `PathId` INT(10) UNSIGNED NOT NULL,
  `Title` VARCHAR(100) COLLATE utf8_unicode_ci NOT NULL,
  `Date` DATE DEFAULT NULL,
  `Source` VARCHAR(20) COLLATE utf8_unicode_ci NOT NULL,
  `UserId` INT(11) NOT NULL,
  `FileName` VARCHAR(256) COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
