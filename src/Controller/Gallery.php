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

namespace SFW2\Gallery\Controller;

use SFW2\Routing\AbstractController;
use SFW2\Routing\Result\Content;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;

use SFW2\Core\Database;
use SFW2\Core\Config;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Gallery\GalleryException;

class Gallery extends AbstractController {

    use DateTimeHelperTrait;
    use ImageHelperTrait;
    use EMailHelperTrait;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var User
     */
    protected $user;

    protected $title;

    const SUMMERIES_PER_PAGE = 3;
    const PREVIEW_FILE = 'preview.png';
    const DIMENSIONS = 130;

    public function __construct(int $pathId, Database $database, Config $config, User $user, string $title = null) {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->config = $config;
        $this->title = $title;
    }

    public function index($all = false) {
        unset($all);
        $content = new Content('SFW2\\Gallery\\Summary');

        $content->assign('caption',          $this->title ?? 'Gallerieübersicht');
        $content->assign('title',            $this->title);
        $content->assign('modificationDate', $this->getLastModificatonDate());
        $content->assign('webmaster',        (string)(new EMail($this->config->getVal('project', 'eMailWebMaster'))));

        $content->appendJSFile('Gallery.handlebars.js');
        return $content;
    }

    protected function getLastModificatonDate() {
        $stmt =
            "SELECT `imagegalleries`.`CreationDate` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`PathId` = '%s' " .
            "ORDER BY `imagegalleries`.`CreationDate`";

        return $this->database->selectSingle($stmt, [$this->pathId]);
    }

    public function read($all = false) {
        unset($all);
        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : self::SUMMERIES_PER_PAGE;

        $stmt =
            "SELECT `imagegalleries`.`Id`, `imagegalleries`.`Title`, `imagegalleries`.`Description`, `imagegalleries`.`CreationDate`, " .
            "`user`.`Email`, CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator`, " .
            "IF(`imagegalleries`.`UserId` = '%s', '1', '0') AS `OwnEntry` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`PathId` = '%s' " .
            "ORDER BY `imagegalleries`.`Id` DESC " .
            "LIMIT %s, %s " ;

        $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);
        $cnt = $this->database->selectCount('{TABLE_PREFIX}_imagegalleries', "WHERE `PathId` = '%s'", [$this->pathId]);

        $content = new Content('Gallery');
        $entries = [];

        foreach($rows as $row) {
            $cd = $this->getDate($row['CreationDate']);
            $entry = [];
            $entry['id'               ] = $row['Id'];
            $entry['date'             ] = $cd;
            $entry['title'            ] = $row['Title'];
            $entry['link'             ] = '?do=showGallery&id=' . $row['Id'];
            $entry['description'      ] = $row['Description'];
            $entry['ownEntry'         ] = (bool)$row['OwnEntry'];
            $entry['previewImage'     ] = $this->getPreviewImage($row['Id']);
            $entry['creator'          ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }

        $content->assign('offset', $start + $count);
        $content->assign('hasNext', $start + $count < $cnt);
        $content->assign('entries', $entries);
        return $content;
    }

