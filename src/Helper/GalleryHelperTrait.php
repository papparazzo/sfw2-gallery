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

namespace SFW2\Gallery\Helper;

use SFW2\Gallery\GalleryException;

trait GalleryHelperTrait {

    protected function addFile(string $folder, int $previewSize) {
        if(!isset($_POST['file'])) {
            throw new GalleryException("file not set", GalleryException::UPDATING_GALLERY_FAILED);
        }

        $thumbFolder = $folder . DIRECTORY_SEPARATOR . 'thumb' . DIRECTORY_SEPARATOR;
        $highFolder = $folder . DIRECTORY_SEPARATOR . 'high' . DIRECTORY_SEPARATOR;

        if(!is_dir($thumbFolder) && !mkdir($thumbFolder, 0777, true)) {
            throw new GalleryException("could not create destination-folder <$thumbFolder>", GalleryException::UPDATING_GALLERY_FAILED);
        }

        if(!is_dir($highFolder) && !mkdir($highFolder, 0777, true)) {
            throw new GalleryException("could not create destination-folder <$highFolder>", GalleryException::UPDATING_GALLERY_FAILED);
        }

        $chunk = explode(';', $_POST['file']);
        $type = explode(':', $chunk[0]);
        $type = $type[1];
        $data = explode(',', $chunk[1]);

        switch($type) {
            case 'image/pjpeg':
            case 'image/jpeg':
            case 'image/jpg':
                $type = 'jpg';
                break;

            case 'image/png':
            case 'image/x-png':
                $type = 'png';
                break;

            default:
                throw new GalleryException("invalid image type <$type> given!", GalleryException::INVALID_IMAGE);
        }

        $cnt = count(glob($highFolder . '*'));

        do {
            if($cnt >= 99999) {
                throw new GalleryException("more then <$cnt> images are not allowed", GalleryException::UPDATING_GALLERY_FAILED);
            }

            $filename = str_repeat('0', 6 - mb_strlen((string)$cnt)) . ++$cnt . '.' . $type;
            if(!is_file($highFolder . $filename)) {
                break;
            }
        } while(true);

        if(!file_put_contents($highFolder . $filename, base64_decode($data[1]))) {
            throw new GalleryException("could not store file <$filename> in path <$highFolder>", GalleryException::UPDATING_GALLERY_FAILED);
        }

        $this->generateThumb($highFolder . $filename, $thumbFolder . $filename, $previewSize);
        return $filename;
    }

    protected function generateThumb(string $srcFile, string $destFile, int $desHeight) : void {
        if(!is_file($srcFile)) {
            throw new GalleryException("src-file <$srcFile> does not exists", GalleryException::INVALID_IMAGE);
        }

        list($srcWidth, $srcHeight, $srcTyp) = getimagesize($srcFile);

        $desWidth = $srcWidth / $srcHeight * $desHeight;
        $new = imagecreatetruecolor($desWidth, $desHeight);

        switch($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($srcFile);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagejpeg($new, $destFile, 100);
                break;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($srcFile);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagepng($new, $destFile);
                break;
        }
        imagedestroy($old);
        imagedestroy($new);
    }

    protected function getImageFile(string $name, bool $preview) : string {
        if($preview) {
            return DIRECTORY_SEPARATOR . $this->getGalleryPath() . 'thumb' . DIRECTORY_SEPARATOR . $name;
        }
        return DIRECTORY_SEPARATOR . $this->getGalleryPath() . 'high' . DIRECTORY_SEPARATOR . $name;
    }

    protected function getGalleryPath() {
        return 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR;
    }

}
