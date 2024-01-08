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

use Exception;

trait ImageHelperTrait {

    protected function getImageFileName($firstname, $lastname, $path = '/img/layout/'): string
    {
        $file = $this->getSimplifiedName($firstname) . '_' . $this->getSimplifiedName($lastname) . '.png';

        $path = trim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if(file_exists($path . $file)) {
            return DIRECTORY_SEPARATOR . $path . $file;
        }
        return DIRECTORY_SEPARATOR . $path . 'unknown.png';
    }

    protected function getSimplifiedName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(' ', '_', $name);
        $name = iconv("UTF-8", "ASCII//TRANSLIT", $name); // converts umlauts see http://www.interessante-zeiten.de/webdesign/ae-zu-ae-umlaute-mit-php-umwandeln-312.html for details
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name);
        return mb_strtolower($name);
    }

    protected function getImageFolder(): string
    {
        return 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR;
    }

    /**
     * @throws Exception
     */
    protected function addFile(string $folder, int $previewSize): string
    {
        if(!isset($_POST['file'])) {
            throw new Exception("file not set");
        }

        $thumbFolder = $folder . DIRECTORY_SEPARATOR . 'thumb' . DIRECTORY_SEPARATOR;
        $highFolder = $folder . DIRECTORY_SEPARATOR . 'high' . DIRECTORY_SEPARATOR;

        if(!is_dir($thumbFolder) && !mkdir($thumbFolder, 0777, true)) {
            throw new Exception("could not create destination-folder <$thumbFolder>");
        }

        if(!is_dir($highFolder) && !mkdir($highFolder, 0777, true)) {
            throw new Exception("could not create destination-folder <$highFolder>");
        }

        $chunk = explode(';', $_POST['file']);
        $type = explode(':', $chunk[0]);
        $type = $type[1];
        $data = explode(',', $chunk[1]);

        $type = match ($type) {
            'image/pjpeg', 'image/jpeg', 'image/jpg' => 'jpg',
            'image/png', 'image/x-png' => 'png',
            default => throw new Exception("invalid image type <$type> given!"),
        };

        $cnt = count(glob($highFolder . '*'));

        do {
            if($cnt >= 99999) {
                throw new Exception("more then <$cnt> images are not allowed");
            }

            $filename = str_repeat('0', 6 - mb_strlen((string)$cnt)) . ++$cnt . '.' . $type;
            if(!is_file($highFolder . $filename)) {
                break;
            }
        } while(true);

        if(!file_put_contents($highFolder . $filename, base64_decode($data[1]))) {
            throw new Exception("could not store file <$filename> in path <$highFolder>");
        }

        $this->generateThumb($highFolder . $filename, $thumbFolder . $filename, $previewSize);
        return $filename;
    }

    /**
     * @throws Exception
     */
    protected function uploadImage(string $folder) : string
    {
        if(!isset($_FILES['upload'])) {
            throw new Exception("<upload> is not set in FILES");
        }

        if(!is_dir($folder) && !mkdir($folder, 0777, true)) {
            throw new Exception("could not create destination-folder <$folder>");
        }

        $file = $_FILES['upload'];

        if($file['error'] != UPLOAD_ERR_OK) {
            // TODO spezielle Fehlermeldung (siehe https://www.php.net/manual/de/features.file-upload.errors.php)
            throw new Exception("error <{$file['error']}> is set");
        }

        $type = match ($file['type']) {
            'image/pjpeg', 'image/jpeg', 'image/jpg' => 'jpg',
            'image/png', 'image/x-png' => 'png',
            default => throw new Exception("invalid image type <{$file['type']}> given"),
        };

        $cnt = count(glob($folder . '*'));

        do {
            if($cnt >= 99999) {
                throw new Exception("more then <$cnt> images are not allowed");
            }

            $filename = str_repeat('0', 6 - mb_strlen((string)$cnt)) . ++$cnt . '.' . $type;
            if(!is_file($folder . $filename)) {
                break;
            }
        } while(true);

        move_uploaded_file($file['tmp_name'], $folder . $filename);

        return "$folder$filename";
    }

    /**
     * @throws Exception
     */
    protected function generateThumb(string $srcFile, string $destFile, int $desHeight) : void {
        if(!is_file($srcFile)) {
            throw new Exception("src-file <$srcFile> does not exists");
        }

        list($srcWidth, $srcHeight, $srcTyp) = getimagesize($srcFile);

        if ($desHeight > $srcHeight) {
            if(!copy($srcFile, $destFile)) {
                throw new Exception("unable to copy file <$srcFile> to <$destFile>");
            }
            return;
        }

        $desWidth = (int)($srcWidth / $srcHeight * $desHeight);
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

            default:
                throw new Exception("invalid image type <$srcTyp> given");
        }
        imagedestroy($old);
        imagedestroy($new);
    }

    protected function getImageFile(string $name, int $pathId, bool $preview): string
    {
        if($preview) {
            return DIRECTORY_SEPARATOR . $this->getGalleryPath($pathId) . 'thumb' . DIRECTORY_SEPARATOR . $name;
        }
        return DIRECTORY_SEPARATOR . $this->getGalleryPath($pathId) . 'high' . DIRECTORY_SEPARATOR . $name;
    }

    protected function getGalleryPath(int $pathId, int $galleryId = null): string {
        if($galleryId === null) {
            return 'img' . DIRECTORY_SEPARATOR . $pathId . DIRECTORY_SEPARATOR;
        }
        return 'img' . DIRECTORY_SEPARATOR . $pathId . DIRECTORY_SEPARATOR . $galleryId . DIRECTORY_SEPARATOR;
    }

    /**
     * @throws Exception
     */
    protected function rotate(string $file): void
    {
        [, , $srcTyp] = getimagesize($file);
        $old = match ($srcTyp) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file),
            IMAGETYPE_PNG => imagecreatefrompng($file),
            default => throw new Exception("Invalid Image type <$srcTyp> given"),
        };

        $new = imagerotate($old, 90, 0);

        imagepng($new, $file);
        imagedestroy($new);
        imagedestroy($old);
    }

    protected function getImageList(string $path): array{
        $dir = dir($path . '/thumb/');
        $pics = [];

        /** @noinspection PhpAssignmentInConditionInspection */
        while(false !== ($entry = $dir->read())) {
            if($entry == '.' || $entry == '..') {
                continue;
            }
            $fi = pathinfo($path . '/thumb/' . $entry);
            if(strtolower($fi['extension']) != 'jpg' && strtolower($fi['extension']) != 'png') {
                continue;
            }
            $pic = [];
            $pic['lnk'] = '/' . $path . '/high/' . $entry;
            $pic['ttp'] = $entry;
            $pic['src'] = '/' . $path . '/thumb/' . $entry;
            $pics[] = $pic;
        }

        $dir->close();
        rsort($pics);
        return $pics;
    }

    protected function deleteAllUnneededImages(string $folder, string $content, bool $others = true) : void {
        $matches = [];
        preg_match_all('/<img src=\"(.*)\">/imU', $content, $matches);
        $matches = $matches[1];

        $matches = array_flip($matches);

        $files = glob($folder . '*');
        foreach($files as $file) {
            if(!isset($matches["/$file"]) && $others) {
                unlink($file);
            }
            if(isset($matches["/$file"]) && !$others) {
                unlink($file);
            }
        }
    }

    /**
     * @throws Exception
     */
    protected function convertPDFToJPG(string $pdfFile, string $imgFile) : void {
        $pdfFile = escapeshellarg($pdfFile);
        $jpg_file = escapeshellarg($imgFile);

        $result = 0;
        $output = [];
        exec("convert -flatten -density 300 {$pdfFile}[0] $jpg_file", $output, $result);
        if($result != 0) {
            throw new Exception("could not convert file <$pdfFile>");
        }
    }
}