    public function showGallery() {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if($id == false) {
            throw new GalleryException('no gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }
        $page = (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

        $stmt =
            "SELECT `imagegalleries`.`Title`, `imagegalleries`.`CreationDate`, `imagegalleries`.`Description`,  " .
            "`user`.`Email`,  CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`Id` = '%s' ";

        $row = $this->database->selectRow($stmt, [$id]);

        if(empty($row)) {
            throw new GalleryException("no gallery found for id <$id>!", GalleryException::NO_GALLERY_FETCHED);
        }

        $path = $this->getGalleryPath($id);
        if(!is_dir($path . '/thumb/')) {
            throw new GalleryException("path <$path> is invalid", GalleryException::INVALID_PATH);
        }

        $dir = dir($path . '/thumb/');
        $pics = [];

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
        $cd = $this->getDate($row['CreationDate']);
        $content = new Content('SFW2\\Gallery\\Gallery');
        $content->assign('caption',           $row['Title']);
        $content->assign('id',                (int)$id);
        $content->assign('mailaddr',          (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")"));
        $content->assign('creationDate',      $cd);
        $content->assign('description',       $row['Description']);
        $content->assign('page',              (int)$page);
        $content->assign('pics',              $pics);
        $content->assign('editable',          true);
        $content->assign('previewImage',      $this->getPreviewImage($id));
        $content->assign('maxFileUploads',    ini_get('max_file_uploads'));
        $content->appendCSSFile('lightbox.min.css');
        $content->appendJSFile('lightbox.min.js');
        return $content;
    }

    public function create() {
        $content = new Content('Gallery');

        $rulset = new Ruleset();
        $rulset->addNewRules('caption', new IsNotEmpty());
        $rulset->addNewRules('description', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
            return $content;
        }

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_imagegalleries` " .
            "SET `PathId` = '%s', `UserId` = '%s', `CreationDate` = NOW(), `Title` = '%s', `Description` = '%s' ";

        $id = $this->database->insert(
            $stmt,
            [
                $this->pathId,
                $this->user->getUserId(),
                $values['caption']['value'],
                $values['description']['value'],
            ]
        );

        $folder = $this->getGalleryPath($id);

        if(!mkdir($folder . '/thumb', 0777, true) || !mkdir($folder . '/high')) {
            throw new GalleryException("could not create gallery-path <$folder>", GalleryException::COULD_NOT_CREATE_GALLERY_PATH);
        }

        $cd = $this->getShortDate();
        $content = new Content('Gallery');
        $content->assign('id',           ['value' => $id]);
        $content->assign('date',         ['value' => $cd]);
        $content->assign('title',        ['value' => $values['caption']['value']]);
        $content->assign('description',  ['value' => $values['description']['value']]);
        $content->assign('link',         ['value' => '?do=showGallery&id=' . $id]);
        $content->assign('ownEntry',     ['value' => true]);
        $content->assign('previewImage', ['value' => $this->getPreviewImage()]);
        $content->assign('creator',  ['value' => $this->getEMailByUser($this->user, 'Galerie ' . $values['caption']['value'] . ' (' . $cd . ")")]);

        $content->dataWereModified();
        return $content;
    }

    public function delete($all = false) {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        if($id == false) {
            throw new GalleryException('no gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        $tokens = explode('__', $id);
        if(count($tokens) == 1) {
            $this->deletGallery($tokens[0], $all);
        } else {
            $this->deleteImage(array_shift($tokens), implode('__', $tokens), $all);
        }
        return new Content();
    }

    public function changePreview() {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        $p = strpos($id, '__');
        if($p === false) {
            throw new GalleryException("invalid id <$id> given!", GalleryException::NO_GALLERY_FETCHED);
        }

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $path = $this->getGalleryPath($galleryId);
        $this->generatePreview($file, self::DIMENSIONS, $path . '/high/', $path);
        return new Content();
    }

    protected function getGalleryPath($galleryId) {
        return 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR . $galleryId . DIRECTORY_SEPARATOR;
    }

    protected function getPreviewImage($id = 0) {
        if($id == 0) {
            return "/img/layout/empty.png";
        }
        $preview = $this->getGalleryPath($id) . self::PREVIEW_FILE;

        if(!is_file($preview)) {
            return "/img/layout/empty.png";
        }
        return '/' . $preview;
    }

    protected function deletGallery($galleryId, bool $all = false) {
        $stmt = "DELETE FROM `{TABLE_PREFIX}_imagegalleries` WHERE `Id` = '%s' AND `PathId` = '%s'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        if(!$this->database->delete($stmt, [$galleryId, $this->pathId])) {
            throw new ResolverException("no entry found", ResolverException::NO_PERMISSION);
        }

        $path = $this->getGalleryPath($galleryId);
        if(!is_dir($path . '/thumb/')) {
            throw new GalleryException("path <$path> is invalid", GalleryException::INVALID_PATH);
        }

        $dir = dir($path . '/thumb/');

        while(false !== ($entry = $dir->read())) {
            if($entry == '.' || $entry == '..') {
                continue;
            }

            unlink($path . '/thumb/' . $entry);
            unlink($path . '/high/' . $entry);
        }
        $dir->close();
        if(is_file($path . '/' . self::PREVIEW_FILE)) {
            unlink($path . '/' . self::PREVIEW_FILE);
        }

        rmdir($path . '/thumb/');
        rmdir($path . '/high/');
        rmdir($path);
    }

    protected function deleteImage($galleryId, $fileName, bool $all = false) {
        $stmt =
            "SELECT `imagegalleries`.`Id` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = '%s' AND `imagegalleries`.`PathId` = '%s' ";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $row = $this->database->selectRow($stmt, [$galleryId, $this->pathId]);

        if(empty($row)) {
            throw new GalleryException('no valid gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        $path = $this->getGalleryPath($galleryId);

        unlink($path . 'thumb/' . $fileName);
        unlink($path . 'high/' . $fileName);
    }

    public function addImage() {
        $galleryId = filter_input(INPUT_POST, 'gallery', FILTER_SANITIZE_STRING);

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
        $path = $this->getGalleryPath($galleryId);
        $cnt = count(glob($path  . '/high/*'));
        if($cnt >= 999) {
            throw new GalleryException("more then <$cnt> images are not allowed");
        }

        $filename = str_repeat('0', 4 - mb_strlen((string)$cnt)) . ++$cnt . '.' . $type;

        if(!file_put_contents($path . '/high/' . $filename, base64_decode($data[1]))) {
            throw new GalleryException("could not store file <$filename> in path <$path/high/>");
        }

        $this->generateThumb($filename, self::DIMENSIONS, $path . '/high/', $path . '/thumb/');

        if(!is_file($path . self::PREVIEW_FILE)) {
            $this->generatePreview($filename, self::DIMENSIONS, $path . '/high/', $path);
        }

        #$this->ctrl->updateModificationDate();
        return new Content();
    }

    private function generateThumb($file, $desHeight, $src, $des) {
        $srcFile = $src . '/' . $file;
        if(!is_file($srcFile)) {
            return false;
        }

        list($srcWidth, $srcHeight, $srcTyp) = getimagesize($srcFile);

        $desWidth = $srcWidth / $srcHeight * $desHeight;
        $new = imagecreatetruecolor($desWidth, $desHeight);

        switch($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($srcFile);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagejpeg($new, $des . '/' . $file, 100);
                break;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($srcFile);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagepng($new, $des . '/' . $file);
                break;
        }
        imagedestroy($old);
        imagedestroy($new);
        return true;
    }

    public function generatePreview($file, $dimensions, $src, $des) {
        $srcFile = $src . '/' . $file;

        if(!is_file($srcFile)) {
            throw new GalleryException("file <$srcFile> is not a valid image!", GalleryException::INVALID_IMAGE);
        }

        list($oldW, $oldH, $srcTyp) = getimagesize($srcFile);


        if($oldH > $oldW) {
            $newW = $dimensions;
            $newH = ($oldH / $oldW) * $dimensions;
            $limD = $oldW;
        } else {
            $newH = $dimensions;
            $newW = ($oldW / $oldH) * $dimensions;
            $limD = $oldH;
        }

        $new = imagecreatetruecolor($dimensions, $dimensions);

        switch ($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($srcFile);
                break;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($srcFile);
                break;
        }

        imagecopyresampled($new, $old, 0, 0, ($newW - $dimensions) / 2, ($newH - $dimensions) / 2, $dimensions, $dimensions, $limD, $limD);
        imagepng($new, $des . '/' . self::PREVIEW_FILE);
        imagedestroy($new);
        imagedestroy($old);
    }
}

