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

    const SUMMERIES_PER_PAGE = 100;

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

        #$content->appendJSFile('crud.js');
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

        $count = $count ? $count : 5;

        $stmt =
            "SELECT `imagegalleries`.`Id`, `imagegalleries`.`Title`, `imagegalleries`.`Description`, `imagegalleries`.`CreationDate`, " .
            "`user`.`Email`, `imagegalleries`.`PreviewImage`, " .
            "CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator`, " .
            "IF(`imagegalleries`.`UserId` = '%s', '1', '0') AS `OwnEntry` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`PathId` = '%s' " .
            "ORDER BY `imagegalleries`.`Id` DESC ";

        $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);

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
            $entry['previewImage'     ] = $this->getPreviewImage($row['Id'], $row['PreviewImage']);
            $entry['creator'          ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }
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
            "SELECT `imagegalleries`.`Title`, `imagegalleries`.`CreationDate`, `imagegalleries`.`Description`, `imagegalleries`.`PreviewImage`, " .
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
            $pic['pre'] = ($row['PreviewImage'] == $entry);
            $pics[] = $pic;
        }

        $dir->close();
        rsort($pics);

        $cd = $this->getDate($row['CreationDate']);
        $content = new Content('SFW2\\Gallery\\Gallery');
        $content->assign('caption',           $row['Title']);
        $content->assign('id',                (int)$id);
        $content->assign('mailaddr', (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")"));
        $content->assign('creationDate',      $cd);
        $content->assign('description',       $row['Description']);
        $content->assign('page',              (int)$page);
        $content->assign('pics',              $pics);
        $content->assign('editable',          true);
        $content->assign('maxFileUploads',    ini_get('max_file_uploads'));
        $content->appendCSSFile('lightbox.min.css');
        $content->appendJSFile('lightbox.min.js');
        return $content;
    }

    public function create() {
        $content = new Content('Blog');

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
            "SET " .
            "`PreviewImage` = '', " .
            "`PathId` = '%s', " .
            "`UserId` = '%s', " .
            "`CreationDate` = NOW(), " .
            "`Title` = '%s', " .
            "`Description` = '%s' ";

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
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
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

    public function changePrevImg($galleryId, $fileName) {
#        if(!$this->ctrl->hasCreatePermission()) {
#            return false;
#        }

        $stmt =
            "SELECT `imagegalleries`.`PreviewImage` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = '%s' AND `imagegalleries`.`PathId` = '%s' ";

        $row = $this->database->selectRow($stmt, [$galleryId, $this->pathId]);

        if(empty($row)) {
            throw new GalleryException('no valid gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        if($fileName == $row['PreviewImage']) {
            throw new GalleryException('unable to delete preview-img!', GalleryException::COULD_NOT_DELETE_PREVIEW_IMAGE);
        }

        // Prüfen, ob Bild im Ordner vorhanden...
        $file = $this->getGalleryPath($galleryId) . '/thumb/' . $fileName;

        if(!is_file($file)) {
            throw new GalleryException("file <$file> is not a valid image!", GalleryException::INVALID_IMAGE);
        }

        $stmt = "UPDATE `{TABLE_PREFIX}_imagegalleries` SET `PreviewImage` = '%s' WHERE `Id` = '%s'";

        if($this->database->update($stmt, [$fileName, $galleryId]) != 1) {
            throw new GalleryException('updating imagegalleries failed!', GalleryException::UPDATING_GALLERY_FAILED);
        }
    }

    protected function getGalleryPath($galleryId) {
        return 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR . $galleryId . DIRECTORY_SEPARATOR;
    }

    protected function getPreviewImage($id = 0, $image = '') {
        if ($image == '') {
            return "/img/layout/empty.png";
        }
        return '/' . $this->getGalleryPath($id) . 'thumb/' . $image;
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
        rmdir($path . '/thumb/');
        rmdir($path . '/high/');
        rmdir($path);
    }

    protected function deleteImage($galleryId, $fileName, bool $all = false) {
        $stmt =
            "SELECT `imagegalleries`.`PreviewImage` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = '%s' AND `imagegalleries`.`PathId` = '%s' ";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $row = $this->database->selectRow($stmt, [$galleryId, $this->pathId]);

        if(empty($row)) {
            throw new GalleryException('no valid gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        if($fileName == $row['PreviewImage']) {
            throw new GalleryException('unable to delete preview-img!', GalleryException::COULD_NOT_DELETE_PREVIEW_IMAGE);
        }

        $path = $this->getGalleryPath($galleryId);

        unlink($path . 'thumb/' . $fileName);
        unlink($path . 'regular/' . $fileName);
        unlink($path . 'high/' . $fileName);
    }















    public function addImage() {

        $chunk = explode(';', $this->dto->getData('file'));
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
                return [
                    'error' => true,
                    'msg'   => 'Es wurde eine ungültige Datei übermittelt.' .
                    $chunk[0] . print_r($_REQUEST, true) . print_r($_FILES, true)
                ];
        }

        $cnt = count(glob($rv["Path"] . '/high/*'));
        if($cnt >= 999) {
            throw new GalleryException("more then <$cnt> images are not allowed");
        }

        $filename = str_repeat('0', 4 - mb_strlen('' . $cnt)) . ++$cnt . '.' . $type;

        if(!file_put_contents($rv["Path"] . '/high/' . $filename, base64_decode($data[1]))) {
            throw new GalleryException("could not store file <$filename> in path <" . $rv["Path"] . '/high/>');
        }

        $this->generateThumb($filename, 170, $rv["Path"] . '/high/', $rv["Path"] . '/thumb/');
        $this->generateThumb($filename, 335, $rv["Path"] . '/high/', $rv["Path"] . '/regular/');

        if($rv['PreviewImage'] == '') {
            $this->changePrevImg($galid, $filename);
        }

        $this->ctrl->updateModificationDate();

        return array(
            'error' => false,
            'msg' => 'Alles chick.'
        );
    }

    private function generateThumb($file, $size, $src, $des) {
        if(!is_file($src . '/' . $file)) {
            return false;
        }

        list($srcWidth, $srcHeight, $srcTyp) = getimagesize($src . '/' . $file);

        if($srcWidth >= $srcHeight) {
            $desWidth = $size;
            $desHeight = $srcHeight / $srcWidth * $size;
        } else {
            $desHeight = $size;
            $desWidth = $srcWidth / $srcHeight * $size;
        }

        switch($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($src . '/' . $file);
                $new = imagecreatetruecolor($desWidth, $desHeight);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagejpeg($new, $des . '/' . $file, 100);
                imagedestroy($old);
                imagedestroy($new);
                return true;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($src . '/' . $file);
                $new = imagecreatetruecolor($desWidth, $desHeight);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagepng($new, $des . '/' . $file);
                imagedestroy($old);
                imagedestroy($new);
                return true;
        }
    }
}

